# ADR-0004 — `numeric` with three scales, not minor-unit integers

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-006, PF-081, PF-222

## Context

Every number in a financial system inherits the representation choice. It cannot be changed after there is data, because changing it means rewriting every money column in every tenant database and re-deriving every stored total.

## Decision

Money is **PostgreSQL `numeric`** at exactly three scales - `numeric(21,4)` for amounts, `numeric(21,8)` for unit prices, `numeric(21,12)` for rates - and arithmetic in PHP goes through `brick/money` with an **explicitly passed rounding policy on every operation**.

The API returns money as an object with a string amount and a currency code, never a bare number.

## Alternatives considered

**`float` / `double`.** Disqualified: IEEE 754 cannot represent `0.1`, and the accumulated drift over a large ledger is non-deterministic and unexplainable.

**Minor-unit integers (`bigint`).** Exact and fast, and the standard advice. Rejected for three reasons that are all concrete in this product's target markets:

1. **The ISO 4217 exponent is not 2.** `JOD`, `KWD`, `BHD`, `OMR`, `TND`, `LYD` and `IQD` have three; `JPY`, `KRW` and others have zero. An integer is meaningless without its currency, every read needs a per-currency division, and a forgotten division is a **1000× error in a Jordanian dinar amount that looks entirely plausible**. `JOD` is a target currency, so this is the normal case.
2. **`MGA` and `MRU` have base-5 subunits.** No power of ten represents them.
3. **Unit prices need more precision than amounts.** `0.0725` per unit is ordinary; one scale for both means price or total loses.

**A single `numeric` scale for everything.** Simpler. Rejected because an eight-decimal amount column invites storing an unrounded intermediate as a legal total, which is the precise failure the rounding rule exists to prevent. Three scales with different names make "this value is an amount" and "this value is a price" different types in practice.

**A hand-rolled money class over `bcmath` strings.** Fewer dependencies. Rejected because the single most valuable property of `brick/money` is that **it has no default rounding mode** - every operation must name one. A hand-rolled helper acquires a default within a month, and that default becomes the bug.

## Consequences

**Easy:** exact `SUM()` in the database; three-decimal and zero-decimal currencies work without special cases; a rounding mode cannot be omitted silently; the API cannot hand a client a float to misparse.

**Hard:** `numeric` is slower than `bigint`, so index and partition design carry more weight - the trial balance over ten million lines is a performance fixture rather than an afterthought; every money value crosses the PHP boundary as a string, so a developer who treats it as a number gets a type error, which is the intended outcome but is friction.

**Ruled out:** arithmetic in SQL beyond `SUM` and comparison, and any client-side money arithmetic that could disagree with the server.

## Revisit when

A profiled, reproducible performance problem traces specifically to `numeric` arithmetic rather than to a missing index or a missing partition bound. That has not happened in any benchmark run so far, and the correct first response would be to fix the plan.
