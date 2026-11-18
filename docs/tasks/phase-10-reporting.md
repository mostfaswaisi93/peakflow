# Phase 10 — Reporting, close and consolidation

**17 tasks · 87 points**

Reporting is last among the domain phases because a report is a query over the journal, and the journal must be complete and trustworthy first. Reports built early become a second source of truth that then has to be unbuilt.

The rule this phase is written around: **a report is never a second opinion about the data.** Where a read model exists for performance, it is rebuildable from the journal by definition, and a nightly check **reports** drift rather than repairing it - because silent repair hides the bug that caused it.

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

### PF-184 · Statement of Financial Position
`Reporting` · **5 pts** · depends: PF-183, PF-037 · standards: IAS 1 · `type:compliance`

- [ ] Built from the chart's IFRS statement classification, so it is a grouping rather than a hand-built report
- [ ] Current and non-current split, comparative period, and a note-reference column
- [ ] Assets equal liabilities plus equity, asserted
- [ ] An account with no classification appears in an explicit "unclassified" section rather than being omitted - a silently dropped account is how a statement stops balancing

### PF-185 · Statement of Profit or Loss and OCI
`Reporting` · **5 pts** · depends: PF-184 · standards: IAS 1 · `type:compliance`

- [ ] By nature and by function, comparative, with OCI presented separately
- [ ] Translation differences from consolidation land in OCI, not in profit or loss (`PF-194`)
- [ ] Gross margin, operating result and net result as derived subtotals defined by the classification, not hard-coded
- [ ] Drill-down to account, then to journal line, then to source document

### PF-186 · Statement of Cash Flows
`Reporting` · **8 pts** · depends: PF-185 · standards: IAS 7 · `risk:high` `type:compliance`

- [ ] **Indirect method** from the movement in balance-sheet accounts, with each adjustment traceable to the accounts that produced it
- [ ] **Direct method** from the cash and bank journals, which is the independent check
- [ ] The two methods must agree, and the report asserts it rather than presenting whichever was asked for
- [ ] Operating, investing and financing classification driven by account configuration
- [ ] Non-cash movements excluded explicitly and listed, because an unexplained difference between the two methods is almost always a non-cash item nobody tagged

### PF-187 · Statement of Changes in Equity
`Reporting` · **3 pts** · depends: PF-185 · standards: IAS 1 · `type:compliance`

- [ ] Opening, movements by type (result for the period, OCI, distributions, capital transactions), closing
- [ ] Reconciles to the balance sheet's equity section, asserted
- [ ] Prior-period adjustments presented per IAS 8 rather than folded into the current year

### PF-188 · General ledger detail and registers
`Reporting` · **3 pts** · depends: PF-183 · standards: —

- [ ] GL detail by account and period with running balances
- [ ] Account activity for a date range, filterable by dimension, party and journal
- [ ] Journal register listing every entry in a period, which is what an auditor samples from
- [ ] Each report is exportable and reproducible, and each states its parameters on the output

### PF-189 · Universal drill-down
`Reporting` · **5 pts** · depends: PF-188 · standards: — · `risk:high` `type:a11y`

The structural promise: if a number cannot be explained, it is a bug.

- [ ] Every figure on every report drills to its journal lines, then to the source document, then to the user who created and posted it
- [ ] The path is the same mechanism everywhere - one drill-down service, not a per-report implementation
- [ ] Permissions are respected at every level: a user who cannot see a document sees that a line exists and that they lack access, rather than a blank
- [ ] Keyboard-operable, with focus management on each level and a breadcrumb back
- [ ] A test asserts drill-down from a cash-flow line all the way to an invoice line

### PF-190 · AR and AP aging
`Reporting` · **5 pts** · depends: PF-168, PF-144 · standards: IFRS 9

- [ ] Configurable buckets and a configurable basis - due date or document date - **stated on the report**, because the two give different answers and a report that does not say which is unusable
- [ ] Instalment terms split across buckets by each due date, not aged from the last one
- [ ] Credit balances and unapplied cash shown as negative buckets rather than netted away
- [ ] DSO and DPO, with the formula stated
- [ ] **Reconciles to the AR and AP control accounts**, and the report declares the reconciliation rather than leaving it to be discovered

### PF-191 · Bank reconciliation reporting
`Reporting` · **3 pts** · depends: PF-165 · standards: —

