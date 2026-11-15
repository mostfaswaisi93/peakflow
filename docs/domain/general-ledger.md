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

## `ledger:verify`

The global proof. An individual assertion can be written to pass; this cannot.

```bash
php artisan ledger:verify {entity} [--period=]
```

| Assertion | Catches |
|-----------|---------|
| Every entry sums to zero in functional currency | A bypassed constraint, a bad posting rule |
| AR subledger = `accounts_receivable` | A manual posting into a control account, a broken allocation |
| AP subledger = `accounts_payable` | The same, on the payable side |
| Tax accounts = the period's return | A rate resolved at the wrong date, a missed breakdown |
| Bank GL = statement ± reconciling items | An unexplained reconciliation |
| `payments_in_transit` = released and unreconciled | A payment that left and never arrived |
| Inter-entity pairs net to zero | A one-sided inter-entity posting |
| Nothing posted into a non-accepting period | A backfill that ignored period state |
| No posted entry mutated | A tampered audit chain |
| Read models match a fresh rebuild | Drift in a materialized balance |

It exits non-zero with a specific, actionable report, and **it runs as the final assertion of every feature test that posts anything** — wired into the test case rather than remembered per test.
