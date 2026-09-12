# Endpoints — Profile

**Contract shape only.**

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| GET | `/profile` | authenticated | — | normal |
| PATCH | `/profile` | authenticated | yes | normal |
| POST | `/profile/delete` | authenticated | yes | strict |

---

## Get profile — APPROVED

Returns what v0.3 board 17 displays: username, avatar, "running since"
(derived from account creation), best score, run count, achievements unlocked out
of the catalogue size, **`lifetime_paws`**, favourite character, locale, and 2FA
state.

Note the paw field: the profile shows `lifetime_paws`, while the main menu shows
`loli_cycle_paws / 200`. They are different numbers and must not be confused.

---

## Update profile and settings — APPROVED

Covers locale, favourite character, and the settings from v0.3 board 18: music,
sound effects, and **reduced motion**.

| Rule | Status |
| --- | --- |
| Changing locale updates the profile; the client also mirrors it on the device | APPROVED — the language switcher works before a session exists |
| Transactional emails use the **profile** locale, not the requesting device's | APPROVED |
| The favourite character must be **selectable** for this player | APPROVED — validated server-side, not assumed from the UI |
| Username changes | **OPEN** — nothing in v0.3 offers one, and it interacts with leaderboard identity |

### Audio settings — APPROVED (Option C)

v0.3 contradicted itself: board 12 (pause) showed **volume sliders**, board 18 (settings)
showed **on/off toggles**. **Option C is approved**, deliberately resolving conflict #10.

| Surface | Controls |
| --- | --- |
| **Settings** | Music volume slider · SFX volume slider |
| **Pause** | Quick music mute/unmute · quick SFX mute/unmute |

**Four persisted fields:** `music_volume`, `music_muted`, `effects_volume`, `effects_muted`.

**Mute is independent of volume — APPROVED.** Unmuting restores the **previous non-zero
volume**. Mute is never stored as `volume = 0`, which would destroy the level the player chose.

Volume values are clamped to `0.0`–`1.0`. A muted channel is silent regardless of its stored
volume.

### Reduced motion — APPROVED

Stored on the profile so it follows the account. The client combines it with the
OS `prefers-reduced-motion` preference: a player who already told their device
they want less motion should not have to say it again.

---

## Account deletion — architecture PROPOSED, policy OPEN (SEC-3)

Required by **KVKK/GDPR**, and by both major app stores for any app supporting account
creation — which matters given the approved requirement that Android/iOS distribution remain
possible.

### PROPOSED architecture

| Principle | Detail |
| --- | --- |
| Identity data | Deletion **removes or anonymizes** personal identity data as required |
| Public visibility | A deleted user **must no longer appear publicly under their former identity** |
| Leaderboard projections | **Active projections must account for deletion and anonymization.** A projection that is not rebuilt keeps serving a deleted identity — the exact failure a cached ranking makes easy |
| Historical results | Retention governed by the eventual privacy/retention policy, not by this document |
| Gameplay telemetry | Falls under the same policy — see [`../../security/data-protection.md`](../../security/data-protection.md) §2A |

### Still OPEN — policy, not architecture

**No grace period, retention window, anonymization method, or legal basis is invented here.**
This requires an explicit product/legal decision.

| Question | Why it is hard |
| --- | --- |
| Immediate deletion, or a grace period? | A grace period protects against mistakes and complicates "deleted means deleted" |
| What is retained for legal or audit reasons, and for how long? | Audit logs and abuse records pull against erasure |
| Is deletion reversible before it completes? | |
| How are historical runs anonymized without destroying aggregate integrity? | They are the basis of the leaderboard |

See [`../../security/data-protection.md`](../../security/data-protection.md).

---

## Open questions

| Ref | Question |
| --- | --- |
| SEC-3 | Account deletion **policy** — grace period, retention, anonymization method, legal basis. Architecture is proposed; policy is genuinely OPEN. |

**Resolved by M0.6:** #10 — audio **Option C APPROVED**.
| PR-1 | Can a player change their username? |
| PR-2 | Avatar source — uploaded, generated, or initials-based (SI-6). An upload path would add file storage, moderation, and a new abuse surface |
