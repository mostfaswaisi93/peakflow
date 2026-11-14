# ADR-0005 — Ledger invariants enforced in PostgreSQL

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-043, PF-044, PF-045, PF-233

## Context

A general ledger has invariants that must hold absolutely:

1. Every journal entry sums to zero in the entity's functional currency.
2. Nothing posts into a period that does not accept postings.
3. A posted entry is never mutated or deleted.

The question is where to enforce them. The application is the obvious place and the easy place.

## Decision

**All three are enforced in PostgreSQL.** The application enforces them too, but only so the error message is useful.

```sql
-- 1. Balanced entries, checked at COMMIT
CREATE CONSTRAINT TRIGGER journal_entry_must_balance
    AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION assert_entry_balanced();

-- 2. Closed periods, on the entry header
CREATE TRIGGER journal_entry_period_open
    BEFORE INSERT OR UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION assert_period_accepts_postings();

-- 3. Immutability, plus revoked privileges
CREATE TRIGGER journal_posted_immutable
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW WHEN (OLD.status = 'posted')
    EXECUTE FUNCTION raise_posted_immutable();

REVOKE DELETE ON journal_entries, journal_lines FROM peakflow_app;
```

`DEFERRABLE INITIALLY DEFERRED` is the mechanism that makes the first one possible at all: an entry is **transiently unbalanced** while its lines are being inserted, so a per-statement trigger would reject every multi-line entry ever written. The deferral means the check fires once, at `COMMIT`, which is the only moment the invariant is meaningful. A comment in the migration says exactly this, so nobody "fixes" it later.

## Alternatives considered

**Application-level validation only.** The default. It is bypassed by: a console command, a data migration, a backfill script, a queued job written in a hurry, a `tinker` session during an incident, a future ORM upgrade that changes event firing, and any code path a developer did not know existed. Each of those is a realistic way an unbalanced entry reaches the database, and once one is there, **every report from that date is wrong and nobody knows why**. The asymmetry decides it: application-only validation is cheaper to write and catastrophic to be wrong about.

**A `CHECK` constraint.** Cannot work: a `CHECK` is per row, and the invariant is per entry across rows.

**A materialized sum column with a `CHECK`.** Would work mechanically but requires maintaining a denormalized sum that is itself a thing to get wrong, and it does not prevent a row being inserted outside the maintaining code path.

**Immutability by convention plus an audit log.** Rejected: an audit log records that a mutation happened. The requirement is that it cannot.

**Trusting an application-level "repository" layer.** Rejected for the same reason as validation-only - a layer is only a boundary if something stops you going around it.

## Consequences

**Easy:** the invariants hold against every access path, including ones that do not exist yet; `ledger:verify` can be a verification rather than a repair; a corrupted ledger becomes a category of bug that is structurally impossible rather than merely unlikely.

**Hard:** errors surface as database exceptions and must be translated into readable messages - so each trigger raises a named exception the application maps; the invariants are expressed in PL/pgSQL, which is a second language in the repository and needs its own tests; a legitimate bulk correction must run as `peakflow_migrator` from a separate process, deliberately, which is friction by design.

**Ruled out:** running the tenant schema on an engine without deferred constraint triggers, and testing the ledger on SQLite.

## Revisit when

Never for correctness. If a trigger becomes a measured bottleneck under write load, the response is to optimize the trigger function - it is a single aggregate over one entry's lines - not to move the check into the application.
