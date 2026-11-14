# ADR-0002 — PostgreSQL rather than MySQL

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-010, PF-042, PF-043

## Context

A sibling project in the same stack runs on MySQL successfully, so the default would be to continue. The question is whether a general ledger needs anything MySQL does not provide.

It does, and the reasons are specific rather than preferential.

## Decision

**PostgreSQL 18.** The tenant schema uses PostgreSQL-only features deliberately, and those features are load-bearing rather than convenient.

| Feature | Used for | Why it matters |
|---------|----------|----------------|
| `numeric` exact decimal | Every money column | Exact `SUM()` over millions of rows; no float coercion |
| `DEFERRABLE INITIALLY DEFERRED` constraint triggers | The balanced-entry invariant | An entry is transiently unbalanced while its lines insert; the check must fire at `COMMIT` and nowhere else |
| Row-level security | Entity scoping inside a tenant | Defence in depth behind the application's own scope |
| Declarative range partitioning | `journal_lines`, `bank_transactions`, `audit_log` | These grow for the life of the account and every query is period-bounded |
| Exclusion constraints | Non-overlapping tax-rate validity | A data rule the database can hold instead of the application |
| Partial and expression indexes | One control account per role per entity | Expresses a conditional uniqueness rule directly |
| `pg_stat_statements`, `EXPLAIN (ANALYZE, BUFFERS)` | Query budgets asserted in tests | Index and partition use proven rather than hoped for |
| WAL archiving and PITR | Per-tenant restore | The auditor's "as at 31 December" question |

## Alternatives considered

**MySQL 8 / MariaDB.** Familiar to the team and proven in the sibling project. It loses on three things. `DECIMAL` is exact, so money is fine - but there is no deferred constraint trigger, so the balanced-entry invariant could only be enforced in application code, which is exactly where it can be bypassed by a console command or a backfill. There is no row-level security, so entity isolation would have a single line of defence. Partitioning exists but is more limited and the planner's pruning is less reliable for the access patterns here.

**SQLite for development and tests.** Would make the suite fast and dependency-free. Impossible: the features above are the thing under test. A suite that silently skipped the constraint triggers, RLS and partitioning would be worse than no suite, because it would report green while testing a different system. Accepted cost: the tenant test suite requires a real PostgreSQL, and the documentation says so loudly so that 600 identical connection errors are not mistaken for a code failure.

**A document store for the ledger.** Rejected without much debate. Double-entry bookkeeping is relational by nature, and the invariants are set-based.

## Consequences

**Easy:** the ledger's integrity rules live where they cannot be bypassed; per-tenant PITR; partition pruning on the largest tables; query plans that can be asserted.

**Hard:** `numeric` is slower than `bigint`, which pushes index design to matter more; the development environment needs a real database; the team carries PostgreSQL operational knowledge - connection pooling, autovacuum tuning on partitioned append-only tables, WAL management.

**Ruled out:** running the tenant schema on anything else. This is a deliberate lock-in and is the cost of the invariants.

## Revisit when

Never, realistically. Moving a general ledger between engines after it holds customer data is not a migration anyone should plan. If this needs revisiting, the right response is to question the requirement that drove it, not the engine.
