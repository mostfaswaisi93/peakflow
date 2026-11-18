# Phase 10 — Reporting, close and consolidation — finished

The tasks of [phase-10-reporting.md](../phase-10-reporting.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-182 · The reporting read-model architecture — done
`Reporting` · **8 pts** · depends: PF-053, PF-042 · standards: — · `risk:high`

- [x] Read models are derived, never authoritative, and every one carries the rebuild that produces it
- [x] `Reporting` performs no writes to domain tables - enforced by the architecture test from `PF-005` and by Deptrac
- [x] Incremental maintenance on posting, with a full rebuild always available and benchmarked
- [x] The nightly `--check` diffs rebuilt against live and raises an alert on any difference, naming the account and period
- [x] A documented rule: if a figure can be derived cheaply enough, it is not materialized. A read model is a performance decision with a cost, not a default

`docs/architecture/read-models.md` · `account_balances` ·
`BalanceRebuilder` · `ledger:rebuild-balances --check` ·
`ArchitectureRules::reportingNeverWrites()` · `bin/bench trialbalance`

Four of the five were built by PF-058, which is where the measurement
lives: ten million journal lines, 7.5 seconds from the journal against
a few milliseconds from the read model. The drift check names the
account code and the period label rather than their ids, because a
finding that reads `01M7…|01M7…` names nothing a person can act on, and
it repairs **nothing** — a silent repair hides the bug that caused the
drift, and a figure that was wrong for a week was on a report for a
week.

What this task adds is the rule itself, written down: **if a figure can
be derived cheaply enough, it is not materialized.** The cost of a read
model is not disk, it is that every materialized figure is a second
place the truth lives — so each one needs a rebuild, a drift check,
somebody who reads the drift check, and a benchmark that says whether
it is still worth its cost. `account_balances` is the only one in the
product, and the document says why open balances, aging buckets and
statement totals are not.

### PF-183 · Trial balance — done
`Reporting` · **3 pts** · depends: PF-182, PF-058 · standards: IAS 1

- [x] Any date, any period, opening and closing, movement columns, with and without adjusting entries
- [x] Functional or presentation currency, with the translation basis stated on the output
- [x] Debits equal credits, asserted on the report itself - a trial balance that does not balance must say so prominently rather than printing
- [x] Drill-down from any figure

`App\Tenant\Reporting\Services\TrialBalanceReport` ·
`TrialBalance::drillDown()` (PF-058) · `bin/verify reporting`

**The movement is closing minus opening**, which is the only definition
under which the three columns cannot disagree. Summing the period's own
lines separately would give a fourth figure that is right in a different
way, and the first time the two differed nobody could say which was the
error. Opening is read the day *before* the period starts: on the first
day, the month's first invoice would be reported as something that was
always there.

**It shouts when it does not balance.** Not a boolean in an array a
screen may ignore, a PDF may not print and an export may drop — a
sentence at the top of the page, in both languages, naming the
difference and what to run. An unbalanced *entry* is refused by a
deferred constraint, so an unbalanced trial balance means a row was
written around the posting path or a read model has drifted; the
declaration says so, says every figure below it is unreliable, and
names `ledger:rebuild-balances --check`.

**A presentation currency carries its rate, its date and its source**,
or the report is refused. A translated figure nobody can reproduce
reconciles to nothing and the reader cannot tell. It asks for the rate
in the direction it needs rather than inverting the other one, because
inverting is how a consolidation acquires a persistent one-cent
difference that grows with every account.

Two things the bite sweep established rather than argued: a zero-balance
filter here would be a guard that cannot fire, because `TrialBalance`
already ends both its queries in `HAVING sum(...) <> 0` — so it was
removed; and a check whose fixture had a zero opening could not tell the
movement column from the closing one, which is why the fixture now
issues one invoice before the period and one inside it.