- [ ] The reconciliation statement for any past reconciliation, reproducible as it stood
- [ ] Suspense aging by bucket and entity, and in-transit aging
- [ ] Unreconciled-item aging per bank account, which is the leading indicator of a reconciliation that is drifting
- [ ] A reconciliation history per account with who authorized each one

### PF-192 · Cash position and forecast
`Reporting` · **8 pts** · depends: PF-190, PF-179 · standards: —

- [ ] Actual balances per account per entity, and consolidated in any presentation currency
- [ ] Forward view combining receivable due dates **weighted by each customer's historical payment behaviour**, payable due dates, recurring commitments, released-but-unpresented payments and manual expectations
- [ ] Answers *will we be short in week six*, and **shows which assumptions drive the answer** rather than producing one number
- [ ] Scenario comparison: best case, expected, worst case, each with its stated assumptions
- [ ] Intraday data feeds the current position and is marked provisional

### PF-193 · Budgets and variance
`Reporting` · **5 pts** · depends: PF-046, PF-185 · standards: —

- [ ] Budgets by account and dimension, by period, with versions and a locked approved version
- [ ] Budget versus actual with variance in absolute and percentage terms, and a materiality threshold so the report highlights what matters
- [ ] Commitments from open purchase orders shown against budget, because a committed budget is not an available one
- [ ] P&L by any dimension combination, which is the whole reason dimensions are columns

### PF-194 · Consolidation
`Reporting` · **8 pts** · depends: PF-057, PF-184 · standards: IAS 21, IAS 1 · `risk:high` `type:compliance`

- [ ] Group reporting across the tenant's entities into a group presentation currency
- [ ] **Closing rate for the statement of financial position, average rate for profit or loss**, with the resulting translation difference to **other comprehensive income** - not to profit or loss
- [ ] Entities with different fiscal calendars aligned explicitly, with the alignment basis disclosed on the output
- [ ] Minority interests and partial ownership percentages applied per entity
- [ ] A worked three-entity, three-currency example as a fixture with expected output to the cent

### PF-195 · Inter-entity elimination
`Reporting` · **5 pts** · depends: PF-194, PF-057 · standards: — · `risk:high`

- [ ] Reciprocal due-to and due-from pairs eliminated, and inter-entity revenue and expense eliminated
- [ ] **A proof that eliminations net to zero**, presented as part of the consolidation rather than assumed
- [ ] A non-zero residual is itemized by pair and **blocks the consolidated statement from being marked final**
- [ ] FX on an inter-entity balance between two functional currencies is identified separately rather than absorbed into the elimination

### PF-196 · The period close checklist
`Reporting` · **5 pts** · depends: PF-195, PF-050, PF-088 · standards: IAS 10 · `risk:high`

- [ ] `periods:close` runs every blocking check and reports all of them at once rather than failing on the first
- [ ] Blocking items: unposted documents in the period, unreconciled bank accounts, non-zero suspense, non-zero unexplained tax difference, aged in-transit items, failed recurring occurrences, unresolved three-way-match exceptions, inter-entity residuals, read-model drift
- [ ] Each item links directly to the screen that resolves it
- [ ] A controller may override a named item with a reason; the override is audited and appears on the compliance report
- [ ] The checklist state is retained per period, so "what did we accept at the time" is answerable

### PF-197 · Export
`Reporting` · **5 pts** · depends: PF-183 · standards: ISO 19005, RFC 4180 · `type:security`

- [ ] **XLSX with live formulas and number formats intact** rather than flattened text, so an accountant can keep working in it
- [ ] CSV as UTF-8 with BOM, RFC 4180 quoting, and **formula-injection neutralization on every cell** beginning with `=`, `+`, `-`, `@`, tab or carriage return - applied on the route, not only in the service
- [ ] PDF/A for archiving, veraPDF-verified
- [ ] JSON through the API with the same figures, from the same query
- [ ] Arabic exports carry correct encoding, direction and digit shape; an XLSX opened in Excel shows RTL columns in the right order
- [ ] A large export is queued and delivered as a signed download, never rendered in a request

### PF-198 · Reproducibility
`Reporting` · **3 pts** · depends: PF-197 · standards: — · `risk:high`

- [ ] Every report takes an explicit as-of basis, and the same parameters return the same numbers
- [ ] The parameters - entity, period, basis, currency, adjusting-entry treatment, as-of timestamp - are **printed on the output**
- [ ] A report run against a closed period is byte-stable, and a test asserts it by running one twice across a data change in a later period
- [ ] A saved report definition can be re-run by anyone with permission and produce the same artefact
