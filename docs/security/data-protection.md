# Data Protection

**KVKK and GDPR both apply.** The primary audience is Turkish-speaking, so
**KVKK** is the governing regime, alongside GDPR for European users.

**Status: largely OPEN (SEC-3).** Nothing in the approved references addresses
this, and it cannot be bolted on at the end.

---

## 1. Personal data held — APPROVED inventory

| Data | Source | Sensitivity |
| --- | --- | --- |
| Email address | Registration | Identifier; login credential |
| Username | Registration | **Published on the leaderboard** |
| Password hash | Registration | Credential material |
| 2FA secret, recovery codes | Enrolment | Credential material |
| **Device label** | Session tracking | Device fingerprint-adjacent |
| **Approximate location** | Session tracking | Location data |
| Last-seen timestamps | Session tracking | Behavioural |
| Play history — runs, scores, timestamps | Gameplay | Behavioural |
| **Per-event gameplay telemetry** — obstacle passes, near misses, activations | Gameplay | **Behavioural.** New in M0.5; see §3A |
| Locale | Settings | Low, but inferential |

The two rows that deserve attention are **approximate location** and **device
label**. They appear on v0.3 board 20 as a helpful security feature, and they are
squarely personal data with a retention obligation. How location is derived is
**OPEN (AUTH-4)** and needs a deliberate answer.

---

## 2. Principles — APPROVED

| Principle | Applied here |
| --- | --- |
| **Data minimization** | Collect only what a feature needs. No analytics identifiers "for later". |
| **Purpose limitation** | Session location exists for security review, not for anything else |
| **Storage limitation** | Everything has a retention period — **OPEN** |
| **Integrity and confidentiality** | Encryption in transit everywhere; credential material encrypted or hashed at rest |
| **Accountability** | The audit log records admin access to personal data |

---

## 2A. Gameplay telemetry is personal data — APPROVED framing; **ANTI-5 resolved**, period still OPEN

The M0.5 achievement authority rule requires that achievement progression be **derived
server-side from accepted, validated telemetry** rather than adopted from client summary
counters. **Seven of the sixteen** proposed achievements have a `DERIVED_TELEMETRY`
verification source and depend on it.

That is the right integrity decision, and it has a privacy consequence that must be recorded
now rather than discovered at M14:

| Consequence | Detail |
| --- | --- |
| **Retained per-event data is behavioural personal data** | Linked to an identified account, describing how a person played, minute by minute |
| It falls under **the same retention obligation** as any other personal data | And under the still-OPEN policy in §4 |
| It is subject to **access/export** | A data-export request covers it |
| It is subject to **deletion/anonymization** | Whatever §3 eventually decides applies here too |
| It increases the run-submission **payload** and per-run **storage** | An operational cost, not only a legal one |

**ANTI-5 is resolved, and it resolved toward minimization.** ADR-0006 (accepted 2026-09-22)
chose the lowest-footprint option of the three that were on the table:

| Option | Privacy footprint | Chosen |
| --- | --- | --- |
| **Validate at submission, keep compact derived facts, retain no raw events** | **Lowest** | **Yes** |
| Retain raw events for a bounded window, then reduce | Medium; the window itself becomes a retention decision | No |
| Retain raw events indefinitely | Highest, and hardest to defend under KVKK | No |

Concretely: **no `run_events` table is created, and no raw per-event gameplay history is
retained.** What persists is the authoritative run record, compact authoritative derived
facts, the minimum validation metadata needed to explain a classification, and the approved
progression state.

**The footprint shrank further than the table suggests.** ADR-0006 also ships Layers 1 and 2
only, and those cannot *establish* a `DERIVED_TELEMETRY` fact — so rather than adopt a client
counter in breach of the authority rule, M9 persists and returns **none** of the four
telemetry-derived facts at all. That is tracked as **ANTI-6** and blocks M11. Until it
resolves, the behavioural-personal-data surface described above is **not created**.

**Any future raw-event retention is a separate privacy decision**, not something ANTI-6
resolving later implies. The burden of justification falls on **retaining**, not on discarding.

**What remains OPEN as SEC-5** is the retention period for the data that *is* kept — the run
records and their compact derived facts — together with the rest of §4.

