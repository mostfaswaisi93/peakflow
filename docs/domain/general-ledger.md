# The general ledger

The spine. Every financial fact in PeakFlow is a posting here, and every report is a query over it.

Three rules are structural rather than configurable:

1. **No financial fact exists outside the ledger.**
2. **Nothing posted is ever updated in place.** Corrections are reversals.
3. **Every balance is explainable** down to the journal line, the source document and the user.

## Chart of accounts

| Column | Notes |
|--------|-------|
| `code` | Unique per entity, sortable, and the thing accountants actually use |
| `type` | `asset`, `liability`, `equity`, `revenue`, `expense` — decides natural sign, year-end closing behaviour and which statement it reaches |
| `statement_class` | The IAS 1 line it rolls up to, so a statement is a grouping rather than a hand-built report |
| `control_role` | Nullable; see below |
| `postable` | A header account is not |
| `requires_dimension` | Which dimension axes are mandatory on a posting to it |
| `reconcilable` | Bank and control accounts |
| `currency_restriction` | Nullable; a foreign-currency bank account's GL mirror is restricted |
| `blocked` | Closed to new postings, retained for history |

### Control accounts

| Role | Holds | Posted by |
|------|-------|-----------|
| `accounts_receivable` | What customers owe | `Sales`, `Settlement` |
| `accounts_payable` | What is owed to suppliers | `Purchasing`, `Settlement` |
| `employee_payable` | Expense claims owed to staff | `Purchasing` |
| `vat_output` | Tax charged on sales | `Tax` |
| `vat_input` | Tax recoverable on purchases | `Tax` |
| `withholding_tax` | Tax deducted at source | `Tax`, `Settlement` |
| `bank` | One per bank account, 1:1 | `Banking`, `Settlement` |
| `payments_in_transit` | Released, not yet on a statement | `Settlement` |
| `unapplied_cash` | Received, not yet allocated | `Settlement` |
| `suspense` | Imported, not yet explained | `Banking` |
| `deferred_revenue` | Billed, not yet earned | `Sales` |
| `accrued_expenses` | Received, not yet billed | `Purchasing` |
| `fx_gain_loss` | Realized currency differences | `Settlement` |
| `fx_revaluation` | Unrealized, reversed each period | `Catalog` |
| `rounding_difference` | Settlement rounding | `Settlement` |
| `bad_debt` | Written off | `Settlement` |
| `ecl_provision` | IFRS 9 expected credit loss | `Settlement` |
| `inter_entity` | Due-to / due-from within the tenant | `Ledger` |
| `retained_earnings` | Year-end close target | `Ledger` |

**A manual journal entry naming a control account is refused** — only a registered posting rule may post to one, and the refusal is enforced in the database because this is precisely the rule a backfill script will not respect.

> This is the invariant that keeps a subledger equal to its control account — the reconciliation every month-end close starts with, and the one that is impossible to restore once a human has posted a manual adjustment into it. The single exception is opening balances, available only while the entity has no other postings, permissioned and audited.

### Reading the chart as a tree

The tree is a **materialized path**: `path` holds the chain of ancestor segments joined by `.`, and `depth` is how many separators are in it. There is no closure table and no recursive query.

A subtree is then a question about string order. Everything under `1.1` is exactly:

```sql
path >= '1.1.'  AND  path < '1.1/'
```

because `/` is the byte immediately after `.`. Both bounds land in the index condition, and the scan reads the subtree and nothing else.

**`LIKE 'path.%'` reads better and is a different plan.** Measured on 5,055 accounts, asking for the 100 under `1.1`:

| form | plan | time | rows examined |
|------|------|------|---------------|
| `path LIKE '1.1.%'` | no index on the path; `entity_id` only, then a filter | 1.75 ms | 5,055, of which **4,955 discarded** |
| `path >= '1.1.' AND path < '1.1/'` | index scan, both bounds in the condition | 0.30 ms | 100 |

The LIKE is not a slower route to the same plan — it reads every account in the entity on every roll-up. At 5,000 accounts that is 1.75 ms and looks fine; at 50,000 it reads 50,000 rows to return ten, in every report, forever. The prefix optimization does not rescue it either: `path` is `varchar` and the pattern operator takes `text`, so the comparison is against a cast and the planner derives no bounds — true even with a literal pattern.

So an architecture rule refuses `path LIKE` anywhere in `app/`, and it found one in `Account::descendants()` the moment it was written.

**Two schema facts the range depends on.** The column is collated `C`, because the range only means "has this prefix" under byte-wise comparison — under a linguistic collation, punctuation can be ignored at the primary level and the query would return the wrong accounts, quietly, and differently on a server installed with a different locale. And `(entity_id, path)` is unique, because a path identifies a node: two accounts sharing one makes every subtree query return both branches and every roll-up double-count one of them. That is reachable rather than theoretical — a code does not change when an account moves, so moving `1.1` under `1.2` wants the path `1.2.1` that the sub-group `1.2.1` already holds, and the move is refused with that explanation.

**The cost of a materialized path** is that moving a subtree rewrites its descendants' paths. It is one UPDATE over the range, so the work is proportional to the subtree: 101 accounts in 2.9 ms. Moving an account is rare and a chart is small, which is the trade this design makes — a cheap read every time in exchange for an expensive write almost never.

Measured by `bin/bench tree`, which takes the SQL from the service's own query builder rather than restating it, so the benchmark fails when the service regresses:

