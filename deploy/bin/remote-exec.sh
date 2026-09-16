#!/usr/bin/env bash
#
# The single audited path from a CI runner to the production host.
#
#   remote-exec.sh --host H --user U [--port P] --key K --script FILE -- ARG...
#   remote-exec.sh --host H --user U [--port P] --key K --copy FILE... --dest DIR
#
# Every ssh and scp invocation in the deployment workflow goes through here, so
# the transport has exactly one implementation to audit rather than one per step.
#
# ---------------------------------------------------------------------------
# The problem this solves
# ---------------------------------------------------------------------------
#
# `ssh host "cmd '$VALUE'"` is not safe, and putting $VALUE in an environment
# variable first does not make it safe. ssh has no argv: whatever is passed is
# joined into ONE string and handed to a shell on the far side. A value holding
# a single quote ends the quoting and the rest is executable source. An earlier
# revision of this workflow did exactly that with repository variables, and a
# value of  /var/www/x'; id; '  was proven to execute `id` on the host.
#
# So no caller value is ever part of remote shell source. Each argument is
# base64-encoded here and decoded there. The base64 alphabet is
# [A-Za-z0-9+/=] — no quote, no `$`, no `;`, no newline, no leading `-` — so the
# text this script interpolates cannot change how the remote shell parses it,
# whatever the argument contained.
#
# Host, user and port are a different case: they are ssh's own command-line
# arguments, not remote shell source. A user of `-oProxyCommand=...` would be
# read by ssh as an option and execute on the RUNNER, where the private key is.
# They are therefore validated against strict grammars AND passed after `--`.
# Neither defence is relied on alone: OpenSSH 9.6 was verified to honour `--`
# and to reject a malformed username, but older clients are not assumed to.
#
# PURRENADE_SSH_BIN / PURRENADE_SCP_BIN exist so the test suite can substitute
# an argv recorder. That is the only way to prove what this script really passes
# without a server to connect to.

set -euo pipefail

readonly USER_PATTERN='^[a-z_][a-z0-9_-]{0,31}$'
readonly HOST_PATTERN='^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'
readonly PORT_PATTERN='^[0-9]{1,5}$'

die() { printf '\n  ERROR: %s\n\n' "$1" >&2; exit 1; }

host=""; user=""; port="22"; key=""; script=""; dest=""
mode=""
declare -a copy_files=()
declare -a remote_args=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --host)   host="${2:-}"; shift 2 ;;
        --user)   user="${2:-}"; shift 2 ;;
        --port)   port="${2:-22}"; shift 2 ;;
        --key)    key="${2:-}"; shift 2 ;;
        --script) mode="script"; script="${2:-}"; shift 2 ;;
        --dest)   dest="${2:-}"; shift 2 ;;
        --copy)
            mode="copy"; shift
            while [[ $# -gt 0 && "$1" != --* ]]; do copy_files+=("$1"); shift; done
            ;;
        --)
            shift
            while [[ $# -gt 0 ]]; do remote_args+=("$1"); shift; done
            ;;
        *) die "unknown argument: $1" ;;
    esac
done

# ---------------------------------------------------------------------------
# Validate the values that become ssh's own arguments
# ---------------------------------------------------------------------------
#
# A leading `-` cannot survive any of these patterns, which is the property that
# matters: it is what stops a value being read as an option.
[[ -n "$host" ]] || die "missing --host"
[[ -n "$user" ]] || die "missing --user"
[[ -n "$key" ]] || die "missing --key"

[[ "$user" =~ $USER_PATTERN ]] || die \
"unsafe SSH user: it must match ${USER_PATTERN}
  A value beginning with '-' would be read by ssh as a command-line option."
[[ "$host" =~ $HOST_PATTERN ]] || die \
"unsafe SSH host: it must match ${HOST_PATTERN}
  A value beginning with '-' would be read by ssh as a command-line option."
[[ "$port" =~ $PORT_PATTERN ]] || die "SSH port must be numeric, got: ${port}"
[[ "$port" -ge 1 && "$port" -le 65535 ]] || die "SSH port out of range 1-65535: ${port}"
[[ -f "$key" ]] || die "SSH key file not found: $key"

# ---------------------------------------------------------------------------
# Two independent option arrays
# ---------------------------------------------------------------------------
#
# ssh takes -p, scp takes -P. An earlier revision derived one from the other
# with ${ssh_opts[@]/-p/-P}, which rewrites EVERY element: an identity path
# containing "-p" — /home/runner-pool/... — silently became /home/runner-Pool.
# Two arrays cost two lines and cannot do that.
readonly -a COMMON_OPTS=(
    -o StrictHostKeyChecking=yes
    -o BatchMode=yes
    -o ConnectTimeout=15
    -o LogLevel=ERROR
)
declare -a ssh_opts=("${COMMON_OPTS[@]}" -i "$key" -p "$port")
declare -a scp_opts=("${COMMON_OPTS[@]}" -i "$key" -P "$port")

SSH_BIN="${PURRENADE_SSH_BIN:-ssh}"
SCP_BIN="${PURRENADE_SCP_BIN:-scp}"

encode() { printf '%s' "$1" | base64 -w0; }

case "$mode" in
    script)
        [[ -n "$script" ]] || die "missing --script"
        [[ -f "$script" ]] || die "script not found: $script"

        # Build the remote program. Only base64 text is interpolated; the
        # decoded values exist solely as shell *variables*, never as source.
        remote_program='set -euo pipefail'$'\n'
        local_index=0
        declare -a expansions=()
        for arg in ${remote_args+"${remote_args[@]}"}; do
            local_index=$((local_index + 1))
            encoded="$(encode "$arg")"
            # Defensive: prove the encoder produced only the safe alphabet
            # before it is spliced in. If this ever fails, the splice is unsafe.
            [[ "$encoded" =~ ^[A-Za-z0-9+/=]*$ ]] \
                || die "internal: base64 produced an unexpected character"
            remote_program+="__a${local_index}=\"\$(printf %s '${encoded}' | base64 -d)\""$'\n'
            expansions+=("\"\$__a${local_index}\"")
        done
        remote_program+="bash -s --"
        for e in ${expansions+"${expansions[@]}"}; do remote_program+=" $e"; done

        exec "$SSH_BIN" "${ssh_opts[@]}" -- "${user}@${host}" "$remote_program" < "$script"
        ;;

    copy)
        [[ ${#copy_files[@]} -gt 0 ]] || die "no files given to --copy"
        [[ -n "$dest" ]] || die "missing --dest"
        for f in "${copy_files[@]}"; do
            [[ -f "$f" ]] || die "file to copy not found: $f"
        done
        # The destination path is a remote-shell-expanded string for scp, so it
        # is constrained to a conservative grammar rather than trusted.
        [[ "$dest" =~ ^/[A-Za-z0-9._/-]*$ ]] || die \
"unsafe scp destination: it must be an absolute path matching ^/[A-Za-z0-9._/-]*$
  got: ${dest}"
        exec "$SCP_BIN" "${scp_opts[@]}" -- "${copy_files[@]}" "${user}@${host}:${dest}"
        ;;

    *) die "one of --script or --copy is required" ;;
esac
