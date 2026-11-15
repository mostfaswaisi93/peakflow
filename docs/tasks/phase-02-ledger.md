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
- [ ] The tree is queried as a tree efficiently - a materialized path or closure table, benchmarked at 5,000 accounts, not a recursive query per row

### PF-038 · Control-account roles
`Ledger` · **5 pts** · depends: PF-037 · standards: — · `risk:high` `type:posting`

The invariant that keeps a subledger equal to its control account, and the one that cannot be restored once a human has posted into it by hand.

- [x] Roles: `accounts_receivable`, `accounts_payable`, `vat_input`, `vat_output`, `bank`, `payments_in_transit`, `fx_gain_loss`, `fx_revaluation`, `rounding_difference`, `suspense`, `deferred_revenue`, `retained_earnings`, `inter_entity`, `bad_debt`, `ecl_provision`
- [x] Exactly one account per role per entity, enforced by a partial unique index
- [x] A manual journal entry naming a control account is **refused**; only a registered posting rule may post to one
- [x] The refusal is enforced in the database, because this is precisely the rule a backfill script will not respect
- [ ] A controller-level override exists for opening balances only, is audited, and is available only while the entity has no other postings

### PF-039 · Chart templates per jurisdiction
`Ledger` · **3 pts** · depends: PF-038 · standards: —

- [ ] Seedable templates producing a statutory-shaped chart, with the control-account roles pre-assigned
- [ ] Templates for a generic IFRS chart, a Saudi chart and a Jordanian chart at minimum
- [x] A new entity opens with a usable chart rather than an empty tree
- [ ] A template is data, versioned, with a documented source

### PF-040 · Journals and their series
`Ledger` · **3 pts** · depends: PF-037 · standards: — · `type:schema`

- [x] `journals`: `sales`, `purchases`, `cash`, `bank`, `general`, `opening`, `closing`, `revaluation`, each with a numbering series
- [ ] Reserved journals (`opening`, `closing`, `revaluation`) accept postings only from their own process
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
- [ ] Every query carries a period or date bound so the planner prunes; a test asserts pruning via `EXPLAIN` for the trial balance, the GL detail and the aging reports
- [ ] Documented procedure for detaching and archiving an old partition without taking a lock that blocks posting

### PF-043 · The balanced-entry constraint
`Ledger` · **8 pts** · depends: PF-041 · standards: Double-entry bookkeeping · `risk:high` `type:schema`

- [x] `SUM(functional_amount) = 0` per journal entry, enforced by a `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` that fires at `COMMIT`
- [x] The deferral is the whole mechanism: an entry is transiently unbalanced while its lines are being inserted, so a per-statement trigger would reject every multi-line entry ever written. A comment in the migration says so, so nobody "fixes" it later
- [x] The invariant applies to the **functional** amount only. A single entry recording a USD receipt against an EUR invoice is correctly unbalanced in both USD and EUR and must balance in the entity's functional currency, the difference being the FX line
- [x] An attempt to commit an unbalanced entry raises a named exception the application translates into a readable error
- [ ] Property-based test: any generated set of lines summing to zero commits; any set not summing to zero is refused, including by raw SQL

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
- [ ] Benchmarked: a P&L grouped by two dimensions over a year stays inside its query budget

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
- [ ] Posting listeners are **synchronous and inside the originating transaction**. A posting that is eventually consistent with the document that caused it is not a ledger
- [ ] Deptrac proves no module calls into `Ledger`'s models directly
- [ ] Non-financial side effects of the same events - email, webhooks, search indexing - are queued, and a failure in them can never fail or roll back the posting

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
- [ ] Reports can include or exclude adjusting entries, and every statement states which it did

### PF-052 · Year-end close
`Ledger` · **5 pts** · depends: PF-050, PF-047 · standards: IAS 1 · `type:posting`

- [x] A closing entry zeroes every revenue and expense account into `retained_earnings`; an opening entry carries balance-sheet accounts into the new year
- [x] Both are **ordinary journal entries in reserved journals** - visible, drillable and reversible if the close was wrong - rather than a flag on a row
- [x] Re-runnable: a second run detects the existing close and refuses rather than double-posting
- [x] The close is reversible while the new year's first period is still open

### PF-053 · Balance read models
`Ledger` · **5 pts** · depends: PF-042 · standards: — · `risk:high`

- [ ] Materialized balances per `(entity, period, account, dimension set)`, maintained incrementally on posting
- [ ] **Rebuildable from the journal by definition** - `ledger:rebuild-balances` recomputes from scratch
- [ ] A nightly `--check` run diffs the rebuilt values against the live ones and **reports** drift rather than silently repairing it, because silent repair hides the bug that caused it
- [ ] Drift raises an alert; the alert is listed in [`../operations/observability.md`](../operations/observability.md)

### PF-054 · `ledger:verify`
`Ledger` · **5 pts** · depends: PF-053, PF-038 · standards: — · `risk:high`

The global proof. An individual assertion can be written to pass; this cannot.

- [x] Asserts every entry balances in functional currency
- [ ] Asserts every subledger total equals its control account - AR, AP, VAT input and output, bank, in-transit
- [x] Asserts nothing is posted into a period that does not accept postings
- [ ] Asserts no posted entry has been mutated, by re-checking the audit chain
- [x] Exits non-zero with a specific, actionable report
- [ ] **Runs as the final assertion of every feature test that posts anything**, wired into the test case rather than remembered per test

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
- [ ] A performance test asserts it stays inside its query budget and its time budget on a seeded dataset of **10 million** journal lines, with partition pruning proven by `EXPLAIN`
- [x] The query count does not change with the number of accounts returned - an N+1 here is invisible to every functional test