| | measured |
|--|----------|
| subtree of a group (100 of 5,055) | 0.30 ms, index scan, nothing discarded |
| subtree of a statement (1,011 of 5,055) | 1.0 ms, sequential — a fifth of the table, so that is the right plan |
| the direct children of a group | 0.23 ms |
| the ancestors of a leaf | 1.0 ms, one `whereIn` |
| rolling up a statement from the read model | 9.7 ms over 10,800 balance rows |
| moving a 101-account subtree | 2.9 ms |

### Where a chart comes from

A new entity does not get an empty tree. An empty chart is not a neutral starting point: the tenant has to invent one before they can record anything, what they invent is shaped by the first three transactions they happen to think of, and they cannot post at all until the control-account roles are assigned — which nothing tells them, because the way it is said is a posting failure naming one role at a time.

So provisioning applies a template, and the entity is postable the moment it exists.

**A template is data, not code.** One JSON file per template in `database/charts`, discovered by reading the directory, so adding a jurisdiction is adding a file. Three reasons, and the third decided it: a chart is argued about and an accountant can read and correct a data file; a template has a version and a source, so a diff is a readable history of a structure changing; and a tenant will eventually supply their own, for which a format already exists.

| Template | Serves | Accounts |
|----------|--------|----------|
| `ifrs-generic` | every country with no template of its own | 32 |
| `saudi-arabia` | `SA` | 42 |
| `jordan` | `JO` | 42 |

**The country suggests; it does not impose.** Every template stays selectable — a Saudi subsidiary keeping its books on a group's generic chart is an ordinary arrangement, and so is the reverse. What the selection never does is fall back to the *nearest-looking* national chart: an entity in a country with no template gets the plainly generic one, because a chart that is almost right is harder to correct than one that is obviously a starting point. A tenant who sees a Saudi chart assumes somebody checked it against Saudi requirements.

**The source says what is not mandated.** Neither Saudi Arabia nor Jordan mandates a chart of accounts for commercial companies. Saudi Arabia has a unified chart for government entities and it does not apply to a trading company; Jordan has none for private companies at all. The templates say so. What each one adds to the IFRS structure is the set of accounts an entity in that jurisdiction cannot operate without — the ones driven by obligations rather than by presentation:

| | Saudi Arabia | Jordan |
|--|--------------|--------|
| Indirect tax | VAT, administered by ZATCA | General sales tax, administered by the ISTD |
| On profit | Zakat **and** income tax — a mixed company apportions between the Saudi/GCC-owned share and the rest | Income tax, plus the national contribution certain sectors bear |
| Withheld at source | On payments to non-residents | On certain payments |
| Social | GOSI contributions | Social Security Corporation contributions |
| Employee benefit | End-of-service benefits (IAS 19, required by the Labor Law) | End-of-service benefits |

Two things deliberately absent from every chart file. **No rate**: rates change by regulation and belong to the tax engine, where a change is dated and a historical return still recomputes. **No inference**: `monetary` is required on every account, because IAS 21 restates monetary items at the closing rate and the classification cannot be derived — property and receivables are both assets, and only one of them moves.

The Jordanian GST accounts carry the **same control roles** as the VAT accounts elsewhere, which is the whole point of a role: the tax has a different name, a different rate and a different return in each jurisdiction, and not one posting rule has to know that.

**Every template is checked at load, not at use.** A parent that does not exist, a child written before its parent, one role on two accounts, a role on a group account nothing can post to, a subtree that changes statement, a postable account with children, a reserved journal naming no rule, a missing version, a source with no note: each is refused when the file is read. A template is edited by whoever understands the accounting rather than the code, so the read is the only useful place to catch a mistake — the alternative is a chart that is wrong in a way the tenant meets weeks later, with nothing pointing back at the file.

**The entity records what built it.** `chart_template`, `chart_template_version` and `chart_applied_at`, written in the same transaction as the chart and constrained to move together. "Why does this subsidiary have an account the other one does not" is otherwise answered by archaeology — and the version is the half that earns its place, because two entities provisioned a month apart may legitimately differ, which is a different situation from somebody having edited one of them.

## Journals, entries and lines

| Journal | Source |
|---------|--------|
| `sales` | Invoices, credit notes, debit notes |
| `purchases` | Bills, supplier credit notes, expense claims |
| `cash` | Cash receipts and payments |
| `bank` | Bank transactions and reconciliation |
| `general` | Manual entries, accruals, adjustments |
| `opening` | Reserved — year opening |
| `closing` | Reserved — year-end close |
| `revaluation` | Reserved — IAS 21 and IFRS 9 runs |

Reserved journals accept postings only from their own process.

An entry is `draft` → `posted` → optionally `reversed`. There is no `deleted`. A draft has no effect on any balance; **posting is the irreversible act**, and it stamps the period.

Each line carries a **signed** amount (debits positive, credits negative) in the transaction currency, the FX rate, and the functional amount — see [`ADR-0008`](../architecture/decisions/ADR-0008-signed-amount.md).

```
SUM(functional_amount) = 0   per entry, enforced by a deferred constraint trigger
```

