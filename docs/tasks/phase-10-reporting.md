# Phase 10 — Reporting, close and consolidation

**17 tasks · 87 points**

Reporting is last among the domain phases because a report is a query over the journal, and the journal must be complete and trustworthy first. Reports built early become a second source of truth that then has to be unbuilt.

The rule this phase is written around: **a report is never a second opinion about the data.** Where a read model exists for performance, it is rebuildable from the journal by definition, and a nightly check **reports** drift rather than repairing it - because silent repair hides the bug that caused it.

---

### PF-185 · Statement of Profit or Loss and OCI — mostly done
`Reporting` · **5 pts** · depends: PF-184 · standards: IAS 1 · `type:compliance`

- [x] By nature and by function, comparative, with OCI presented separately
- [x] Translation differences land in OCI, not in profit or loss — the reserve account and the presentation exist; consolidation fills it (`PF-194`)
- [x] Gross margin, operating result and net result as derived subtotals defined by the classification, not hard-coded
- [ ] Drill-down to account, then to journal line, then to source document

`App\Tenant\Reporting\Services\StatementOfProfitOrLoss` ·
`App\Tenant\Ledger\Enums\{ExpenseNature,ProfitOrLossBand}` ·
`bin/verify reporting`

**Two analyses, because IAS 1.104 requires both.** 1.99 lets an entity
present expenses by nature or by function; 1.104 says one presenting by
function must *also* disclose them by nature. So this is not a preference: a
product carrying only `statement_line` cannot produce a compliant set of
statements, and the gap surfaces at an audit rather than in the code.

Neither axis follows from the other. A production supervisor's salary is
`employee_benefits` by nature and `cost_of_sales` by function; a
bookkeeper's is the same nature under `administrative_expenses`. The two
agree only in their total — which is asserted, because two analyses of one
set of expenses reaching different totals is the clearest evidence available
that an account is classified wrong on one axis.

**The subtotals are band boundaries, not formulas.** Writing gross margin as
`revenue - cost_of_sales` would be a second definition of the statement's
shape, and the first line added to the wrong side of it produces a subtotal
that is wrong and a total that is right — the hardest arithmetic error to
see, because the bottom still agrees with the ledger.

**Every line is presented as its contribution to the result**, income and
expense alike, so each subtotal is a plain sum. The first version applied
the section's presentation sign, which is right for the statement of
financial position — where a liability is shown positive under a heading
that says "Liabilities" — and wrong here, where everything is added into one
number.

Three of the eight bites found a hollow check rather than a defect, and all
three were the fixture's fault. The by-nature total was negated twice and
the comparison passed, because the fixture's bill equalled its invoice and
the result was exactly zero — which no sign error changes. The movement
check issued two invoices and asserted the result moved, which a product
reading closing *balances* passes just as happily; it now reports on a
window that opens after the posting, where the movement is nil and the
balance is not. And the OCI check asserted only the order of the bands,
which proved nothing until there was an account OCI could reach: the
translation reserve exists now, and the check posts to it through the real
import path and asserts the two totals differ by exactly that amount.

Posting it also found two guards working. A bill is entered as a draft and
reaches the ledger only when posted, so the first fixture produced a
statement with an empty expense side; and a manual entry to a control
account is refused, because a control account's balance is the total of a
subledger and a manual posting makes the two disagree.

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

- [x] Every blocking check runs and reports all of them at once rather than failing on the first
- [ ] Blocking items — **done**: unposted documents, unreconciled bank accounts, non-zero suspense, unexplained tax difference, read-model drift. **Open**: aged in-transit items, failed recurring occurrences, three-way-match exceptions, inter-entity residuals
- [ ] Each item links directly to the screen that resolves it
- [ ] A controller may override a named item with a reason; the override is audited and appears on the compliance report
- [ ] The checklist state is retained per period, so "what did we accept at the time" is answerable

`App\Support\Closing\ClosingChecks` ·
`App\Tenant\Ledger\Closing\{ReadModelDriftCheck,UnpostedEntriesCheck}` ·
`bin/verify reporting`

**Every reason at once, and a check that throws is itself a reason.** A
close failing on the first problem is found three times by the same person
on the same afternoon. And a period whose checks nobody could *compute*
must not close: the alternative is a close that succeeded because something
was broken.

**Two ledger blockers added, and neither can be signed off.** Unexplained
money in suspense is a fact about the world that a close cannot always
resolve — a bank will not say who sent a transfer — so that one has an
escape. A drifted balance and an unfinished draft are facts about *this
system*, each with a one-command fix, and there is no circumstance in which
closing over them is the better of two options.

