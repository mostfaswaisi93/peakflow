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

### PF-184 · Statement of Financial Position — done
`Reporting` · **5 pts** · depends: PF-183, PF-037 · standards: IAS 1 · `type:compliance`

- [x] Built from the chart's IFRS statement classification, so it is a grouping rather than a hand-built report
- [x] Current and non-current split, comparative period, and the IAS 1 paragraph each line comes from
- [x] Assets equal liabilities plus equity, asserted
- [x] An account with no classification appears in an explicit "unclassified" section rather than being omitted - a silently dropped account is how a statement stops balancing

`App\Tenant\Reporting\Services\StatementOfFinancialPosition` ·
`App\Tenant\Ledger\Enums\StatementLine` · `bin/verify reporting`

**Nothing in the statement knows an account code.** Every figure comes from
the trial balance and every heading from the chart's own `statement_line`,
so adding an account to a chart changes the statement without anybody
editing the statement — and the person who added it has no reason to think
they broke one.

**The equation is not the trial balance's balance check.** The trial balance
asserts debits equal credits over every account, profit and loss included;
this statement covers only the accounts classified onto it, so the two
differ by exactly the result for the period, which has not been closed into
retained earnings yet. The result is therefore carried in before the
equation is asserted — without that, a correct statement would report an
imbalance on every date except the day after a year-end close.

**The presentation sign lives on the section, in one place.** A liability's
natural balance is a credit, stored negative, and a report that forgot to
flip it would print equity negative *and still balance*, because both sides
move together. That is why there is a check on the sign itself and not only
on the equation.

**An unclassified balance is declared before anything else**, and named as
the cause: it is the explanation for the difference in almost every case,
and it is the one a person can act on. Leading with "the ledger may be
broken" when the answer is "three accounts need a line" sends the reader to
the wrong place.

Two bites that proved the checks rather than the code. Inflating the emitted
equity total changed nothing, because the difference is computed from a
local that the emitted string is derived from — so the bite was rewritten to
drop a liability line from the grouping, which is a defect the check does
see. And the IAS 1 reference check first bit the *cash* line, which an
entity whose only activity is an issued invoice does not have: the check
passed and proved nothing until the bite moved to the receivable.

### PF-187 · Statement of Changes in Equity — done
`Reporting` · **3 pts** · depends: PF-185 · standards: IAS 1 · `type:compliance`

- [x] Opening, movements by type (result for the period, OCI, transactions with owners), closing
- [x] Reconciles to the balance sheet's equity section, asserted
- [x] Prior-period adjustments presented per IAS 8 rather than folded into the current year

`App\Tenant\Reporting\Services\{StatementOfChangesInEquity,EquityMovements}` ·
`bin/verify reporting`

**It is the only statement that has to agree with both of the others.** Its
closing column must equal the equity section of the statement of financial
position, and its result row the bottom of the statement of profit or loss.
An entity whose three statements each look right alone and do not reconcile
has published three answers, and the reader cannot tell which one the ledger
holds — so the reconciliation is computed and declared here rather than left
to whoever reads them side by side.

**The cause of a movement cannot be read off the account.** A credit to
retained earnings is a profit if the year-end close made it and a
distribution if a dividend did; IAS 1.106 presents those separately and they
must never be added together. `journal_entries.posting_rule` is what every
posting carries and the close is one rule, so that is what the
classification rests on. The alternative is a box on a dividend form that
nobody ticks.

**Two defects it found in statements that were already green.**

The statement of financial position was *silently dropping accumulated
OCI*. A translation reserve's statement line belongs to the profit-or-loss
statement, because that is where the year's movement is presented — but the
BALANCE is a component of equity and belongs on the balance sheet. It was
excluded on the statement test, so a real equity balance vanished, assets
exceeded liabilities plus equity by exactly the reserve, and the
unclassified section said nothing because the account *had* a line. Only a
statement whose closing column has to equal that section could see it.

And the movement query ignored `includeAdjusting` while the balances beside
it honoured it, so on the view that excludes period 13 the two sides would
have disagreed by exactly the year-end adjustments — on the first view an
auditor asks for.

> **One check here is structural and says so.** Reaching the adjusting
> period needs `period.post_adjusting` with step-up and a written reason,
> which this suite has no fixture for; a fixture posting to an ordinary
> period cannot see the flag at all, which the bite sweep proved by removing
> the bound and changing nothing. So the check reads the query. It catches
> the bound being deleted and would not catch it being wrong, and the
> behavioural check arrives with the fixture that can open period 13.