The invariant applies to the **functional** amount only. An entry recording a USD receipt against a EUR invoice is correctly unbalanced in both USD and EUR; the difference is the FX line. See [`money-and-precision.md`](../architecture/money-and-precision.md#the-invariant-that-balances-in-one-currency-only).

## The posting rules

Every rule below is owned by `Ledger` and registered by the module that raises the event. `Ledger` does not know what an invoice is. Amounts are shown as **Dr** (positive) and **Cr** (negative); a rule's output is validated against the balancing invariant **before** the entry is written, so a bad rule fails with its own name rather than as a constraint violation.

### Sales

**`InvoiceIssued`** — standard invoice, 1,000 net, 150 tax

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `accounts_receivable` | 1,150 |
| Cr | revenue (per line's account) | (1,000) |
| Cr | `vat_output` (per rate group) | (150) |

**`InvoiceIssued`** — with a recognition schedule (IFRS 15)

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `accounts_receivable` | 1,150 |
| Cr | `deferred_revenue` | (1,000) |
| Cr | `vat_output` | (150) |

**`RevenueRecognized`** — releasing one period's share

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `deferred_revenue` | 250 |
| Cr | revenue | (250) |

**`PrepaymentInvoiceIssued`** — cash before performance is a liability, not revenue

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `accounts_receivable` | 1,150 |
| Cr | `deferred_revenue` | (1,000) |
| Cr | `vat_output` | (150) |

**`CreditNoteIssued`** — reverses the original through a rule, never by negating rows

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | revenue | 1,000 |
| Dr | `vat_output` | 150 |
| Cr | `accounts_receivable` | (1,150) |

**`InvoiceIssued`** — reverse charge (`AE`): no tax on the document, and a mandatory legal note

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `accounts_receivable` | 1,000 |
| Cr | revenue | (1,000) |

### Purchasing

**`GoodsReceived`** — optional accrual, which is what makes a period-end cutoff honest

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | expense or asset | 1,000 |
| Cr | `accrued_expenses` | (1,000) |

**`BillApproved`** — reversing any accrual

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | expense or asset (or `accrued_expenses` if accrued) | 1,000 |
| Dr | `vat_input` | 150 |
| Cr | `accounts_payable` | (1,150) |

**`BillApproved`** — reverse charge received: **both sides posted**, which is the half most systems forget

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | expense | 1,000 |
| Dr | `vat_input` | 150 |
| Cr | `vat_output` | (150) |
| Cr | `accounts_payable` | (1,000) |

**`BillApproved`** — non-recoverable input tax goes to expense, keeping `vat_input` reconcilable to the return

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | expense | 1,150 |
| Cr | `accounts_payable` | (1,150) |

**`ExpenseClaimApproved`**

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | expense | 300 |
| Dr | `vat_input` | 45 |
| Cr | `employee_payable` | (345) |

### Settlement

**`PaymentReceived`** — unallocated

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `bank` (that account's mirror) | 1,150 |
| Cr | `unapplied_cash` | (1,150) |

**`PaymentAllocated`**

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `unapplied_cash` | 1,150 |
| Cr | `accounts_receivable` | (1,150) |

**`PaymentAllocated`** — with a realized FX loss (IAS 21): invoice booked at 3,750 functional, payment worth 3,726

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `unapplied_cash` | 3,726 |
| Dr | `fx_gain_loss` | 24 |
| Cr | `accounts_receivable` | (3,750) |

**`PaymentAllocated`** — with a settlement rounding difference: paid 999.99 against 1,000.00

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `unapplied_cash` | 999.99 |
| Dr | `rounding_difference` | 0.01 |
| Cr | `accounts_receivable` | (1,000.00) |

**`PaymentAllocated`** — with withholding deducted at source: the shortfall is **not** a short payment

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `unapplied_cash` | 950 |
| Dr | `withholding_tax` | 50 |
| Cr | `accounts_receivable` | (1,000) |

**`PaymentRunReleased`** — AP moves to in-transit, which is what makes the three-way tie possible

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `accounts_payable` | 5,000 |
| Cr | `payments_in_transit` | (5,000) |

**`StatementLineMatched`** — the released payment appears on the statement

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `payments_in_transit` | 5,000 |
| Cr | `bank` | (5,000) |

**`ReceivableWrittenOff`**

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `bad_debt` | 1,000 |
| Dr | `vat_output` | 150 *(where the jurisdiction permits tax relief)* |
| Cr | `accounts_receivable` | (1,150) |

**`EclProvisionRun`** — reversed and recomputed each period, never accumulated

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | bad-debt expense | 4,200 |
| Cr | `ecl_provision` | (4,200) |

### Banking

**`StatementLineImported`** — unexplained, so it lands somewhere explicit rather than nowhere

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `bank` | 300 |
| Cr | `suspense` | (300) |

**`SuspenseCleared`** — a reclassification, never an edit of the original

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `suspense` | 300 |
| Cr | the resolved account | (300) |

**`BankChargeClassified`**

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | bank charges expense | 12.50 |
| Cr | `bank` | (12.50) |

### Period-end and year-end

**`ledger.fx_revaluation`** (IAS 21) — monetary items only, reversed and recomputed each period

A customer owes **EUR 10,000**, invoiced at **4.00** and so booked at SAR 40,000. At the period end the rate is **4.10**, making the receivable worth SAR 41,000.

| Dr/Cr | Account | Amount | Currency | Rate |
|-------|---------|--------|----------|------|
| Dr | `accounts_receivable` | 1,000.0000 | **SAR** | *none* |
| Cr | `fx_revaluation` | (1,000.0000) | **SAR** | *none* |

Three things about those two lines are easy to get wrong.

**The lines are in the functional currency and carry no rate.** This is not a transaction in euro: the exposure has not changed, the customer still owes exactly EUR 10,000. What changed is what those euro are worth in riyal, and that difference is a riyal amount with no euro counterpart. Posting it as a EUR line with a rate would claim a further EUR amount arose - making the euro balance wrong in order to make the riyal balance right. `assert_line_translation()` refuses a rate on a functional-currency line, so the mistake is unrepresentable rather than discouraged.

**One line to the revaluation account, not one per account.** A line per account makes the revaluation account's own history fifty entries per close where one belongs, and the figure that reaches profit or loss is the total.

**Monetary items only, and deferred revenue is not one.** It is settled by delivering goods and services, not by paying money, so it is never restated - the account most often wrongly revalued. Property, inventory, prepayments and equity are likewise non-monetary. Suspense is excluded for a different reason: it holds items nobody has identified, and revaluing an unidentified item asserts a classification that has not been made.

The classification is a column on the account (`accounts.monetary`) because it cannot be derived - property and receivables are both assets, and a prepayment is both current and non-monetary. An account holding a foreign balance with **no** classification is reported by `fx:revalue` and left alone; a default would be wrong in whichever direction it pointed.

**Re-running it inside an open period reverses the previous run in full and posts a fresh computation.** An unrealized difference is an opinion about today's rate; next period the rate differs and so does the opinion, and adding this period's to last period's gives a figure that is neither. Run it three times on three days and exactly one adjustment stands - and a database trigger refuses a run that supersedes another without reversing its entry, so that stays true of the data and not only of the code.

Each run keeps its workings - exposure, rate, rate date, booked value, restated value, difference per account and currency - because "why is there a 1,000 revaluation in March" is asked at every close, and reconstructing it from the journal means re-deriving the balances as they were at the time.

**`InterEntityPosted`** — two mirrored entries, each balanced in its own entity's functional currency

*Entity A:*

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `inter_entity` (due from B) | 10,000 |
| Cr | revenue | (10,000) |

*Entity B:*

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | expense | 10,000 |
| Cr | `inter_entity` (due to A) | (10,000) |

The pair must net to zero in consolidation; the close process checks it and **refuses to pass silently** when it does not.

**`ledger.year_end_close`** — an ordinary entry in the reserved `CLS` journal, so it is visible, drillable and reversible

A year with revenue of **120,000** and cost of sales of **45,000**:

| Dr/Cr | Account | Amount | Dimensions |
|-------|---------|--------|------------|
| Dr | every revenue account | its credit balance (120,000.0000) | the ones that account **requires** |
| Cr | every expense account | its debit balance (45,000.0000) | the ones that account **requires** |
| Dr/Cr | `retained_earnings` | the net (75,000.0000 Cr) | — |

**An ordinary journal entry, not a flag on a row.** Three reasons, in the order they matter. It is **drillable**: "why is retained earnings 4.2 million" is answered by opening the entry and reading the lines, the same as any other figure, and a close implemented as a flag has no lines to read. It is **reversible**: a close run against a year with a misposted accrual has to come back out, and reversing a journal entry is a mechanism that already exists and is already audited, while un-setting a flag is not. And it is **visible** in the journal, the audit log and the trial balance's own movements, so nobody has to know that closing is a special case in order to see that it happened.

**A second close refuses; it does not recompute.** Recomputing looks idempotent and is not: the first close moved the revenue accounts to zero, so a recomputation either sees nothing and posts nothing, or deliberately excludes the close's own entries and posts the same profit again. Both are wrong and one is silent. The refusal names the entry to reverse.

**The balances exclude the closing journal**, by journal and not by date - the closing entry is dated the last day of the year, which is also when plenty of real transactions are. Without the exclusion, a reversal followed by a re-close would read the reversal as ordinary activity and close it again.

**Every period of the year must be closed first.** A year closed while December still accepts postings means a posting can land after the closing entry and never be carried forward: the year's revenue understated, retained earnings short by exactly that amount, and no total anywhere disagreeing with another.

**The closing lines carry the dimensions each account requires.** An expense account closed without its cost centre leaves that cost centre's profit never zeroed, so the following year's dimensional P&L opens with last year's figures still in it. Only the required axes, because grouping by all eight multiplies the entry - fifty cost centres by twenty projects is a thousand lines for one account - and the axes an account does not require are ones the business said it does not need broken out.

**Reversible while the new year's first period is still open and unposted.** Once somebody has posted into the new year on top of the carried-forward balances, un-closing the old one changes figures those postings were made against, and the new year's trial balance stops tying to the closing figures it was opened from.

**Nothing else may post into `CLS`.** The journal declares the one rule that may, and a trigger enforces it - a closing entry typed by hand is indistinguishable from the real one, and is found a year later by whoever compares this year's opening to last year's closing. A **reversal** is the one exception, because it mirrors an entry that is already there rather than asserting anything new; without that exception the close could never be undone, which would make its own reversibility requirement unachievable.

## Periods

| State | Postings | Changed by |
|-------|----------|-----------|
| `future` | No | — |
| `open` | Yes | — |
| `soft_closed` | Only `period.post_adjusting` | Controller |
| `closed` | No — reopening needs `period.reopen`, step-up auth and a reason | Controller |
| `locked` | **Never, by anyone.** Set after statutory filing; irreversible | Nobody |

Fiscal years are per entity and need not be calendar years: 12 monthly periods, 13 four-weekly, or a 52/53-week retail calendar. An **adjusting period 13** carrying no calendar days exists so audit adjustments land after December without being re-dated into it.

`locked` is enforced in the database and has no application path back. A date outside every defined period is **refused** rather than posted to nothing.

## Dimensions

Up to **eight** axes per entity — cost centre, project, department, branch, fund and three free axes — each a tenant-defined hierarchical list.

Stored as **columns** on `journal_lines`, not JSON and not an EAV side table, because every financial report filters and groups by them and an index on a JSON path is not a substitute. An account may require a dimension; validity rules can forbid a combination, including a value belonging to another entity.

## Manual journal entry

### The imbalance is a figure, not a boolean

"This entry does not balance" is useless while somebody is typing. The amount it is out **by** is what tells them which mistake they made, and the shape of the number says which:

| out by | almost always |
|--------|---------------|
| 90 (a multiple of 9) | a transposition — 1,500 typed as 1,410 |
| exactly twice a line | a sign the wrong way round |
| a round figure | a missing line |

So validation runs on every keystroke rather than on submit, and reports a signed imbalance with the **side** it falls on — "short 90.00 on the credit side" — because nobody should have to remember that debits are positive in storage to read a form.

### A line being typed is representable

A `PostingLine` is valid by construction: it holds a `Money`, an account or a role, and nothing that does not parse. A line being typed is none of those things — it has an account code somebody is half way through, an amount that is currently "1,2", and a dimension that may not exist.

Refusing to represent that is what forces a screen to keep its own shadow copy of the entry, and the shadow copy is where two versions of the truth come from. So a `DraftLine` holds strings, a `LineProblem` says what is wrong with them and **which field**, and the conversion to a `PostingLine` happens only once the line is valid.

A blank row at the bottom of the grid is counted as blank, not reported as an error. It is normal until somebody tries to post.

### Amounts are normalised, never guessed

`1,500.00` and `١٢٬٥٠٠٫٥٠` are both numbers somebody typed, and the form takes them: Arabic-Indic and Eastern Arabic-Indic digits, the Arabic decimal and thousands separators, and the Latin separators people actually type.

`about 12k` is not a number. A figure the software invented is a figure nobody can trace, so it is refused by name. More than four decimal places is refused too — rounding has to be a decision rather than a side effect.

### A control account is refused, and the refusal explains

Hiding the account from the picker is what gets built by default. It makes the screen simpler and it leaves somebody hunting for an account they can see in the chart, with nothing saying why.

So the message says three things, and a check asserts each phrase:

> Account 1.1.2.1 is a control account (accounts_receivable): its balance is the total of a subledger, and the documents post to it. A manual entry makes the two disagree and nothing afterwards can say which is right. Post to the counter account, or correct the document that created the balance.

What the account **is**, what would **break**, and what to do **instead** — in both languages, because it is the message most likely to be read by somebody who does not read English.

### Templates hold the shape, not the amounts

The accrual posted every month is the same entry with a different figure, and the way people do it without a template is to find last month's and copy it. That works until somebody copies the one that was reversed, or the one from before the chart changed.

A template line carries a signed **share** rather than an amount, so one figure drives the whole entry:

| | share | 100.00 becomes |
|--|------|----------------|
| Cost of sales | 0.333333333333 | 33.3333 |
| Administrative expenses | 0.333333333333 | 33.3333 |
| Property, plant and equipment | 0.333333333334 | 33.3334 |
| Other income | −1.000000000000 | −100.0000 |

Each **side** is apportioned to the total separately. The first version multiplied every share by the total and put the residual on the largest-magnitude line — which is the −1 credit, already exact — so a 100.00 accrual came out as three debits of 33.3333 against a credit of 99.9999. The entry balanced and the total was not what anybody asked for, which is the kind of wrong that reconciles.

A deferred constraint refuses a template whose shares do not cancel, and a control account is refused in a template as well as at posting: a template is written once and used every month, so the refusal at posting would arrive on the first run and again every month until somebody worked out it was the template.

**A recurring template prepares a draft; it does not post.** An entry appearing in the ledger because a date passed is an entry nobody decided to make, and the first time it is wrong is the month a rate changed and nobody looked.

### A spreadsheet posts whole or not at all

A file of four hundred journal lines has mistakes in it. Not might: does. The person who prepared it has a column of codes from last year's chart, two rows where the sign is wrong, and one entry that is out by the VAT.

An import that posts what it can leaves a partial month in the ledger and a spreadsheet to diff against it. So the dry run is the primary operation:

```
Rejected: 7 of 9 rows have a problem. Nothing was written - the file posts whole or not at all.
  row 2   JV-001   entry_does_not_balance   Entry JV-001 is out by 50.0000, short on the credit side.
  row 4   JV-002   account_is_a_control     Account 1.1.2.1 is a control account (accounts_receivable)…
  row 6   JV-003   date_unreadable          "2026-02-30" is not a date. Accepted: 2026-08-05, 05/08/2026…
  row 8   -        reference_missing        This row has no reference, so there is nothing to say which…
  row 9   JV-004   entry_has_two_dates      Entry JV-004 carries two dates: 2026-08-05 and 2026-08-06…
```

Four decisions in there:

**Rows are grouped into entries by reference, and each entry must balance on its own.** A file whose grand total is zero while two entries are out by equal and opposite amounts would post two wrong entries and look right.

**Row numbers are spreadsheet row numbers**, offset by the header. An index into an array is a number somebody has to translate, and they will translate it wrong on the fortieth one.

**Dates are parsed against explicit formats.** `Carbon::parse('2026-02-30')` returns **2 March** — silently, two days into a period that may be closed by then. Before the formats were made explicit, that row was accepted and the only complaint was that the entry "carried two dates", which is true and is not the problem.

**The refusal carries the report**, and that is what the guard is for. Atomicity is not: the import runs in one transaction, so removing the guard still posts nothing — the Poster refuses the unbalanced entry and everything rolls back. What the guard provides is the answer the screen needs, instead of one constraint violation about one entry and a four-hundred-row spreadsheet to import again.

### What is not here

The keyboard-operable grid. It belongs to the design system in Phase 11, and building it first means building it twice. What it needs from the ledger exists and is checked: a running imbalance per keystroke, a problem per line with its field named, and a refusal that explains rather than hides.

## Inter-entity postings

A transaction between two entities of the same tenant — A pays a supplier invoice on behalf of B, C recharges D for shared services — posts **two entries in two sets of books**, each balancing in its own entity's functional currency.

### One row, one amount

The obvious shape is two rows, one per side: A records what it is owed, B records what it owes. It is also how inter-company balances come to disagree in every system that has them, because two rows can hold two numbers and nothing makes them the same.

So there is one row holding **one settlement amount in one currency**, and both entries derive from it. The pair cannot fail to net to zero in the settlement currency, because there is only one figure. Eliminating it in consolidation is then arithmetic rather than a reconciliation.

### Why the pair is denominated in a settlement currency

A keeps books in SAR, B in EUR, and A lends B 1,000 USD. There is no single "amount" in either entity's currency:

| | records | at | giving |
|--|---------|----|--------|
| A (SAR) | a 1,000 USD receivable | 3.75 | 3,750.00 SAR |
| B (EUR) | a 1,000 USD payable | 0.8625 | 862.50 EUR |

The 1,000 USD is the fact both sides agree on. Each functional amount is a **translation of it**, recorded with its rate, rate set, date and provider on the line — and revalued at each period end by the monetary revaluation, because an inter-entity balance is a monetary item like any other.

### The translation difference is identified, not absorbed

In the consolidation, the settlement amounts cancel exactly. The two functional figures, translated into the presentation currency at the closing rate, do not — because each side was booked at its own rate on its own day. Measured on the four-entity group in `bin/verify group`:

| pair | settlement | lender, in SAR | borrower, in SAR | difference |
|------|-----------|----------------|------------------|-----------|
| A → B | 10,000.00 SAR | 10,000.00 | −10,120.00 | **−120.00** |
| B → A | 4,000.00 EUR | 17,600.00 | −17,391.30 | **+208.70** |
| A → C | 25,000.00 USD | 93,750.00 | −94,000.00 | **−250.00** |
| C → D | 8,000.00 USD | 30,080.00 | −30,000.00 | **+80.00** |
| D → B | 15,000.00 SAR | 15,000.00 | −15,180.00 | **−180.00** |
| B → C | 2,500.00 EUR | 11,000.00 | −10,898.55 | **+101.45** |
| | | | **total** | **−319.71** |

That total is a figure the consolidation reports. Absorbing it is how a consolidated balance sheet acquires an unexplained plug nobody can trace — and reporting only the total would net the differences in opposite directions and lose which balance each one is on, which is the question during a consolidation.

A pair settled in a currency both entities share shows **zero**, asserted separately: a difference there would be a rounding artefact wearing the name of a translation difference, and it would send somebody looking for a rate that was never applied.

### An inter-entity line cannot exist without a transaction

This is the guard that matters in practice. The control-account rule already stops a human posting into an inter-entity account; this stops a *registered rule* doing it without a counterpart — which is the shape of every inter-company balance nobody can explain.

A database trigger enforces both directions:

- a line reaching an account with the `inter_entity` role **must** name a transaction
- a line that is not an inter-entity line **must not**, because the elimination would then remove a figure that is not the inter-company balance

### Reading a pair needs both entities' scopes

The transaction row is deliberately **not** entity-scoped: it belongs to two entities, and scoping it to either would hide the pair from one of the two places that need to reconcile it. Its own row-level security policy admits a row when the current entity is *either* side.

Which means a consolidation cannot read it with one cross-entity query. That was the first implementation and it returned **nothing**: with no entity established `peakflow_current_entity()` is null, so the policy matched no rows, and the consolidation reported zero pairs, zero translation difference and "eliminates: true" on a group with six live transactions.

A query that returns nothing looks like data. So each entity reads its own side inside its own scope and the halves are joined by transaction id — one query per entity, no row-level-security bypass anywhere, and it proves something the single query could not: that each side is visible from its own books. The number of sides actually seen is counted and reported, so "the consolidation found nothing" is a finding rather than a clean result.

### The close refuses

A close that proceeds over a broken pair carries an unexplainable inter-company balance into retained earnings and locks it there — in a year that then needs a permission, a reason and a compliance entry to reopen. So `YearEndClose` checks three things before it posts anything:

| refused when | because |
|--------------|---------|
| a transaction has one entry | a deferred constraint makes that impossible to commit, so something wrote the table directly |
| the settlement amounts leave a residual | the single settlement figure has stopped being what both sides derive from |
| an inter-entity balance has no transaction | it cannot be eliminated and cannot be explained |

A translation difference is **not** a reason to refuse. It is expected, it is reported by the consolidation, and refusing on it would mean a group with two currencies could never close a year.

## The trial balance, and why it reads a cache

A trial balance aggregating ten million journal lines takes **8.5 seconds**. That is measured, not estimated, and it is the reason `account_balances` exists.

| 10,000,000 journal lines | |
|--------------------------|--|
| a full-year trial balance, from the balance model | **14 ms** |
| the same statement, aggregated from the journal | **8,487 ms** |
| one month | 7 ms |
| drilling into one account for one month | 282 ms, 2,083 lines |
| partitions scanned, for a month / quarter / year | 1 / 3 / 12 |
| queries, for 5 accounts and for 400 | 3 either way |

The read model holds one row per entity, period, account and dimension set, so a full-year statement reads 4,800 rows instead of ten million — and the measured time is 14 ms at both 120,000 lines and 10,000,000, because it does not read the journal at all.

### The range has to line up with whole periods

The read model's grain **is** the period. A range from 1 January to 15 June has nothing to read for half of June, and apportioning that half from the period's total would produce a figure nothing in the ledger agrees with — a trial balance that is close.

So a range that starts on a period's first day and ends on a period's last day reads the cache; anything else reads the journal. Both are correct and the statement says which one it was:

```
basis.source = 'read_model'   a period-aligned range
basis.source = 'journal'      a mid-month date, or fromJournal: true
```

Nobody reconciling a figure that looks wrong should have to guess which side they are looking at.

### And the two paths are asserted to agree

`BalanceRebuilder` proves the read model against the journal row by row. That is a different claim from this one: the two **read paths** bound differently — one by date, one by period — and the day they diverge is the day two reports of the same figure disagree.

So `bin/verify ledger` reads both and compares them row by row, not just on totals: totals alone would pass with two accounts transposed, which is precisely the shape of a grouping bug.

That comparison found one real divergence, by reasoning rather than by failing. An adjusting entry dated mid-year sits in a period that ends in December, so the date bound would include it in a statement as at 31 March and the period bound would not. Both correct; different questions. `AdjustingEntries` now refuses any date but the fiscal year end — which costs nothing real, because an adjustment belongs to the year it adjusts.

### Partition pruning is asserted on the plan

`journal_lines` is range-partitioned by month. A query without a date bound scans every month ever posted and gets slower every month — correct, fast in the first year, and nobody notices until year three.

So the partition count is read off `EXPLAIN` and asserted: a month touches one partition, a quarter three, a year twelve. Detaching and archiving an old month is [`docs/operations/partition-archival.md`](../operations/partition-archival.md).

## The adjusting period, and what a statement declares

### Period 13 carries no calendar days, and that is load-bearing

It starts and ends on the fiscal year's last day, sharing them with December. So a date lookup skips it on purpose — otherwise every December posting would land in it by accident, and the point is the opposite: an auditor's adjustment sits **after** the last month rather than being re-dated into it.

Which left it unreachable. The period was a row the calendar generator could create and no posting path could use, so the only way to record an adjustment was to re-date it into December — exactly what it exists to prevent. Found by measuring, not by reading: a probe posted three adjustments "into the adjusting period", read the trial balance both ways, and got the same total twice, because all three had gone into December.

`AdjustingEntries` is the way in. It takes `period.post_adjusting` with step-up authentication, a reason of at least twenty characters, and sets the transaction-local session flag the period trigger reads to admit a posting into a soft-closed period. The entry keeps its **year-end date** — it belongs to that year's figures, and the period is what distinguishes what the business recorded in December from what the audit changed afterwards.

The period itself is optional, from the entity's `ledger.adjusting_period` setting, defaulting to on. The cost of having it and not using it is an empty period; the cost of not having it is a December that is not what December was.

### Every statement says what it included

A boolean in a payload is the right fact and is not a declaration. It sits in an array a screen may ignore, a PDF may not print and an export may drop, and the one place it matters is the printed page in front of an auditor.

So every report carries a `StatementBasis`, which renders a sentence in both languages:

```
Stated in SAR as at 2026-12-31, including the 3 adjusting entries in the adjusting period.
بعملة SAR كما في 2026-12-31، بتضمين 3 قيود تسوية في فترة التسوية.

Stated in SAR as at 2026-12-31, EXCLUDING the 3 adjusting entries in the adjusting period.
بعملة SAR كما في 2026-12-31، باستثناء 3 قيود تسوية في فترة التسوية.
```

Those two statements are 1,450 and 1,000. Both are legitimate under IAS 10 — one is the reported figure, the other is the operational figure the business was running on — and they are not interchangeable. A page that does not say which one it is showing is a page that will be quoted as the other.

**Whether the choice made a difference is part of the sentence.** On a range with no adjusting entries in it:

```
Stated in SAR as at 2026-12-31. No adjusting entries fall in this range, so including or
excluding them gives the same figures.
```

That settles an argument before it starts, and it catches the mistake in the other direction: "excluding" printed on a year with forty adjustments is a statement somebody will compare to the filed accounts and find short.

**The Arabic counts properly.** One is singular, two is a dual, three to ten takes a broken plural, eleven upwards goes back to a singular. `3 قيد` is wrong, `3 قيود تسوية` is right, and this is a sentence an auditor reads — so a check asserts it, and simplifying it to a plural suffix fails the build.

## `ledger:verify`

The global proof. An individual assertion can be written to pass: a check that posts an invoice and then asserts the receivable moved by the invoice total passes even if the rule posted twice, posted to the wrong receivable, or posted into a period that was closed — because the check only looks at what its author thought to look at.

```bash
php artisan ledger:verify {entity}   # or --all
```

It exits non-zero with a specific, actionable report. These questions cannot be faked by an individual check, because they are asked of the whole ledger:

| Finding | Asks |
|---------|------|
| unbalanced entry | does every entry sum to zero in the functional currency |
| posting into a period that was not accepting | was the period accepting postings **when the entry was posted** |
| postings that recorded no period state | did anything write an entry the period trigger never saw |
| line out of step with its entry | does every line carry its entry's date and period |
| control account posted manually | did anything reach a control account without a registered rule |
| orphaned line | is every line attached to an entry that exists |
| translation with no provenance | can every rate used be placed, and said to be the right one |
| translation at a stale rate | was any rate older than the fallback window |
| **entry substance changed** | does every entry still match the fingerprint taken when it was posted |
| **entries outside the proof** | how many posted entries carry no fingerprint at all |
| **audit chain broken** | has the record of who did what been edited or excised |

Three more belong here and are not written yet, because the other side of the comparison does not exist: a subledger total against its control account needs a subledger. AR and output VAT arrive with Phase 05, AP and input VAT with Phase 07, bank and in-transit with Phases 08–09. Writing them now against tables that are not there would make them pass by being vacuous, which is the worst kind of green.

The balance read model is compared against a fresh rebuild by [`BalanceRebuilder`](../../app/Tenant/Ledger/Services/BalanceRebuilder.php), on its own schedule rather than inside this command — a full recomputation is not something to put in the path of a check that should be cheap enough to run after every test.

### The fingerprint, and what the immutability triggers do not prove

`journal_entries_posted_immutable` refuses an UPDATE to a posted entry. That proves exactly one thing: **no statement went through it and changed a posted figure.**

It proves nothing about a statement that did not. A role that can `ALTER TABLE ... DISABLE TRIGGER`, a restore from a doctored dump, a superuser session, a bug in a future migration — each leaves a ledger in which every trigger is present and correct and a figure has moved. Nothing in the schema would notice, and **the trial balance would still tie**, because a tamper that balances is the only kind worth doing.

So each entry's substance is hashed at commit by a deferred trigger, into `journal_entry_fingerprints`, and `ledger:verify` recomputes it with the same function. Measured, on a real database:

```
-- every trigger on journal_lines disabled
UPDATE journal_lines SET amount =  55555.0000 WHERE ... AND functional_amount > 0;
UPDATE journal_lines SET amount = -55555.0000 WHERE ... AND functional_amount < 0;
-- the entry still balances: sum(functional_amount) = 0.0000
-- the trial balance still ties

$ php artisan ledger:verify acme
  entry_substance_changed
    Entry GEN-2026/00001 of 2026-06-03 no longer matches the fingerprint taken
    when it was posted at 2026-11-15 13:40:19+00. It had 2 line(s) then and has 2 now.
```

Four decisions inside that are worth stating:

**A separate table, not a column.** The obvious design is `journal_entries.content_hash`, written by a trigger. It cannot work — the entry is inserted with `status = 'posted'`, so the trigger's own UPDATE is refused by the immutability trigger, and carving an exception into that refusal would widen the one control that has no business being widened.

**Computed in the database, at commit.** The application hashing its own work proves only that the application wrote what it meant to write; an import that bypasses the Poster would get no fingerprint at all. At commit rather than per statement because an entry is transiently incomplete while its lines are inserted — and a deferred trigger is per-row, so the first line of a fifty-line entry computes the hash and the other forty-nine see a line count that already matches and return. One hash per entry, not fifty.

**Write-once, by transaction id.** A fingerprint may be refined only inside the transaction that created it. The first version of that guard compared `fingerprinted_at` against `now()` and refused everything: `timestampTz` stores whole seconds and `now()` has microseconds, so the two are never equal. A transaction id is what the test actually means and has no precision to get wrong.

**`status` is not in the hash.** An entry moves from `posted` to `reversed` legitimately. Including it would make every reversal look like a tamper, and a check that cries wolf is a check nobody runs.

### "Was the period accepting postings" is a question about the past

This check was wrong twice, in opposite directions, and both are worth recording because both look right.

The first version asked whether the entry's period is closed **today**. Closing February does not invalidate the entries posted into February while it was open — so in production this would have reported every entry in every closed period from the first month-end onwards. Thousands of critical findings, every one of them correct behaviour, which is how a report stops being read. It was found by running the global proof at the end of the ledger suite, where a year-end close legitimately closes a period it had just posted into.

The second read the state from the transition log, taking the latest transition at or before the entry's `posted_at`. That is the right question and the data cannot answer it: `now()` in PostgreSQL is the **transaction** timestamp, so a close and a posting in one transaction carry the identical instant and cannot be ordered. Measured on a year-end close and its reversal: eight transitions and three entries, all stamped the same second.

So the period trigger — which reads the state anyway, in order to decide whether to refuse — writes down what it saw. A recorded fact instead of a reconstruction, and `NULL` becomes the other half of the finding: the column is only ever written by the thing a bypass goes around.

### It runs at the end of every suite that posts

Not by convention. The `ProvesTheLedger` trait makes it one line, and an architecture rule fails the build for any suite in `tests/Support` that writes to `journal_lines` without it. Forgetting it is a build failure — the only form of "wired in" that survives the person who wrote the convention leaving.

It runs inside each suite's transaction, which is why it cannot live in the runner: every suite rolls back in a `finally`, so by the time `bin/verify` saw the results there would be nothing left to verify.

### It reports, it does not repair

Silent repair hides the bug that caused the drift, and the bug will produce it again — differently, later, after more has been built on top. A figure that was wrong for a week was on a report for a week, in an email, possibly in a filed return, and a repair erases the only evidence of how long.
