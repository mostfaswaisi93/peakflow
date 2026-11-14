# ADR-0009 — Row-lock allocation, not a database sequence

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-071, PF-093, PF-094

## Context

Most jurisdictions require invoice numbers to be **sequential with no gaps**, per issuer, per series. A gap is a question an auditor will ask, and "the transaction rolled back" is not an answer a tax authority accepts.

The obvious implementation is a database sequence. It is wrong, and the reason is worth stating precisely because it is counter-intuitive.

## Decision

Allocation is a **row lock on a `number_series` row**, inside the issuing transaction:

```sql
SELECT next_number FROM number_series
 WHERE entity_id = $1 AND series_id = $2 AND period_year = $3
   FOR UPDATE;
UPDATE number_series SET next_number = next_number + 1 WHERE ...;
```

Three operational rules make it safe:

1. **Allocate last** - after validation, tax computation and the posting rule have all succeeded.
2. **No I/O inside the lock** - no PDF render, no clearance call, no mail. Those run after commit, from a queued job.
3. **One series, one lock** - series are per entity, per document type, per year, optionally per branch, so two entities or two document types never contend.

## Alternatives considered

**A PostgreSQL `SEQUENCE` / `nextval()`.** The default answer, and disqualified by design rather than by implementation detail: **`nextval()` is deliberately non-transactional.** It does not take a lock, it does not block concurrent callers, and **a rollback does not return the number.** That behaviour is exactly right for a surrogate key - it is why sequences are fast - and exactly wrong for a legal sequence. Using it produces a gap on every validation failure, every deadlock, every crash and every retried transaction. The gaps would be rare, invisible in testing, and discovered during an audit.

**`MAX(number) + 1` within the transaction.** No extra table. It loses because it requires `SERIALIZABLE` isolation to be correct, which under concurrency means frequent serialization failures and retries on the hottest path in the application - and it scans or indexes a growing table on every issue.

**Application-level locking via Redis.** Works, and is fast. Rejected because the lock and the data it protects would be in different systems: a Redis failure, eviction or clock problem could permit a double allocation that the database would happily accept. The lock must be in the same transaction as the insert.

**Allocating the number first, then doing the work.** The natural way to write it, and the thing `PF-094` is designed to catch. It produces gaps whenever anything after the allocation fails, and it holds the series lock for the full duration of the transaction - serializing every concurrent issuer behind the slowest one.

**Issuing numbers from a pre-generated pool.** Would allow concurrency, at the cost of a reservation system that must reclaim unused numbers - which is the original problem with extra state.

## Consequences

**Easy:** gaplessness is structural, not probabilistic; a gap report per series is a trivial query and is what an auditor asks for; contention is scoped to one series, so the system scales by series rather than globally.

**Hard:** issuers of the same series are **serialized**, which is an accepted and bounded cost - a series is one entity's one document type for one year, and the lock is held for a single `UPDATE` at the end of a transaction; the "allocate last, no I/O in the lock" discipline is easy to break while refactoring, which is why `PF-094` exists and why the rules are commented at the call site.

**Ruled out:** bulk-issuing thousands of invoices in a single transaction, which would hold the lock for its duration. Bulk issuing is therefore batched into separate transactions, deliberately.

## Revisit when

A single series genuinely needs issuance throughput beyond what a short row lock sustains - realistically thousands per second on one series, which no business has. The correct response would be sub-series per branch or per till, which the schema already supports, not a weaker guarantee.
