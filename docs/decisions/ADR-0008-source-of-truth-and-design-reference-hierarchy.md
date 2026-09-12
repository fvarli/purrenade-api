# ADR-0008 — Source-of-truth and design-reference hierarchy

- **Status:** Accepted
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner

## Context

Purrenade has **four** classes of reference material, produced at different times
by different means, and they genuinely disagree with each other:

| Material | Location |
| --- | --- |
| Written Product/Game Specification | `purrenade/docs/product/` |
| Claude Design v0.3 | `design-reference/claude-design/v0.3` |
| Historical v0.2.1 decisions (as represented inside v0.3) | within v0.3 |
| ChatGPT Art Direction Board v1 | `design-reference/chatgpt-art-direction` |
| Private source photographs | `design-reference/private-source/` (local-only) |

The disagreements are not cosmetic. The art board and v0.3 differ on **all six
named palette tokens**, on the wordmark, and — most consequentially — on the
**camera and perspective**, where one implies a 2D scroller and the other implies
a faux-3D projection. Without a rule, either could have been implemented.

## Decision

### Priority order

1. **Written Product/Game Specification** — authoritative for **behavior** and
   game rules.
2. **Claude Design v0.3** — authoritative for approved **UX/UI structure**, brand
   integration and visual hierarchy.
3. **Historical v0.2.1 decisions represented in v0.3** — secondary reference only,
   and only where v0.3 explicitly preserves them.
4. **ChatGPT Art Direction Board** — **aspirational only**: atmosphere,
   production-art ambition, environmental richness, colour relationships,
   character scale, composition, mood. **Not** a pixel-perfect target, **not** an
   asset source, **not** a source of mechanics.
5. **Private source references** — local-only character artwork reference.
   **Never exposed, published, or committed.**

### Conflict rules

1. The written specification wins for behavior.
2. Claude Design v0.3 wins for approved visual decisions.
3. Approved production assets win over illustrative mockups.
4. **If a conflict cannot be resolved with these rules, stop and report it
   instead of inventing a decision.**

### Decision-status discipline

Every documented decision carries **APPROVED**, **PROPOSED**, or **OPEN**.
PROPOSED and OPEN are never silently promoted. Nothing is asserted as APPROVED
without a source in the specification, in v0.3, or in a recorded review.

### Appearing in a design reference is not approval

A mechanic exists when the written specification says it exists. The live example
is **Çay and Trileçe**: both appear in approved v0.3 material with gameplay-like
labels ("short speed boost", "extra life"), and **neither is an approved
mechanic**. They are recorded in `docs/product/deferred-design-exploration.md`.

### Conflicts are registered, not resolved ad hoc

Every conflict found lives in `docs/product/design-reference-conflicts.md`, with
its resolution or an OPEN marker — including the **losing** variant, so it cannot
re-enter the product by accident. The rejected palette hexes appear there and
nowhere else.

### Cross-repository sync

Product-wide ADRs are stored in **both** repositories with the same number and
the same decision, so each repository is independently readable. Two copies that
disagree are a **defect**, not a variant.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| **Treat the newest reference as authoritative** | The art board is newer in spirit but explicitly labelled reference-only. Recency is not authority. |
| **Treat visual references as authoritative for everything** | Mockups imply mechanics they never specified — the Çay/Trileçe case, and the near-miss mechanic implied by an achievement label. |
| **Resolve conflicts case by case at implementation time** | Produces silent, undocumented decisions made by whoever happened to be implementing. |
| **A single merged design document** | Would destroy the provenance the register depends on, and merging is exactly the step that needs a rule. |

## Consequences

**Easier**
- Most conflicts resolve mechanically instead of by debate.
- The rejected variants are recorded, so they cannot drift back in.
- "Where did this number come from?" always has an answer.

**Harder**
- Someone must maintain the conflict register and the decision register.
- Work genuinely stops when an unresolvable conflict is found, rather than
  proceeding on a guess. That is the intended behavior, not a failure mode.

**Now constrained**
- No ChatGPT-board value may enter code.
- No mechanic may be inferred from a mockup.
- No private source photograph may be committed, published, bundled, or served —
  under any licence, at any time.
