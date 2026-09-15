# Operations

Procedures an operator runs against a deployed environment. Today there is one:
establishing the first administrator. Everything else here exists to explain why
that procedure looks the way it does, and why the alternatives were refused.

**All documentation is English only.** No real address, password, secret or
personal datum belongs in this file or any other tracked file; every example
below uses the reserved `example.test` domain.

This document covers procedures **inside the application**. The environment it
runs in — deploying a release, PostgreSQL, the queue worker, transactional mail,
nginx and TLS — is [`../production/README.md`](../production/README.md). In
production, every command below is invoked through the explicit PHP 8.4 runtime;
see [`../production/README.md`](../production/README.md) §3.

---

## 1. The problem this solves

`role` is `player` for every account the application creates. The column is
`$guarded`, it is constrained in PostgreSQL to the two values the enum declares,
and no HTTP route writes it — not registration, not profile, not the admin
surface itself. That is deliberate: there is no self-service path to privilege,
so there is also no path for an attacker.

The consequence is that a freshly deployed environment has **no administrator at
all**, and no way to obtain one through the product. Something outside the
request cycle has to make the first grant.

That something is:

```bash
php artisan purrenade:admin:promote <email>
```

## 2. What the command will and will not do

It grants the administrator role to an account that **already exists and has
already verified its address**. That is the whole of its authority.

It will not create an account. It will not set, reset or read a password. It
will not mark an address verified. It will not generate, confirm or remove a
second factor, and it will not issue recovery codes. It will not grant
progression, characters, achievements or score, and it touches no game data.

Those are not omissions to be filled in later. An operator who can mint an
administrator with a password of their choosing *is* an administrator, silently
and permanently; the account's owner would have no way to know. Here the owner
registers with their own address, chooses their own password, and proves control
of the mailbox — exactly as any player does — and the operator changes one column
afterwards.

### It signs the account out

A successful promotion revokes every session the account holds and discards any
two-factor challenge in flight. This is the point of the command rather than a
side effect.

Authorization is read from the row on each request, so the role change is
immediate. For a player with no second factor that is harmless: the admin gate
refuses at the enrolment check either way. But a player who *already* had
confirmed two-factor and was holding a session minted by the challenge endpoint
satisfies every remaining condition the instant the role flips — a session that
proved possession as a player would become an administrative session with no
further act by anybody. Revoking means the new privilege begins at a sign-in
somebody performed knowing what the account had become. A pending challenge is
the same handle one step removed, so it goes too.

### It is idempotent

Run against an account that is already an administrator, it reports that and
exits successfully without writing, revoking or logging. Re-running after a
half-finished deploy cannot sign a working administrator out.

### It records what it did

A successful grant writes `auth.admin.role_granted` to the security channel
through the same `AuthLog` vocabulary every other authorization event uses, with
the user id, the resulting role, and the number of sessions revoked. The address
is **not** in the log line: `observability.md` §3.1 forbids the identifier, and
`user_id` already resolves it for anyone entitled to resolve it.

## 3. Bootstrap runbook

Preconditions: the API is deployed and migrated, the frontend is deployed and
reachable, and **transactional mail actually delivers**. Step 3 is a real email
to a real mailbox; with `MAIL_MAILER=log` the code never arrives and the account
can never be verified, which means it can never be promoted.

1. **Confirm mail is configured for delivery.** `MAIL_MAILER` names a real SMTP
   transport, `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` /
   `MAIL_SCHEME` match the provider, and `MAIL_FROM_ADDRESS` is a domain the
   provider is authorised to send for. `FRONTEND_URL` must be the public
   frontend origin, because the password-reset link is built from it and has to
   open a page the frontend serves.

2. **Register through the public interface**, as the person who will hold the
   account — not as the operator on their behalf. Own address, own password,
   chosen in the browser. The operator never learns it and never needs it.

3. **Verify the address** with the six-digit code the application emails. If no
   mail arrives, fix step 1; do not work around it.

4. **Promote the account**, on the server, as the user the application runs as:

   ```bash
   php artisan purrenade:admin:promote person@example.test
   ```

   The command asks for confirmation and names the address before writing. Add
   `--force` to skip the prompt in controlled automation — and only there: the
   prompt is what makes a mistyped address a harmless failure rather than a
   privileged one.

5. **Sign in again.** The previous sessions were revoked by step 4, so this is
   required rather than optional.

6. **Enrol two-factor authentication** under account security. Two-factor is
   mandatory for administrators and is enforced on the server, so this is not
   hardening to schedule for later — until it is done, every administrative
   endpoint refuses the account. The command warns about exactly this when it
   promotes an account that has not enrolled.

7. **Sign in once more, completing the challenge.** Only a session that actually
   passed a challenge carries the ability the admin gate requires; no endpoint
   upgrades a session that is already open.

8. **Verify access.** The administrative overview should load. If it does not,
   §5 explains which of the four conditions is missing — the refusal always says.

Nothing in this procedure involves the operator handling the account holder's
password, and nothing involves editing the database by hand.

## 4. How everyone else gets an account

There is no invitation system, no pre-created account, no shared login and no
seeded user. Every person — friends and early testers included — reaches the
product the same way:

- they register themselves, with **their own address** and a password they choose;
- they verify that address by email;
- they are a `player`, and they stay a `player`.

Being a player is the normal, complete state of the product. The whole game is
playable from it; administration is an operational surface, not a reward or a
tier.

**Playable characters are not authorization.** Ayşenur, Büşo, Ogito and Sero are
game content — selectable characters with unlock conditions in the progression
rules. Choosing one, unlocking one, or being named after one grants no
capability of any kind, and no part of the server reads the character to decide
what a request may do. Roles live in `role`; characters live in progression;
the two never meet.

Promote a second administrator only when the operational need is real, through
the same command and the same sequence. Two roles exist and only two; there is no
moderator tier to hand out instead.

## 5. When the admin surface refuses

Administrative access requires four conditions simultaneously, checked on the
server on every request: the account is an administrator, its address is
verified, a second factor is **confirmed**, and **this session** passed a
two-factor challenge. Every refusal names which one failed, so the response
itself is the diagnostic.

There is one sign-in page for everybody. There is no separate administrative
login, no alternate host, no elevated credential and no bypass — an
administrator is a player whose row says `admin` and who therefore has to clear
two more gates. The frontend route guard exists only to choose a helpful
destination; removing it would change nothing about what the API returns.

## 6. Procedures that are deliberately absent

- **Editing `role` in SQL, or through `tinker`.** It works, which is the
  problem: a privilege change with no precondition, no audit line and no
  consequence for the sessions the account already holds. It also cannot check
  verification, so it happily produces an administrator that no gate will let in.
- **A production seeder.** `database/seeders/DatabaseSeeder.php` is local and
  test convenience only, says so in its own docblock, and creates an ordinary
  player. It is not part of deployment and must never be run against production
  data.
- **A command that creates the account, or sets a password.** See §2.
- **A hardcoded or environment-configured administrator.** A credential in
  configuration is a credential in backups, in deployment logs, and in whatever
  read the configuration last.
