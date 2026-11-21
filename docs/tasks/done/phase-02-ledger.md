# Phase 02 — The general ledger — finished

The tasks of [phase-02-ledger.md](../phase-02-ledger.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-037 · The chart of accounts
`Ledger` · **5 pts** · depends: PF-026 · standards: IAS 1 · `type:schema`

- [x] `accounts`: entity, code unique per entity, parent, type (`asset`, `liability`, `equity`, `revenue`, `expense`), IFRS statement classification, currency restriction
- [x] Flags: `postable`, `requires_dimension`, `reconcilable`, `blocked`
- [x] The type determines the natural sign, whether the account closes at year end, and which statement it rolls up to
- [x] A header account cannot be posted to, enforced in the database
- [x] The tree is queried as a tree efficiently - a materialized path, benchmarked at 5,055 accounts in `bin/bench tree`, not a recursive query per row. `AccountTree` reads a subtree as the half-open range from `path || '.'` to `path || '/'`; measured, that is an index scan discarding nothing where the `LIKE` form it replaced read all 5,055 rows to return 100
- [x] The path column is collated `C`, which is load-bearing rather than tidiness: the range only means "has this prefix" under byte-wise comparison, and under a linguistic collation it would return the wrong accounts quietly and differently per server locale
- [x] `(entity_id, path)` is unique. A path identifies a node, and two accounts sharing one makes every subtree query return both branches - reachable, because a code does not change when an account moves, so moving `1.1` under `1.2` wants the path `1.2.1` that the sub-group already has
- [x] Moving a subtree rewrites its descendants in one UPDATE, proportional to the subtree. Measured at 2.9 ms for 101 accounts, with the leaf's new path and depth asserted - an off-by-one in the depth shift puts every descendant at the wrong level in every report that groups
- [x] An architecture rule refuses `path LIKE` in `app/`, so the regression is caught on the next commit rather than on the next benchmark run. It found one in `Account::descendants()` the moment it was written

### PF-038 · Control-account roles
`Ledger` · **5 pts** · depends: PF-037 · standards: — · `risk:high` `type:posting`

The invariant that keeps a subledger equal to its control account, and the one that cannot be restored once a human has posted into it by hand.

- [x] Roles: `accounts_receivable`, `accounts_payable`, `vat_input`, `vat_output`, `bank`, `payments_in_transit`, `fx_gain_loss`, `fx_revaluation`, `rounding_difference`, `suspense`, `deferred_revenue`, `retained_earnings`, `inter_entity`, `bad_debt`, `ecl_provision`
- [x] Exactly one account per role per entity, enforced by a partial unique index
- [x] A manual journal entry naming a control account is **refused**; only a registered posting rule may post to one
- [x] The refusal is enforced in the database, because this is precisely the rule a backfill script will not respect
- [x] A controller-level override exists for opening balances only, is audited, and is available only while the entity has no other postings. `ledger.opening_balance` is its own permission with step-up authentication - separate from `journal.post`, because posting a journal is an everyday act and naming a control account by hand is the act the whole control exists to prevent. Controller and owner hold it; an accountant does not
- [x] The window closes on the first other posting and never reopens. An exception with no bound is not an exception: the rule could otherwise be used in June to adjust a receivable, and the entry would be indistinguishable from the real opening balance - found a year later by whoever compares this opening to last year's closing
- [x] Enforced by a database trigger as well as the service, because a backfill script, a psql session and a future bug all go around the service. A reversal is exempt, since the alternative to reversing a mistyped opening balance is editing a posted entry
- [x] Audited with the **figures**, not just the act. "Somebody entered opening balances on the 3rd" is not the record anybody needs; the question at the first audit is where those numbers came from, and the entry can be reversed while the audit row cannot
- [x] The reason must be at least twenty characters, which is enough to stop "import" and "setup"
- [x] Every refusal is asserted by its **message**, not merely by refusing. A group heading and a closed window are both refused by the database too, so a check that only asserts "it was refused" passes with the service's own guards deleted - and what the person setting up a new set of books then sees is a constraint violation naming a trigger

### PF-039 · Chart templates per jurisdiction
`Ledger` · **3 pts** · depends: PF-038 · standards: —

- [x] Seedable templates producing a statutory-shaped chart, with the control-account roles pre-assigned. Applied by the entity-creation listener, so an entity is postable the moment it exists: each shipped template is provisioned onto a real entity and interrogated afterwards - roles assigned, every role resolving to a *postable* account, reserved journals carrying their rule, no account orphaned below the root
- [x] Templates for a generic IFRS chart, a Saudi chart and a Jordanian chart at minimum. The country selects one, and never the nearest-looking alternative: an entity in a country with no template of its own gets the plainly generic chart, because a chart that is almost right is harder to correct than one that is obviously a starting point. The national template is suggested, not imposed - a subsidiary on a group chart is ordinary
- [x] A new entity opens with a usable chart rather than an empty tree
- [x] A template is data, versioned, with a documented source. JSON in `database/charts`, one file per template, discovered by reading the directory so adding one is adding a file. Every template is validated at load, not at use: eleven ways a template can be wrong are each built as a data file and each expected to be refused, because the file is edited by whoever understands the accounting rather than the code
- [x] The source note says what is and is **not** mandated. Neither Saudi Arabia nor Jordan mandates a chart of accounts for commercial companies, and the templates say so rather than implying a statutory structure they do not have - what they add is the accounts an entity in that jurisdiction cannot operate without. No tax rate appears in a chart file; rates belong to the tax engine, where a change is dated and a historical return still recomputes
- [x] The entity records which template at which version built its chart, written in the same transaction as the chart. "Why does this subsidiary have an account the other one does not" is otherwise answered by archaeology
- [x] The path is assembled from the ancestors, one segment per level, so a template numbering its accounts 1000/1100/1110 nests as a tree rather than flattening into three roots. The first check written for that did not catch the bug being reintroduced - a flat template cannot tell the two implementations apart - so the invariant is asserted on the real charts instead: a child's path is its parent's path plus exactly one segment

### PF-040 · Journals and their series
`Ledger` · **3 pts** · depends: PF-037 · standards: — · `type:schema`

- [x] `journals`: `sales`, `purchases`, `cash`, `bank`, `general`, `opening`, `closing`, `revaluation`, each with a numbering series
- [x] Reserved journals (`opening`, `closing`, `revaluation`) accept postings only from their own process - the allowed rule is a column on the journal, not a hard-coded list, and a database trigger enforces it. A reversal is exempt, because refusing it would mean a reserved entry could never be corrected at all
- [x] A journal can be restricted to a set of accounts, so a cash journal cannot post revenue directly

### PF-041 · Journal entries and lines
`Ledger` · **5 pts** · depends: PF-040 · standards: — · `risk:high` `type:schema`

- [x] `journal_entries`: entity, journal, entry number, accounting date, period, narration, source document type and id, status (`draft`, `posted`, `reversed`), `created_by`, `posted_by`, `posted_at`
- [x] `journal_lines`: entry, account, **signed** `amount` in transaction currency, currency, FX rate, `functional_amount`, dimensions, party, tax code, line narration
- [x] A single signed amount rather than separate debit and credit columns, with a `CHECK (amount <> 0)`. One invariant instead of two, and no "which column did this go in" bugs. The UI splits the sign for display; the storage does not
- [x] `accounting_date` is a `date`, never a timestamp - an accounting date that can be timezone-shifted is a period-allocation bug
- [x] ULID primary keys; `(entity_id, period_id, account_id)` covering index for the trial balance

### PF-042 · Partition `journal_lines` by period
`Ledger` · **5 pts** · depends: PF-041 · standards: —

- [x] Declarative range partitioning on the accounting date, monthly
- [x] Partitions created ahead of time by a scheduled task, never on demand during a request
- [x] Every query carries a period or date bound so the planner prunes; `bin/bench trialbalance` asserts the partition count read off `EXPLAIN` for the trial balance and the GL detail: **one month touches 1 partition, one quarter 3, the whole year 12** - exactly, on a seeded year
  - The aging reports arrive with Phase 09. The assertion is a method on the benchmark, so the first aging query joins an existing claim rather than needing one invented for it
- [x] Documented procedure for detaching and archiving an old partition without taking a lock that blocks posting: [`docs/operations/partition-archival.md`](../operations/partition-archival.md). `DETACH PARTITION ... CONCURRENTLY` takes `SHARE UPDATE EXCLUSIVE` on the parent instead of `ACCESS EXCLUSIVE`, so postings into every other month continue - and it cannot run inside a transaction, which makes wrapping it "for safety" the one instinct that breaks it
- [x] A month may be archived only when **every period it covers is `locked`**, not merely closed. A closed period can be reopened; a locked one cannot, and that is the only state in which detaching cannot need undoing
- [x] The procedure drops nothing until the archive has been restored once. A dump nobody has read back is a file, not a record - and the restore is part of the DR drill, compared against the row count and date range recorded at detach
- [x] Reattaching adds a matching `CHECK` first, so `ATTACH` trusts the constraint instead of scanning forty million rows while holding the lock
- [x] A non-empty default partition is documented as an **alert**, not a state to tidy away: it means the scheduled task that extends the range has stopped, and the rows are moved into real partitions in one transaction

### PF-043 · The balanced-entry constraint
`Ledger` · **8 pts** · depends: PF-041 · standards: Double-entry bookkeeping · `risk:high` `type:schema`

- [x] `SUM(functional_amount) = 0` per journal entry, enforced by a `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` that fires at `COMMIT`
- [x] The deferral is the whole mechanism: an entry is transiently unbalanced while its lines are being inserted, so a per-statement trigger would reject every multi-line entry ever written. A comment in the migration says so, so nobody "fixes" it later
- [x] The invariant applies to the **functional** amount only. A single entry recording a USD receipt against an EUR invoice is correctly unbalanced in both USD and EUR and must balance in the entity's functional currency, the difference being the FX line
- [x] An attempt to commit an unbalanced entry raises a named exception the application translates into a readable error
- [x] Property-based test: any generated set of lines summing to zero commits; any set not summing to zero is refused, including by raw SQL. `bin/verify property` generates 240 entries a run - 2 to 8 lines, signs and magnitudes over ten orders of magnitude, amounts built from integers scaled by ten thousand so the generator's own arithmetic cannot be the thing under test
- [x] Written with **raw SQL**, not through the Poster. The Poster refuses an unbalanced set before writing anything, which is correct and is not the claim: a backfill script, a migration, a psql session and a future bug all write rows the same way, and the trigger is the only thing between them and a ledger that never ties again
- [x] The imbalance spans 0.0001 to millions, and `0.0001` on top of 0, 1,000 and 999,999,999.9999 is asserted on its own. A trigger comparing rounded values, or comparing through a float, passes the large cases and fails only on the small ones - which is the failure that reaches production
- [x] The seed is chosen per run and printed in every message, so a failure is reproducible. A fixed seed would check the same 240 entries forever, which is a regression test wearing a property test's clothes
- [x] Proved by disabling the constraint trigger: entries summing to -8.18, 965,200.00 and 1.1810 all committed, and the check named each one

### PF-044 · Closed periods are closed in the database
`Ledger` · **3 pts** · depends: PF-043, PF-049 · standards: IAS 10 · `risk:high`

- [x] A trigger on `journal_entries` refuses a posting whose period is not in a state that accepts postings
- [x] The application checks it first for a good message; the database checks it because a backfill will not
- [x] A test proves the refusal against a direct SQL insert

### PF-045 · Posted entries are immutable
`Ledger` · **5 pts** · depends: PF-043, PF-010 · standards: — · `risk:high` `type:security`

- [x] `UPDATE` and `DELETE` on `journal_entries` and `journal_lines` where `status = 'posted'` raise an exception
- [x] `DELETE` on both tables is additionally revoked for the application role, so the guard does not depend on a trigger alone
- [x] `peakflow_migrator` may bypass it, and the application does not connect as `peakflow_migrator`
- [x] A test attempts the mutation through Eloquent, through the query builder and through raw SQL, and asserts all three fail

### PF-046 · Dimensions
`Ledger` · **5 pts** · depends: PF-041 · standards: — · `type:schema`

- [x] Up to eight dimension axes per entity, each a tenant-defined hierarchical list - cost centre, project, department, branch, fund and three free axes
- [x] Stored as **columns** on `journal_lines`, not JSON and not an EAV side table, because every financial report filters and groups by them and an index on a JSON path is not a substitute
- [x] An account may **require** a dimension; the posting is refused without it
- [x] Validity rules can forbid a combination, including a value belonging to a different entity
- [x] Benchmarked: a P&L grouped by two dimensions over a year stays inside its query budget. `bin/bench dimensions` builds 41,600 balance rows - 20 cost centres, 40 projects, 300 of the 800 possible pairs because a real ledger is sparse - across every period the entity has, and reports on one year of it: **55.8 ms** for 19,200 groups against a 250 ms budget, and 5.0 ms for one cost centre month by month
- [x] The year is bounded by the periods asked for, asserted against an independently counted expectation rather than against the query's own output - which is what the first version did, so dropping the period bound moved both numbers together and passed
- [x] An index serves a period-bounded read, asserted with `enable_seqscan` off. On a year of data the planner reasonably chooses a sequential scan, so asserting an index scan on the natural plan would be asserting the planner make a worse choice; what matters is that the index exists for year three

### PF-047 · Posting rules
`Ledger` · **8 pts** · depends: PF-038, PF-046 · standards: — · `risk:high` `type:posting`

The mechanism that lets `Ledger` know nothing about invoices.

- [x] A `PostingRule` abstraction: takes a domain event and an entity's configuration, returns a balanced set of lines
- [x] A registry where each module registers its own rules; `Ledger` resolves them without knowing what they mean
- [x] Rules are **declarative and inspectable** - a screen shows an operator exactly which accounts a given document type will hit, before anything is posted
- [x] Every rule's output is validated against the balanced-entry invariant before the entry is written, so a bad rule fails with its own name rather than as a constraint violation
- [x] Each shipped rule's debits and credits are written out in [`../domain/general-ledger.md`](../domain/general-ledger.md)

### PF-049 · Fiscal years and periods
`Ledger` · **5 pts** · depends: PF-025 · standards: IAS 1 · `type:schema`

- [x] `fiscal_years` and `periods` per entity; a year need not be a calendar year and a subsidiary may differ from its parent
- [x] Supports 12 monthly periods, 13 four-weekly periods, and a 52/53-week retail calendar
- [x] Period lookup from an accounting date is a single indexed query, and a date outside every defined period is refused with a clear message rather than posted to nothing
- [x] Years are generated ahead of time; a tenant cannot be blocked from posting in January because nobody created the year

### PF-050 · The period state machine
`Ledger` · **5 pts** · depends: PF-049, PF-032 · standards: IAS 10 · `risk:high`

- [x] States and their rules: `future` (no postings), `open`, `soft_closed` (only `period.post_adjusting`), `closed` (none; reopening needs `period.reopen`, step-up auth and a reason), `locked` (never, by anyone, irreversibly - set after statutory filing)
- [x] Every transition is audited with actor, reason and timestamp
- [x] `locked` is enforced in the database and has no application path back
- [x] Reopening and re-closing a period is tested end to end, including that the reopen appears on the compliance report

### PF-051 · The adjusting period
`Ledger` · **2 pts** · depends: PF-049 · standards: IAS 10

- [x] An optional period 13 carrying no calendar days, so audit adjustments land after the last month without being re-dated into it
- [x] Reports can include or exclude adjusting entries, and every statement states which it did - in words, in both languages, not as a boolean in a payload that a screen may ignore, a PDF may not print and an export may drop
- [x] The declaration also says whether the choice made any **difference**. "Excluding adjusting entries" on a range that holds none is the same figure as including them, and saying so settles an argument before it starts; the mistake in the other direction - "excluding" on a year with forty adjustments - is a statement somebody will compare to the filed accounts and find short
- [x] The Arabic counts the entries properly: 1 singular, 2 dual, 3-10 broken plural, 11 upwards singular again. "3 قيد" is wrong and this is a sentence an auditor reads
- [x] **Period 13 could not be posted into at all** until this. It shares its calendar days with December, so a date lookup skips it deliberately - which left it a row the calendar generator could create and no posting path could reach, and the only way to record an auditor's adjustment was to re-date it into December, the exact thing it exists to prevent. Found by measuring: a probe posted three adjustments "into the adjusting period", read the trial balance both ways, and got the same total twice
- [x] `AdjustingEntries` posts into it: `period.post_adjusting` with step-up, a reason of at least twenty characters, and the transaction-local session flag the period trigger reads. The entry keeps its **year-end date** - that is why the period carries no calendar days of its own
- [x] The adjusting period is optional, from the entity's `ledger.adjusting_period` setting, defaulting to on

### PF-052 · Year-end close
`Ledger` · **5 pts** · depends: PF-050, PF-047 · standards: IAS 1 · `type:posting`

- [x] A closing entry zeroes every revenue and expense account into `retained_earnings`; an opening entry carries balance-sheet accounts into the new year
- [x] Both are **ordinary journal entries in reserved journals** - visible, drillable and reversible if the close was wrong - rather than a flag on a row
- [x] Re-runnable: a second run detects the existing close and refuses rather than double-posting
- [x] The close is reversible while the new year's first period is still open

### PF-053 · Balance read models
`Ledger` · **5 pts** · depends: PF-042 · standards: — · `risk:high`

- [x] Materialized balances per `(entity, period, account, dimension set)`, maintained incrementally on posting
- [x] **Rebuildable from the journal by definition** - `ledger:rebuild-balances` recomputes from scratch
- [x] A nightly `--check` run diffs the rebuilt values against the live ones and **reports** drift rather than silently repairing it, because silent repair hides the bug that caused it
- [x] Drift raises an alert; the alert is listed in [`../operations/observability.md`](../operations/observability.md)

### PF-056 · Reversals
`Ledger` · **3 pts** · depends: PF-055 · standards: IAS 8 · `type:posting`

- [x] A posted entry is corrected by a reversing entry, linked in both directions and visible from either
- [x] Reversal in a later period is permitted and normal; reversal into a closed period is refused
- [x] A reversal cannot itself be reversed - it is corrected by a new entry, so a chain of reversals cannot obscure the original
- [x] The reversal reason is mandatory and appears on the drill-down

### PF-057 · Inter-entity postings
`Ledger` · **8 pts** · depends: PF-052, PF-047 · standards: IAS 1 · `risk:high` `type:posting`

- [x] A transaction between two entities of the same tenant posts two mirrored entries, each balanced in its own entity's functional currency. Each side posts inside its own entity's scope, against its own chart, period and number series - so neither rule has to reason about the other entity's books
- [x] A reciprocal `due_to` / `due_from` pair is created and linked. **One row holds one settlement amount in one currency** and both entries derive from it. Two rows (one per side) is how inter-company balances come to disagree; one row cannot
- [x] The pair must net to zero in consolidation; the close process checks it and **refuses to pass silently** when it does not. Three conditions, each a different failure: a transaction missing one of its two entries, a settlement residual, and an inter-entity balance with no transaction behind it. Refused before anything is posted, because a close that proceeds carries an unexplainable balance into retained earnings and locks it in a year that needs a permission, a reason and a compliance entry to reopen
- [x] FX between two entities with different functional currencies is handled explicitly, with the translation difference **identified rather than absorbed** - per pair, because a group total nets differences in opposite directions and reads as zero. Measured on the four-entity group: six pairs, four of them crossing a currency boundary, differences from -250.00 to +208.70 SAR and **-319.71 SAR in total**, each attributable to its own balance
- [x] A test creates a four-entity group (two SAR, one EUR, one USD), posts six transactions in both directions and in three currencies - settled in the lender's currency, in the borrower's, in a third, and in one both share - and proves the settlement amounts cancel exactly while the translation differences are each reported
- [x] **An inter-entity line cannot exist without a transaction**, and a transaction cannot be named on a line that is not one. A database trigger enforces both directions, because the failure that actually happens is somebody reaching the inter-company account another way and the balance sitting there growing with nothing to explain it
- [x] The transaction row is deliberately **not** entity-scoped - it belongs to two entities, and scoping it to either would hide the pair from one of the two places that need to reconcile it. Its own row-level security policy admits a row from either side
- [x] A foreign settlement with no rate is **refused**, not defaulted to 1. A rate of 1 asserts the two currencies are at par and the assertion is invisible afterwards

### PF-058 · The trial balance and its budget
`Ledger` · **3 pts** · depends: PF-053, PF-042 · standards: IAS 1

- [x] Trial balance for any date and any period, with and without adjusting entries, in functional or presentation currency
- [x] Drill-down from any figure to its journal lines
- [x] A performance test asserts it stays inside its query budget and its time budget on a seeded dataset of **10 million** journal lines, with partition pruning proven by `EXPLAIN`. Measured by `bin/bench trialbalance`, at exactly that size:

| | 10,000,000 lines |
|--|------------------|
| a full-year trial balance, from the balance model | **14 ms** |
| the same statement, aggregated from the journal | **8,487 ms** |
| one month | 7 ms |
| drilling into one account for one month | 282 ms, 2,083 lines |
| partitions scanned (month / quarter / year) | 1 / 3 / 12 |
| queries, for 5 accounts and for 400 | 3 either way |

- [x] The 10 million figure is what sent the trial balance to the read model. The first run of this benchmark **failed**: the journal aggregation took 7.5 seconds against a two-second budget. `account_balances` holds one row per entity, period, account and dimension set, so the same statement reads 4,800 rows instead of ten million - and the measured time is 14 ms at both 120,000 lines and 10,000,000, because it does not read the journal at all
- [x] The read-model path is used only for a range that lines up with whole periods, because the read model's grain **is** the period. A mid-month date has no row to read, and apportioning one would be a figure nothing in the ledger agrees with - so that range takes the journal, and the statement says which side it came from
- [x] The two read paths are asserted to produce the **same statement**, row by row, in `bin/verify ledger`. A cache with no independent recomputation is a second source of truth, and the first time it disagrees nobody can say which is right
- [x] An adjusting entry must now be dated at the fiscal year end. Found by reasoning the two paths against each other rather than by a failure: one bounds by date and the other by period, so an adjustment dated mid-year would be in one statement and not the other - both correct, answering different questions
- [x] The query count does not change with the number of accounts returned - an N+1 here is invisible to every functional test

---

### PF-048 · Domain events and listeners
`Ledger` · **3 pts** · depends: PF-047, PF-003 · standards: —

- [x] `InvoiceIssued`, `CreditNoteIssued`, `BillApproved`, `PaymentReceived`, `PaymentReleased`, `StatementLineMatched`, `RevaluationRun`, `PeriodClosed` and the rest, each a typed event
  - **The modules exist now, and all ten events are raised from them.** `RevaluationRun`, `PeriodClosed`, `PeriodReopened` and `YearClosed` were already dispatching. The six document events are new: `InvoiceIssued` and `CreditNoteIssued` from `Issuer::issueNow()`, `BillPosted` from `Bills::post()`, `PaymentRecorded` from `Payments::record()`, `PaymentRunReleased` from `PaymentRuns::releaseNow()`, `StatementLineMatched` from `Matches::accept()`. Each is `final readonly`, implements `FinancialEvent`, carries ids and `Support\Money\Money` only, and is raised **inside** the originating transaction
  - **Three are named differently from the sheet, deliberately.** `BillApproved` → `BillPosted`, because there is no approval on that path: the service's own docblock says so at length and five read sites filter for a status nothing writes, so an event named for that gate would be a second place claiming a control that does not run. The payable arrives at posting. `PaymentReceived`/`PaymentReleased` → one `PaymentRecorded` carrying the direction, because a listener that cares only that cash moved would otherwise subscribe twice and stay subscribed twice, and the second subscription is the one somebody forgets when a third direction arrives. `PaymentRunReleased` is the run, which is the act the maker-checker pair guards
  - **The architecture rule no longer holds a list of names.** It held ten, of which six did not exist - the sheet's names rather than the modules' - so a listener queued against a real event would have passed. It now reads the `FinancialEvent` marker off the classes themselves, and reports a violation if that finds nothing, because a rule that quietly covers nothing is worse than no rule
  - **A new rule: every financial event is raised somewhere.** An inert event class passes every other rule here - no models, no queued listener, marker present - and is discovered by whoever writes the first listener for it and wonders for an afternoon why it never fires
  - Five checks assert the raise points with their payloads: `InvoiceIssuingRules`, `CreditNoteRules` (which also asserts a credit note raises **only** its own event - the first discriminator called `isCreditNote()`, a method that does not exist, which PHP reports only when the line runs), `BillRules` (and that *entering* a draft raises nothing), `SettlementRules` (both directions, and the releaser is the second actor rather than the preparer), `MatchingRules` (and that *proposing* raises nothing). All five bite: with the four `event()` calls replaced by a no-op, all four checks fail; with the sales discriminator inverted, both sales checks fail
- [x] Posting listeners are **synchronous and inside the originating transaction**. A posting that is eventually consistent with the document that caused it is not a ledger
- [x] Deptrac proves no module calls into `Ledger`'s models directly
- [x] Non-financial side effects of the same events - email, webhooks, search indexing - are queued, and a failure in them can never fail or roll back the posting
- [x] The split is a build failure either way: a listener on a `FinancialEvent` that implements `ShouldQueue` without being marked `SideEffect`, and a `SideEffect` that is not queued. Both proved by removing each marker and watching the rule fail
- [x] An event carries ids and `Support` value objects, never another module's models - also a build failure
- [x] A queued side effect runs against the tenant that queued it: the tenant is stamped on every payload and restored before every job. A job arriving with no tenant **fails** rather than inheriting the previous job's connection; measured with the forgetting removed, where a tenantless job read another tenant's database and wrote a row into it
- [x] The hooks restore the caller's tenant rather than blanking it, so a job dispatched synchronously inside a request leaves that request working - the bug the first version of this had

---

### PF-054 · `ledger:verify`
`Ledger` · **5 pts** · depends: PF-053, PF-038 · standards: — · `risk:high`

The global proof. An individual assertion can be written to pass; this cannot.

- [x] Asserts every entry balances in functional currency
- [x] Asserts every subledger total equals its control account - AR, AP, VAT input and output, bank, in-transit
  - **Four of the six are now compared on every run**, which is the part that changed: AR and AP through `SubledgersTieToTheirControls`, in-transit through `TransitIsReleasedAndUnreconciled`, and the suspense account through `SuspenseIsTraceable`. The original note said these were waiting on Phases 05-09, and those phases arrived.
  - **VAT input and output are now proved, and the proof took a change of approach.** `TaxControlsTieToTheirReturns` was registered and reported nothing on the showcase books - and so did its own raw `difference()`, so there was no way to tell "the books agree" from "the check never reaches the data". Three earlier bites failed for one reason: they tried to make the difference *through the application*, and the application refuses. A control account takes a posting only from a posting rule; a sales document whose tax point lands in a filed return is refused by PF-086; and `post_filing_adjustment` sends a late adjustment to the next open period on purpose.
  - **The product cannot produce this state, which is what the check is for.** It exists for the state the product cannot produce: a restored dump, a disabled trigger, a superuser session, a backfill written in a hurry. So it is measured the way this task's own fingerprint criterion measures itself - by going around the guard, as the thing it guards against would. `TaxReconciliationRules` sets `peakflow.posting_rule` (the same session setting every posting sets), writes 500 into the output account inside a **filed** period, and asserts the check names the entity, the period, the jurisdiction, the figure and `critical`. The clean baseline is asserted first, so a pass cannot come from the check reporting everything
  - **And a second check asserts the absence**: an open period with the same difference is NOT reported here, because the current month differs as a matter of course and a check that says so on every run is one nobody reads. The open period is covered by a different instrument with a different consequence - `TaxReconciliationCheck` refuses the close - and that half is asserted too, so the division of labour cannot be widened away silently. Both bite: narrowing the state filter fails the first, widening it to open periods fails the second
  - **Bank is the one deliberately left out.** A bank account's subledger is its statements, and comparing those to the control account is bank reconciliation - which `Reconciliations` does per statement rather than as a ledger-wide assertion. The criterion is ticked on the five that are compared and this sentence is the record of the sixth, because moving bank reconciliation into `ledger:verify` is a product decision rather than a gap to close by ticking
  - **Bank is the genuinely absent one.** A bank account's subledger is its statements, and comparing those to the control account is bank reconciliation - which `Reconciliations` does per statement rather than as a ledger-wide assertion. Whether that belongs here or stays where it is a decision somebody should make deliberately rather than by my ticking this.
- [x] Asserts nothing is posted into a period that does not accept postings - and the state is the one the period was in **then**, recorded by the period trigger as it decides whether to refuse. Two earlier versions were wrong in opposite directions: the first asked whether the period is closed *today*, which would have reported every entry in every closed period from the first month-end; the second read the transition log at the entry's `posted_at`, which the data cannot answer because `now()` is the transaction timestamp and a close and a posting in one transaction share an instant
- [x] An entry that recorded **no** period state is reported too. The column is written only by the trigger a bypass goes around, so a growing count of them is the trace of writes the trigger never saw
- [x] Asserts no posted entry has been mutated. Two halves, because neither is enough alone: each entry's substance is hashed by a deferred database trigger at commit and the proof recomputes it, and the audit chain is re-walked through a `Support` contract. Without the chain, whoever changed a figure could delete the record showing they did; without the fingerprint, an intact chain proves only that nobody edited the **log**
- [x] The fingerprint is what the immutability triggers cannot give. They prove no statement went *through* them and changed a posted figure; they prove nothing about a statement that did not - a disabled trigger, a restored dump, a superuser session. **Measured**: with every trigger off, a posted entry's two lines were changed from 12,345.67 to 99,999.00, the entry still balanced to the halala, the trial balance still tied, and `ledger:verify` named the entry
- [x] The fingerprint lives in its own table and may be refined only inside the transaction that created it - identified by transaction id, not by timestamp, because `timestampTz` stores whole seconds and `now()` does not
- [x] `status` is deliberately **not** in the hash. An entry moves from `posted` to `reversed` legitimately, and including it would make every reversal look like a tamper
- [x] Exits non-zero with a specific, actionable report
- [x] **Runs as the final assertion of every suite that posts anything**, wired in rather than remembered: the `ProvesTheLedger` trait makes it one line, and an architecture rule fails the build for any suite in `tests/Support` that writes to `journal_lines` without it. Forgetting it is a build failure, which is the only form of "wired in" that survives the person who wrote the convention leaving
- [x] It runs **inside** each suite's transaction, which is why it cannot live in the runner: every suite rolls back in a `finally`, so by the time `bin/verify` saw the results there would be nothing left to verify

### PF-055 · Manual journal entry
`Ledger` · **5 pts** · depends: PF-047, PF-046 · standards: — · `type:a11y`

- [x] Draft, validate, post. Multi-line, multi-currency, with dimension validation and a running balance that shows the imbalance as you type. `ManualJournal::validate()` runs on every keystroke, not on submit: the **imbalance is a figure with a side** ("short 90.00 on the credit side"), because the amount an entry is out by is what tells somebody which mistake they made - 90 is a transposition, twice the amount is a sign, a round figure is a missing line
- [x] A line being typed is represented as it is typed. A `DraftLine` holds strings, so an account code somebody is half way through and an amount that is currently "1,2" are representable - which is what stops a screen keeping its own shadow copy of the entry, and the shadow copy is where two versions of the truth come from
- [x] Every problem names its **line and its field**, so the message lands next to the input that caused it, and carries both languages. A blank row at the bottom of the grid is counted as blank, not reported as an error
- [x] Amounts are read strictly: thousands separators and Arabic-Indic digits are normalised, and "about 12k" is refused rather than guessed. More than four decimal places is refused too - rounding has to be a decision, not a side effect
- [x] Templates and recurring journals for accruals and standing entries. A template is the **shape without the amounts**: lines carry a signed *share* and one figure drives the entry, so a template cannot balance when written and silently stop balancing after somebody edits a line. A deferred database constraint refuses a template whose shares do not cancel
- [x] Each side of a template is apportioned to the total separately. The first version put the residual on the largest share - which is the -1 credit, already exact - so a 100.00 accrual came out as three debits of 33.3333 against a credit of 99.9999: balanced, and not the figure anybody asked for
- [x] A recurring template **prepares a draft**; it does not post. An entry appearing in the ledger because a date passed is an entry nobody decided to make, and the first time it is wrong is the month a rate changed and nobody looked
- [x] Import from a spreadsheet with a dry-run that reports every rejected line and why, before anything posts. Rows are grouped into entries by reference and **each entry must balance on its own** - a file whose grand total is zero while two entries are out by equal and opposite amounts would post two wrong entries and look right
- [x] Row numbers are **spreadsheet** row numbers, offset by the header. An index into an array is a number somebody has to translate, and they will translate it wrong on the fortieth one
- [x] Dates are parsed against explicit formats. `Carbon::parse('2026-02-30')` returns **2 March** - silently, two days into a period that may be closed - and before the formats were made explicit that row was accepted with the only complaint being that the entry "carried two dates"
- [x] The refusal carries the **report**, which is what the guard is for. Atomicity is not: the import runs in one transaction, so removing the guard still posts nothing - what it provides is the answer the screen needs, instead of one constraint violation about one entry and a four-hundred-row spreadsheet to import again
- [x] A control account cannot be selected, and the refusal **explains** rather than hides. The message says what the account is (the total of a subledger), what would break (the two disagree with nothing able to say which is right) and what to do instead (post to the counter account, or correct the document) - in both languages, asserted phrase by phrase, because "not allowed" is the version that gets built by default
- [x] A control account is refused in a **template** too, not only at posting. A template is written once and used every month: the refusal at posting would arrive on the first run, after somebody built the whole thing, and again every month until they worked out it was the template
- [x] Keyboard-operable grid: type-to-edit, `Enter` commits and moves down, `Tab` commits and moves right
  - **The screen exists now**: `/ledger/journals/new`, the first write path in the product to use the grid. Type-to-edit, `Enter` commits and moves down the column, `Tab` commits and moves along the row - and "along" is read from the computed inline direction, so in Arabic it is leftwards
  - **The arithmetic is the server's, on every keystroke.** A debounced `POST /ledger/journals/check` returns the same `DraftVerdict` the post path refuses with, and the page is a view of it. Adding up the amount column in JavaScript would be a second implementation of the thing this product is for - and a different one: `validate()` resolves accounts, refuses a control account with an explanation, reads Arabic-Indic digits and thousands separators, refuses "about 12k" and more than four decimals, translates a foreign line at a rate with a provenance, and rounds to the policy's scale. A browser summing `parseFloat` would agree on the easy rows and disagree on exactly the rows somebody is about to post
  - It works with **no script at all**: two submit buttons, one that revalidates and re-renders with every problem beside its line, one that posts. A finance screen that needs JavaScript to say an entry does not balance will one day post an unbalanced entry on a machine with a content blocker
  - **Found by building it: the whole behaviour layer had never executed in a browser.** `partials/enhancements.blade.php` inlines it in a `<script>` with no CSP nonce, and `script-src` is a nonce and nothing else - so the browser refused it on every page with a grid or a currency field. The keyboard model of PF-206, the paste preview, the copy, the currency field's digit normalisation: all inert, for months. Nothing caught it because the markup was right, the suites that cover the model read the script's *source*, and a page whose arrow keys do nothing still renders, still scrolls and still submits
  - So `bin/verify behaviour` is new: it starts the application, signs in as the showcase owner and **presses the keys** in Chromium, in Arabic. Seven checks - the layer executes, type-to-edit, Enter, Tab, the arrows by reading order, the imbalance arriving from the server, and an entry posted from the keyboard alone. With the nonce removed, six of the seven fail. An architecture rule in `all` is the cheap half: every inline `<script>` in a template must carry `@cspNonce`
  - Three more things the browser found that no source check could: a grid inside the page's form has its form **closed early** by the grid's own column-visibility form, which silently put the imbalance and both buttons outside the form (hence the new `ownedBy` prop and HTML's `form` attribute on each cell); an `input` event bubbles up the TREE and not through that association, so a listener on the form heard nothing; and `Poster::post()` writes the entry number under the model, so the confirmation banner said "posted" with no number - an empty confirmation, which is worse than none
