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

### PF-186 · Statement of Cash Flows — done
`Reporting` · **8 pts** · depends: PF-185 · standards: IAS 7 · `risk:high` `type:compliance`

- [x] **Indirect method** from the movement in balance-sheet accounts, with each adjustment traceable to the accounts that produced it
- [x] **Direct method** from the cash and bank journals, which is the independent check
- [x] The two methods must agree, and the report asserts it rather than presenting whichever was asked for
- [x] Operating, investing and financing classification driven by account configuration
- [x] Non-cash movements excluded explicitly and listed, because an unexplained difference between the two methods is almost always a non-cash item nobody tagged

`App\Tenant\Reporting\Services\{StatementOfCashFlows,CashMovements}` ·
`App\Tenant\Ledger\Enums\CashFlowActivity` · `bin/verify reporting`

**Both methods, every time.** IAS 7.18 permits either; this computes both,
because they are independent — the indirect one starts from profit and works
through the movement in every balance-sheet account, the direct one reads the
cash accounts themselves. They share the ledger and no arithmetic. A product
that presented whichever was asked for would present a wrong figure in
exactly the case where the other one knew.

And the checks compare each against the **cash accounts**, not only against
each other: two methods agreeing with one another and not with the bank are
two wrong answers, and a check comparing only the two would report them as
right.

**The activity is derived from the statement line, not declared again.** A
receivable is working capital *because* it is a receivable. A fourth column
saying so would be a second place for one fact to be wrong, and the two would
disagree on the first account somebody edited. What that costs is written
down in the enum: IAS 7.16 classifies by the nature of the transaction and a
presentation line is a proxy for it, so when a short-term investment sitting
on "other current assets" arrives, the answer is an override that defaults to
this — not a different default.

**What the two methods caught in each other.** The first version excluded a
non-cash movement by looking at each ACCOUNT's own classification. IAS 7.43
excludes the whole *transaction*: a machine bought on credit moves property
and payables and no money, and subtracting only the leg whose line is
"non-cash" leaves the other leg reported as a cash flow that never happened.
The two methods disagreed by exactly that leg, which is the entire reason the
product runs both. A non-cash transaction is now one that touches no cash
account *and* no profit-or-loss account — both conditions, because either
alone would wrongly exclude an ordinary accrual, which is precisely the
difference between a profit and the cash it produced.

**A transfer inside cash is not a flow** — IAS 7.7. The direct method
excludes it rather than netting it, because counting both sides reports an
inflow and an outflow that never happened.

Three fixtures were wrong before the code was. Manual journals to the bank
and the receivables control were refused, correctly — a control account's
balance is the total of a subledger — so the cash now moves through the
posting rules that move it. The receipt check first asserted only that the
operating section was positive, and the bite sweep showed a sliver of
withholding tax kept it positive even with receivables misclassified as
financing; it now asserts the other two sections are *empty*, which the
misclassification cannot survive. And that assertion in turn failed on the
fixture's own opening entry, which credits equity and is a financing flow.

### PF-190 · AR and AP aging
`Reporting` · **5 pts** · depends: PF-168, PF-144 · standards: IFRS 9

- [x] Configurable buckets and a configurable basis - due date or document date - **stated on the report**, because the two give different answers and a report that does not say which is unusable
  - Stated on **both** sides, and boundaries that would make an impossible bucket are refused rather than silently reordered. The boundaries reach the payables side too - a boundary the AP half ignores makes one report with two grids, and the bucket names do not say so.
  - The defaults are the Overview tile's own, asserted by a check, because the tile, the IFRS 9 provision matrix and this report are read side by side and two of them meaning different things by "61-90" is a difference nobody would think to look for.
- [x] Instalment terms split across buckets by each due date, not aged from the last one
  - **The Overview tile and the IFRS 9 provision both do the wrong thing here, and that is not fixed.** `OverviewQuery::receivables()` and `ExpectedCreditLoss::openReceivables()` age from `max(due_dates)`, the **last** instalment, so a customer on 30/60/90 terms reads as entirely current until the ninetieth day - and the ECL matrix then provisions them at the current rate, which understates the provision rather than just mis-colouring a tile. Left alone deliberately: the ECL reverses and reposts a reported provision, so restating it is a decision with an audit trail behind it, not a bug fix. It wants its own task.
  - Both also hard-code `document_type IN ('380','383','386')`, which omits `326` (partial invoice) and `384` (corrected invoice) - both money a customer owes, so a partial invoice is invisible to the tile and carries no provision at all. This report derives the set from `DocumentType` instead and lets the control-account reconciliation prove it.
- [x] Credit balances and unapplied cash shown as negative buckets rather than netted away
  - Netting is what hides a customer who is simultaneously 90 days late on one invoice and sitting on an unallocated receipt: the net reads as a healthy account and the 90-day column reads as empty.
- [x] DSO and DPO, with the formula stated
  - Both state their formula, their window and their **currency**, and both refuse an empty window with a sentence rather than returning zero - a DSO of zero reads as "our customers pay immediately", which is the opposite of what an empty window means.
  - DPO used to sum `bills.net_amount` across every currency a supplier had billed in, divided into a control-account balance that was itself adding currencies, and printed the quotient to one decimal place. That is what makes a ratio dangerous: it looks precise, and nobody reads a number with a decimal point and asks whether its two halves were denominated in the same thing. Fixed in PF-144's own service so there is still one DPO, with the excluded documents counted and reported.
  - And the receivables side had a counter that could not count: it selected `count(*) FILTER (WHERE currency <> ?)` to report what it left out, with `currency = ?` in the WHERE clause as well, so the rows it would have counted were gone before it ran - and the result was then dropped on the floor, never returned. Two failures in one line, both the same failure as a check that cannot fail, and both surviving for the same reason: it looked like coverage.
- [x] **Reconciles to the AR and AP control accounts**, and the report declares the reconciliation rather than leaving it to be discovered
  - Declares it either way - agreement is a statement, not a silence - and shouts when it does not agree, which is bitten by leaving a real difference out of the declaration.
  - `PayablesAging::controlAccountBalance()` counted **draft** entries, so somebody's unfinished journal became a reconciliation difference and the report then accused the ledger: it offered three causes, none of which was "somebody has a journal open". Naming a culprit that did not do it is worse than a wrong figure, because somebody acts on it. Fixed, with a check that writes a draft straight onto the control account - no service will, which is the point: the row exists in the wild and the report has to ignore it.

