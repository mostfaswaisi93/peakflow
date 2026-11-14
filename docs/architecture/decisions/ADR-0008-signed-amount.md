# ADR-0008 — A single signed amount, not debit and credit columns

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-041, PF-043

## Context

A journal line's amount can be stored two ways. The traditional accounting shape is two columns, `debit` and `credit`, exactly one of which is non-zero. The alternative is one signed column, with debits positive and credits negative.

This looks like a style question. It is not: it determines what the balancing invariant looks like, and therefore how reliably it can be enforced.

## Decision

**One signed `amount`** in the transaction currency, one signed `functional_amount`, with `CHECK (amount <> 0)`. Debits are positive, credits negative.

The invariant becomes:

```sql
SUM(functional_amount) = 0  -- per journal entry
```

The UI still renders Dr and Cr columns, because that is what accountants read. **The presentation splits the sign; the storage does not.**

## Alternatives considered

**Separate `debit` and `credit` columns.** The traditional and more immediately readable shape. It loses on four counts, each of which is a class of bug:

1. **Two constraints instead of one.** You need `SUM(debit) = SUM(credit)` *and* a check that exactly one of the pair is non-zero per row. The second is the one people forget, and a row with both populated is silently double-counted by some queries and not others.
2. **"Which column" bugs.** Every posting rule has to decide which column to write, and a sign error becomes a column error - which is harder to spot in a code review because both columns are plausible.
3. **Every aggregate needs a subtraction.** `SUM(debit) - SUM(credit)` appears in the trial balance, in every report, in every balance read model and in the drill-down. Each occurrence is a place to get the direction backwards, and the result is a number of the right magnitude and the wrong sign.
4. **Three-valued nullability.** If the unused column is `NULL`, every aggregate needs `COALESCE`; if it is `0`, the "exactly one non-zero" check has to allow a genuine zero-amount line, which the `CHECK (amount <> 0)` rules out directly in the signed model.

**A signed amount plus a redundant `side` enum.** Rejected: two sources of truth for the same fact, which must then be kept consistent by a constraint. The sign already carries the side.

**An unsigned amount plus a `side` enum.** Equivalent to two columns with extra steps, and every aggregate becomes a `CASE`.

## Consequences

**Easy:** one invariant, enforceable as a single aggregate in one deferred constraint trigger; `SUM(functional_amount)` over **any** slice of the ledger is directly meaningful - by account, by period, by dimension, by party - with no subtraction and no `CASE`; a sign error is caught by the balancing constraint rather than producing a plausible wrong number.

**Hard:** developers who think in Dr/Cr must translate at the boundary, so the `Money` and posting-rule APIs name the direction explicitly (`debit(...)`, `credit(...)`) and convert to a sign internally; a raw `SELECT` against `journal_lines` is less immediately readable to an accountant, so the drill-down and the GL detail report always render split columns; imports from systems that supply Dr/Cr pairs need an explicit conversion with its own test.

**Ruled out:** exposing the signed amount directly in any accountant-facing view or export. Internal representation, external convention.

## Revisit when

Not expected. If an accounting-standards requirement ever demands stored Dr/Cr columns - none is known - they would be generated columns derived from the sign, keeping one source of truth.
