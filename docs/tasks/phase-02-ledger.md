# Phase 02 — The general ledger

**22 tasks · 104 points**

This is the phase that decides whether PeakFlow is a financial system or a database of documents. Everything after it posts into what is built here.

The organising idea is that **the ledger's invariants belong to the database**. An application can be bypassed by a console command, a migration, a future developer's REPL session or a bug in an ORM upgrade. A deferred constraint trigger cannot. Every rule in this phase that can be expressed in PostgreSQL is expressed in PostgreSQL, and the application layer checks it too only so that the error message is useful.

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

### PF-048 · Domain events and listeners
`Ledger` · **3 pts** · depends: PF-047, PF-003 · standards: —

- [ ] `InvoiceIssued`, `CreditNoteIssued`, `BillApproved`, `PaymentReceived`, `PaymentReleased`, `StatementLineMatched`, `RevaluationRun`, `PeriodClosed` and the rest, each a typed event
  - `RevaluationRun`, `PeriodClosed`, `PeriodReopened` and `YearClosed` exist and dispatch. The six document events belong to the modules that raise them - `InvoiceIssued` to Phase 05, `BillApproved` to Phase 07, `PaymentReceived` and `StatementLineMatched` to Phases 08-09 - and are named in the architecture rule now, so the first one added arrives already bound by it. Left open deliberately: ticking it here would claim events that have no module to be raised from yet
- [x] Posting listeners are **synchronous and inside the originating transaction**. A posting that is eventually consistent with the document that caused it is not a ledger
- [x] Deptrac proves no module calls into `Ledger`'s models directly
- [x] Non-financial side effects of the same events - email, webhooks, search indexing - are queued, and a failure in them can never fail or roll back the posting
- [x] The split is a build failure either way: a listener on a `FinancialEvent` that implements `ShouldQueue` without being marked `SideEffect`, and a `SideEffect` that is not queued. Both proved by removing each marker and watching the rule fail
- [x] An event carries ids and `Support` value objects, never another module's models - also a build failure
- [x] A queued side effect runs against the tenant that queued it: the tenant is stamped on every payload and restored before every job. A job arriving with no tenant **fails** rather than inheriting the previous job's connection; measured with the forgetting removed, where a tenantless job read another tenant's database and wrote a row into it
- [x] The hooks restore the caller's tenant rather than blanking it, so a job dispatched synchronously inside a request leaves that request working - the bug the first version of this had

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

### PF-054 · `ledger:verify`
`Ledger` · **5 pts** · depends: PF-053, PF-038 · standards: — · `risk:high`

The global proof. An individual assertion can be written to pass; this cannot.

- [x] Asserts every entry balances in functional currency
- [ ] Asserts every subledger total equals its control account - AR, AP, VAT input and output, bank, in-transit
  - `controlTotals()` reads the control-account side today. The other side does not exist yet: AR and output VAT arrive with Phase 05, AP and input VAT with Phase 07, bank and in-transit with Phases 08-09. Left open deliberately - a comparison against a table that is not there would pass by being vacuous, which is the worst kind of green
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

- [ ] Draft, validate, post. Multi-line, multi-currency, with dimension validation and a running balance that shows the imbalance as you type
- [ ] Templates and recurring journals for accruals and standing entries
- [ ] Import from a spreadsheet with a dry-run that reports every rejected line and why, before anything posts
- [ ] Keyboard-operable grid: type-to-edit, `Enter` commits and moves down, `Tab` commits and moves right
- [ ] A control account cannot be selected, and the UI explains why rather than hiding it

### PF-056 · Reversals
`Ledger` · **3 pts** · depends: PF-055 · standards: IAS 8 · `type:posting`

- [x] A posted entry is corrected by a reversing entry, linked in both directions and visible from either
- [x] Reversal in a later period is permitted and normal; reversal into a closed period is refused
- [x] A reversal cannot itself be reversed - it is corrected by a new entry, so a chain of reversals cannot obscure the original
- [x] The reversal reason is mandatory and appears on the drill-down

### PF-057 · Inter-entity postings
`Ledger` · **8 pts** · depends: PF-052, PF-047 · standards: IAS 1 · `risk:high` `type:posting`

- [ ] A transaction between two entities of the same tenant posts two mirrored entries, each balanced in its own entity's functional currency
- [ ] A reciprocal `due_to` / `due_from` pair is created and linked
- [ ] The pair must net to zero in consolidation; the close process checks it and **refuses to pass silently** when it does not
- [ ] FX between two entities with different functional currencies is handled explicitly, with the translation difference identified rather than absorbed
- [ ] A test creates a four-entity group, posts inter-entity transactions in both directions and in three currencies, and proves the eliminations net to zero

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