## 3. Player rights — OPEN (SEC-3)

| Right | Status |
| --- | --- |
| **Access / export** | **OPEN.** No endpoint exists. Must cover profile, progression, runs and sessions. |
| **Deletion** | **OPEN.** Required by KVKK/GDPR **and** by both major app stores for any app supporting account creation — which matters given the approved Android/iOS requirement. |
| **Rectification** | Partly covered by profile update; username change is OPEN (PR-1) |
| **Objection / restriction** | **OPEN** — relates to leaderboard opt-out (LB-4) |
| **Consent records** | **OPEN.** Registration shows a terms line; what is actually consented to, and where that is recorded, is undefined. |

### The hard one: deletion versus the public leaderboard

| Question | PROPOSED direction |
| --- | --- |
| What happens to a deleted player's leaderboard entries? | **Identity removed or anonymized.** Retaining a named public record after deletion is difficult to defend under KVKK. |
| What happens to their runs? | Anonymized rather than destroyed, so aggregate integrity survives |
| Is anything retained? | Audit and abuse records may need to be, for a bounded period, with a stated legal basis |
| Immediate, or a grace period? | A grace period protects against mistakes and complicates "deleted means deleted" |

None of this is decided. It must be, before the leaderboard ships.

---

## 4. Retention — OPEN

| Data | PROPOSED direction |
| --- | --- |
| Runs | Long-lived; they are the leaderboard's basis |
| Paw ledger | Long-lived; it is the auditable basis of progression |
| Sessions | Short — revoked and expired sessions have no reason to persist |
| Verification codes, reset tokens | Deleted promptly after consumption or expiry |
| Application logs | Short, and **already free of personal data** |
| Audit log | Longest; it is the accountability record |

---

## 5. Data that must never be logged — APPROVED

Passwords and hashes · tokens and session identifiers · 2FA secrets, TOTP codes,
recovery codes · verification codes and reset tokens · email addresses · IP
addresses · precise locations · full request/response bodies for authenticated
endpoints.

**Redaction is applied at the logger**, not at each call site. See
[`../architecture/observability.md`](../architecture/observability.md).

---

## 6. Third parties — PROPOSED

Every third party that receives personal data is a processor with an obligation
attached.

| Third party | Status |
| --- | --- |
| **Transactional email provider** | Necessary. Receives email addresses. Provider chosen and in production: **Zoho Mail, EU region**, so processing is in the EU. **OPEN (SEC-3):** processor terms, retention at the provider, and the processing-location analysis. |
| **Font CDN** | **Avoided.** Fonts are **self-hosted** — a font CDN transmits every visitor's IP address to a third party for no functional gain. |
| **External error tracker** | **OPEN (OB-2).** If used, payloads must be scrubbed before transmission. |
| **Analytics** | None in the approved surface. Adding any would be a new personal-data decision, not a technical one. |
| Hosting and database providers | **OPEN (OPS-1)**, including processing location |

---

## 7. Private source material — APPROVED, non-negotiable

`design-reference/private-source/` contains private photographs used as artwork
reference. They must **never** be committed, published, copied into public
documentation, exposed in application bundles, or served from a CDN — under **any**
licence, at any time.

Enforced by `.gitignore` and a CI check in the frontend repository.

Related: the **consent records** for real-person and real-animal likenesses
(LR-2) are a legal prerequisite for shipping those characters at all. See
`purrenade/docs/product/licensing-and-rights.md` §3.

---

## 8. Open questions

| Ref | Question |
| --- | --- |
| SEC-3 | Deletion, export, consent capture, retention — **the whole section** |
| SEC-5 | Retention period for the run records and compact derived facts that are kept (§2A). ANTI-5 is resolved — no raw per-event history is retained — so this is now a period question, not a form question. |
| LB-4 / LB-5 | Leaderboard opt-out; deleted and banned players' entries |
| AUTH-4 | How session location is derived, and whether it is proportionate |
| OB-2 | Is an external error tracker acceptable? |
| OPS-1 | Hosting and data processing location |
| PR-1 | Can a player change their username? |
| LR-2 | Likeness consent records |