A draft invoice has **no journal entry at all** until it is issued, so the
first version of the unposted check — which read `journal_entries` alone —
could not see the thing most likely to be left unfinished. The criterion
says "unposted documents" and means it: invoices, bills and journal
entries.

Three of the seven checks were wrong before the code was. One asserted a
*soft* close was refused and reported the product as broken; the state
machine consults the registry on the way to `Closed` only, and the comment
says why. One drifted a balance on an entity that had never posted, so
`account_balances` had no row to drift. And one asked about April with a
draft dated mid-May, where `<` and `<=` give the same answer — so the bite
sweep changed the period bound and nothing failed. It now drafts on the
first day of the *next* period, which is the only date that tells the two
apart.

> **Still open, and why.** The remaining blocking items need services that
> are themselves unfinished — inter-entity residuals wait on PF-195. The
> link-to-screen criterion changes `ClosingCheck::reasonsToRefuse` from
> returning sentences to returning structures, which ripples through three
> modules that implement it; worth doing, not worth doing half. The
> override and the retained state need a table, and the honest place for it
> is beside that interface change.

### PF-197 · Export
`Reporting` · **5 pts** · depends: PF-183 · standards: ISO 19005, RFC 4180 · `type:security`

- [ ] **XLSX with live formulas and number formats intact** rather than flattened text, so an accountant can keep working in it
- [x] CSV as UTF-8 with BOM, RFC 4180 quoting, and **formula-injection neutralization on every cell** beginning with `=`, `+`, `-`, `@`, tab or carriage return
- [ ] PDF/A for archiving, veraPDF-verified
- [ ] JSON through the API with the same figures, from the same query
- [ ] Arabic exports carry correct encoding, direction and digit shape; an XLSX opened in Excel shows RTL columns in the right order
- [ ] A large export is queued and delivered as a signed download, never rendered in a request

`App\Tenant\Reporting\Export\CsvExport` · `bin/verify reporting`

**The CSV half is done and is the security half.** A cell beginning with
`=`, `+`, `-`, `@`, a tab or a carriage return is a *formula* to Excel,
LibreOffice and Sheets. A customer called `=cmd|'/c calc'!A1` has not broken
this product — they have planted something in it, and it fires on the
accountant who exports the party list. Nothing in the ledger was ever wrong,
which is why no ledger check can see it.

Quoting is not the fix, and the checks prove they know that: a quoted
`"=1+1"` is still a formula, because the quotes belong to the CSV reader and
are gone before the spreadsheet sees the cell. So the apostrophe goes
*inside* the quoting, and the checks assert what a cell starts with after
quoting is stripped.

The character is prefixed, never removed. A supplier genuinely called
`Alpha-Beta` exporting as `Alpha Beta` costs somebody an afternoon
reconciling two systems that disagree about a name.

> **Still open, and why.** XLSX with live formulas and PDF/A both need an
> engine this repository does not vendor, and the PDF driver is still a stub
> (`bin/verify wiring` asserts it). JSON through the API waits on PF-217.
> Queued delivery waits on the export being large enough to need it — there
> is no report yet that cannot be rendered in a request, and queueing one
> that can is machinery with no failure to prevent.

### PF-198 · Reproducibility
`Reporting` · **3 pts** · depends: PF-197 · standards: — · `risk:high`

- [x] Every report takes an explicit as-of basis, and the same parameters return the same numbers
- [x] The parameters - entity, period, basis, currency, adjusting-entry treatment, as-of timestamp - are **printed on the output**
- [ ] A report run against a closed period is byte-stable, and a test asserts it by running one twice across a data change in a later period
- [ ] A saved report definition can be re-run by anyone with permission and produce the same artefact

`App\Tenant\Reporting\Export\ReportBasis` · `bin/verify reporting`

**The parameters travel with the figures**, because two people run "the
trial balance" and get different numbers: one included the adjusting period,
or asked as at the 30th, or is looking at a presentation currency. Every one
of those is a correct answer to a different question, and a page showing only
figures gives the two of them no way to discover which question each asked.

`as_of` is a parameter and never `now()`. A report that read the clock would
be unreproducible by construction, and the difference would surface weeks
later as a changed row with nothing to explain it.

**The digest hashes the parameters, not the data** — deliberately. Two runs
with the same digest asked the same question, and return the same numbers
while the period is closed, which is the only guarantee that is true. A hash
of the figures would say two reports agree without saying they are
comparable.

> **Still open:** byte-stability across a later-period data change needs the
> export artefact itself, which waits on the XLSX and PDF halves of PF-197;
> and a saved report definition needs somewhere to save it.
