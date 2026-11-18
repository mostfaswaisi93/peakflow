# Read models

PF-182. What may be materialized, what may not, and the one rule that
decides.

## The rule

**If a figure can be derived cheaply enough, it is not materialized.**

A read model is a performance decision with a cost, not a default. The
cost is not the disk: it is that every materialized figure is a second
place the truth lives, and the two can disagree. Every one of them needs
a rebuild, a drift check, and somebody to read the drift check — and a
product with twelve read models has twelve ways to be quietly wrong.

"Cheaply enough" is measured, not argued. `bin/bench` holds the
measurements, and a proposal to materialize something carries a number
from it.

## What is materialized, and why

### `account_balances` — PF-058

One row per entity, period, account and dimension set. Maintained by
triggers as journal lines are posted.

The measurement that justifies it: at ten million journal lines, a trial
balance aggregated from the journal takes **7.5 seconds**; read from
this model it takes **a few milliseconds**. `bin/bench trialbalance`
reports both, on demand, so the justification can be re-checked rather
than believed.

It is the only materialized figure in the product.

## What is NOT materialized, and why

**A document's open balance.** `peakflow_document_open_balance()`
computes it from the document, its credit notes and its allocations,
every time. It is a handful of indexed reads against rows that are
already in cache, and the figure changes on every allocation — so a
materialized version would be rebuilt almost as often as it was read,
and would be the figure a customer disputes.

**Aging buckets.** Derived from open balances and due dates at the
moment the report is asked for. The buckets move every day by
definition: a materialized bucket is wrong by the following morning.

**Statement totals.** Summed from the same open balances. A statement is
produced for one party at a time and reconciles to the control account
on the way out; materializing it would add a thing to rebuild in order
to save a sum over a few dozen rows.

**Anything a human waits less than a second for.** Which is most of it.

## The obligations a read model carries

Any new one has to arrive with all four, in the same change:

1. **A full rebuild.** `BalanceRebuilder::rebuild()` for the balances.
   Not a migration — a command somebody can run on a Tuesday.
2. **A drift check that compares it against the source** and names what
   differs in terms a person can act on. `ledger:rebuild-balances
   --check` prints the account code and the period label, not the ids:
   a finding that reads `01M7…|01M7…` names nothing.
3. **Nothing repaired silently.** The check reports and changes nothing.
   A silent repair hides the bug that caused the drift, and a figure
   that was wrong for a week was on a report for a week.
4. **A benchmark**, so the next person can tell whether it is still
   worth its cost.

## Reporting writes nothing

`Reporting` may read every module and is permitted to write to none of
them. Enforced twice: by the Deptrac ruleset, which gives it read access
only, and by `ArchitectureRules::reportingNeverWrites()`, which scans
the module for writing calls.

This is not tidiness. A reporting module that could write is one where a
report that found a discrepancy might fix it — and the fix would be made
by the code least able to understand what caused it.
