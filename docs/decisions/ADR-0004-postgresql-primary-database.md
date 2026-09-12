# ADR-0004 — PostgreSQL as the primary database

- **Status:** Accepted
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner

## Context

The approved technology direction specifies **PostgreSQL** and explicitly states
**do not use MySQL**.

The data this product stores is not merely CRUD. Three workloads shape the choice:

1. **Ranked leaderboards** — weekly and all-time, read-heavy, requiring
   deterministic total ordering and efficient top-N over a growing table.
2. **Race-safe progression** — a paw ledger with a threshold that consumes 200
   and preserves overflow, evaluated concurrently with achievement unlocks.
3. **Integrity-critical auth data** — sessions, 2FA secrets, recovery codes.

## Decision

**PostgreSQL is the single source of truth for all persistent product data.**
Target version **18** (PROPOSED; re-verified at M1).

1. **Schema-level integrity.** Foreign keys, check constraints, unique
   constraints, and `NOT NULL` are used as correctness mechanisms — not as
   documentation of what the application already checks.
2. **Native features are used rather than emulated:** appropriate native types,
   partial and covering indexes, `ON CONFLICT` for idempotent upserts, window
   functions for ranking, transactional DDL for safe migrations.
3. **Indexes follow real query patterns**, added with the queries that need them
   and verified against realistic data volumes.
4. **Transactions wherever integrity requires them** — notably run submission,
   which touches the run record, the paw ledger, achievement progress and unlocks
   atomically.
5. **Redis is never authoritative.** It may cache or project; the authoritative
   ranking always derives from PostgreSQL.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| **MySQL** | Explicitly excluded by the approved technology direction. |
| **Redis as the leaderboard source of truth** | Sorted sets are an excellent fit for ranking and a poor fit for durability, referential integrity, moderation, deletion under KVKK, and auditability. Used as a projection, not as truth. |
| **A document database** | The data is highly relational — players, runs, ledger entries, achievements, unlocks, sessions — and the integrity requirements are exactly what relational constraints exist for. |
| **SQLite for development** | Divergence between development and production SQL is precisely where constraint and concurrency bugs hide, and this product's hardest bugs are concurrency bugs. Use PostgreSQL everywhere, via Docker. |

## Consequences

**Easier**
- Concurrency correctness has real tools: transactions, row locking, unique
  constraints, `ON CONFLICT`.
- Ranking queries are expressible and indexable without a second system.
- Data integrity survives application bugs, because the database refuses invalid
  states rather than storing them.
- KVKK/GDPR deletion and export are tractable against a relational schema.

**Harder**
- Local development needs PostgreSQL running (Docker) rather than a file.
- Leaderboard performance requires a deliberate projection strategy rather than
  an `ORDER BY` that happens to work at small scale.
- The local client at the time of writing was PostgreSQL 16 against a target of
  18 — the environment must be aligned at M1.

**Now constrained**
- No business rule may rely on a uniqueness or integrity guarantee that is not
  also expressed in the schema.
- No caching layer may become the source of truth for ranking or progression.
- Migrations are reversible where practical.
