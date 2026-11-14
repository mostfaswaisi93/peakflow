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

**`RevaluationRun`** (IAS 21) — monetary items only, reversed and recomputed each period

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | `accounts_receivable` | 1,800 |
| Cr | `fx_revaluation` | (1,800) |

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

**`YearClosed`** — an ordinary entry in a reserved journal, so it is visible, drillable and reversible

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | every revenue account | its credit balance |
| Cr | every expense account | its debit balance |
| Dr/Cr | `retained_earnings` | the net |

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
