# Schema

The authoritative description of every table. **A schema change updates this file in the same pull request as its migration** — that is in the Definition of Ready and in `CONTRIBUTING.md`, because a schema document that lags its migrations is worse than none.

## Conventions that apply everywhere

| Convention | Detail | Why |
|------------|--------|-----|
| Primary key | `ulid` (26-char, `char(26)`) | Sortable by creation time, safe to generate client-side for idempotent and offline writes, and does not leak a row count the way a sequence does |
| Money | `numeric(21,4)` amounts, `numeric(21,8)` unit prices, `numeric(21,12)` rates | Exact decimal; see [`money-and-precision.md`](../architecture/money-and-precision.md) |
| Accounting dates | `date`, never `timestamptz` | A date that can be timezone-shifted is a period-allocation bug |
| Event times | `timestamptz` | Always UTC at rest |
| Entity scope | Non-nullable `entity_id` on **every** financial table, first in every composite index, plus an RLS policy | Two independent mechanisms; both enforced by architecture tests |
| Soft deletes | `deleted_at` on anything referenced by history | A deleted customer's name must still resolve on a three-year-old invoice |
| Authorship | `created_by` everywhere a user can create a row; `posted_by` + `posted_at` separately on financial tables | Who drafted and who approved are different questions, and the second is the one an auditor asks |
| Append-only tables | Declarative range partitioning by month on the date column | `journal_lines`, `bank_transactions`, `audit_log` grow for the life of the account and every query is period-bounded |
| Log indexes | Composite `(filter_column, created_at)`, plus a standalone `created_at` | PostgreSQL uses one index per table reference, so a lone filter index lets it filter **or** sort, never both |
| Enums | PostgreSQL native enums for closed sets, `text` + a check for sets a tenant extends | A migration to add a value is explicit and reviewable |

## Platform schema — the `platform` connection

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `operators` | Platform staff | Email unique; MFA mandatory |
| `tenants` | Customers | `slug` unique; `status` ∈ provisioning, active, suspended, expired, migrating |
| `plans` | Product tiers | Feature flags, limits, risk ceilings |
| `subscriptions` | A tenant's plan over time | No overlapping active periods per tenant (exclusion constraint) |
| `database_connections` | Per-tenant credentials | `password_encrypted`; excluded from serialization; one per tenant |
| `platform_settings` | Typed operator settings, including the ceilings tenants cannot exceed | Key unique |
| `activity_logs` | Operator actions | Append-only; `created_at` and `(operator_id, created_at)` |
| `impersonation_grants` | Tenant-granted consent to impersonate | Time-bounded; cannot be self-granted |
| `exchange_transmissions` | Outbound document state per tenant | `(tenant_id, document_ulid, transport)` unique; retry and dead-letter state |

## Tenant schema — the `tenant` connection

### Identity and tenancy

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `entities` | Legal companies | `functional_currency` immutable once anything is posted; `(code)` unique |
| `tax_registrations` | Per entity, per jurisdiction | `(entity_id, jurisdiction, scheme)` unique |
| `fiscal_years` | Per entity | Non-overlapping date ranges (exclusion constraint) |
| `periods` | Per fiscal year | Non-overlapping; `state` ∈ future, open, soft_closed, closed, locked; `locked` is terminal |
| `users` | Tenant users | Email unique; soft deletes |
| `authenticators` | Passkeys and TOTP secrets | TOTP secret encrypted; last-used tracked |
| `roles`, `permissions`, `role_permissions` | The permission model | Permission keys from a fixed catalogue |
| `user_entity_roles` | **Roles are held per entity**, not globally | `(user_id, entity_id, role_id)` unique |
| `sod_rules` | Incompatible duty pairs | Seeded; the beneficiary-change pair is not overridable |
| `sod_overrides` | Documented exceptions | Requires reason, approver ≠ requester, and an expiry |
| `audit_log` | Append-only, hash-chained; records reads of sensitive data as well as writes | Partitioned monthly; `UPDATE`/`DELETE` revoked at the role level |
| `tenant_settings` | Typed, per tenant or per entity | Risk-bearing values validated against the platform ceiling |

### Ledger

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `accounts` | Chart of accounts | `(entity_id, code)` unique; `type` ∈ asset, liability, equity, revenue, expense; `control_role` nullable; a **partial unique index** gives one account per control role per entity; header accounts not postable |
| `journals` | The books | `(entity_id, key)` unique; reserved journals accept only their own process |
| `journal_entries` | Entry headers | `(entity_id, journal_id, number)` unique; `status` ∈ draft, posted, reversed; `accounting_date` is a `date`; trigger refuses a non-accepting period; trigger refuses mutation when posted |
| `journal_lines` | Signed amounts | `CHECK (amount <> 0)`; **`CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` asserting `SUM(functional_amount) = 0` per entry**; partitioned monthly; covering index `(entity_id, period_id, account_id)`; `DELETE` revoked |
| `dimension_axes`, `dimension_values` | Up to eight tenant-defined axes | Hierarchical; values scoped to an entity |
| `account_balances` | Materialized read model | Rebuildable from the journal by definition; nightly drift check |
| `entry_reversals` | Links an entry to its reversal, both ways | A reversal cannot itself be reversed |
| `inter_entity_links` | Reciprocal due-to / due-from pairs | Must net to zero in consolidation; checked by the close |

### Parties and catalog

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `parties` | **One table with roles** — customer, supplier, employee, or several | Shared across the tenant; balances are per entity; soft deletes |
| `party_addresses` | Legal, billing, delivery | Shaped on the EN 16931 address terms |
| `party_tax_registrations` | With scheme and identifier | Structure validated where the scheme defines one |
| `party_electronic_addresses` | With their **EAS** scheme code | What Peppol routing needs |
| `party_bank_details` | IBAN, BIC, domestic identifiers | **Column-encrypted**; every change audited; a change sets `requires_reverification` |
| `party_credit` | Limit, hold, risk grade, per entity | Exposure computed, not stored |
| `contacts` | With role and **per-contact locale** | A document is rendered in the recipient's language |
| `items` | Billable items | `(code)` unique per tenant; `item_type` ∈ good, service, digital_service — it drives place of supply; classification code **with its scheme** |
| `price_lists`, `price_list_lines` | Per party or group, per currency, effective-dated, tiered | Non-overlapping validity per key |
| `payment_terms` | Net days, EOM, fixed day, instalments, discount terms | Derives one or more due dates |
| `currencies` | Enabled per entity | Exactly one functional currency per entity |
| `fx_rates` | `(from, to, date, rate_set, provider)` | **Direction stored, never inferred**; `(from, to, date, rate_set)` unique |
| `number_series` | Per entity, per document type, per year, optionally per branch | **Format frozen once the first document is issued**; `next_number` allocated under `FOR UPDATE` |
| `attachments` | Documents on anything | Content type sniffed; deduplicated by hash with reference counting |

### Tax

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `tax_codes` | Category + jurisdiction + reporting box mapping | `category` from UNCL 5305; an exemption reason is mandatory where the category requires one |
| `tax_rates` | Effective-dated versions of a code's rate | **Overlapping validity refused by an exclusion constraint**; a document resolves by its tax point |
| `place_of_supply_rules` | Data-driven, with a documented source per jurisdiction | Evaluated in a defined precedence; the winning rule is recorded on the document |
| `withholding_rates` | Per party, item type and jurisdiction | Effective-dated |
| `tax_return_periods` | Per registration; need not match accounting periods | A document belongs by its **tax point** |
| `tax_returns` | A filed period | **Frozen snapshot** with figures, supporting line ids and a hash; never edited |
| `tax_return_adjustments` | Corrections landing in a later period | Carries a reference to the period corrected |

### Sales

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `quotes`, `quote_lines` | Non-binding offers | No legal number, no posting |
| `sales_orders`, `sales_order_lines` | Commitments | Uninvoiced balance tracked **per line**; over-invoicing refused |
| `invoices` | Issued documents | `(entity_id, series_id, number)` unique; `document_type` is a UNCL 1001 code; **frozen snapshot** of both parties' legal identity, addresses, registrations, rates, FX rate and terms; `canonical_hash`, `previous_hash`, `chain_index`; immutable once issued |
| `invoice_lines` | With the item reference **and** its own description | So renaming an item later does not rewrite history |
| `invoice_tax_breakdown` | One row per `(category, rate)` | EN 16931 `BG-23`; `BR-CO-14` asserted against the document tax total |
| `invoice_allowances_charges` | Document and line level | Apportioned across rate groups before tax |
| `invoice_references` | Credits, corrections, preceding documents | EN 16931 `BT-25` |
| `invoice_due_dates` | Instalment terms produce several | Each separately dunnable and allocatable |
| `recurring_schedules` | Template + RFC 5545 `RRULE` | Generation idempotent on `(schedule_id, occurrence_date)` |
| `recognition_schedules` | IFRS 15 per line | Posts to deferred revenue, releases on schedule |
| `dunning_policies`, `dunning_events` | Steps, offsets, channels, actions | Evaluated per invoice, **sent per customer**; a dispute suppresses escalation |
| `delivery_log` | Per document, per channel | Attempt, outcome, provider message id; failure never changes issued state |

### Purchasing

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `purchase_requisitions`, `purchase_requisition_lines` | Internal requests | A justification over ten characters, enforced by CHECK; a rejection must carry a reason; a converted requisition must name its order and must have been approved first; prices named `estimated_` because the supplier has not quoted; **posts nothing** |
| `purchase_orders`, `purchase_order_lines` | Commitments | `quantity_received` and `quantity_billed` are **per line**; a closed order must carry a reason over five characters; **no posting anywhere** - an order is a promise, and a commitment in the ledger is a liability nobody owes |
| `goods_receipts`, `goods_receipt_lines` | What arrived | One row per order line per receipt; a rejection must carry a reason; `journal_entry_id` and `reversal_entry_id` hold the optional GRNI accrual and its release, so an accrual that never cleared is a receipt nobody was billed for |
| `bills`, `bill_lines`, `bill_tax_breakdown` | Supplier invoices | **`(entity_id, party_id, supplier_document_number)` unique** — the hard half of duplicate detection |
| `bill_lines.purchase_order_line_id`, `bill_lines.goods_receipt_line_id` | What a bill line matched against | On the **line**, not a `bill_matches` table: one bill routinely spans several orders, and a bill-level link could not say which line came from which |
| `bills.credits_bill_id`, `bills.credit_reference`, `bills.credit_disposition` | A credit note's reference and what becomes of it | A credit note (`document_type` `381`) must name a bill **or** state the supplier's reference; a trigger refuses crediting beyond what the bill charged, across suppliers, or across currencies |
| `bill_credit_allocations` | A credit note used against another bill | One row per pair; a trigger bounds it from both ends - not beyond the credit's value, not beyond what the bill still owes; **posts nothing**, because both sides are in `accounts_payable` for one party |
| `bill_lines.credits_line_id` | Which line of the original a credit line credits | So the bill can show which lines have been credited and by how much |
| `supplier_match_tolerances` | How much difference is not worth a person's attention | Per supplier **and** line type; absolute and percentage, the larger applying; **a CHECK constraint caps both at 10%** - a 50% tolerance is the match turned off |
| `three_way_match_exceptions` | A mismatch, with its figures | `expected`, `actual` and `tolerance` as columns so the queue sorts by how wrong each row is; `detail` is required and over ten characters, because "on hold" makes somebody open three documents; unique per `(bill_line, kind)` so a re-run does not duplicate an answered row |
| `approval_rules`, `approval_chains`, `approval_decisions` | The workflow | An edit after approval **returns the document to the start of the chain** |
| `approval_delegations` | Date-bounded | A delegate cannot receive an approval SoD forbids them |
| `expense_policies` | What a category of expense may be | A rate, a ceiling or both; dated, because rates change; `tax_recoverable` and `recoverable_percent` kept consistent by CHECK |
| `expense_claims`, `expense_claim_lines` | Employee claims | Post to `employee_payable`, a distinct control account; the claimant is a **user**, not a party; recovery needs a receipt **and** a stated reason (CHECK); the rate is frozen from the policy on the date incurred; a trigger refuses submission while a policy breach is unexplained |
| `inbound_documents` | Email and Peppol intake, before they are bills | An inbox item is **not** a bill; unique on `(entity, content_hash)` so a retried delivery is one row; `match_method` enumerates what is allowed, so a **name match stays impossible**; a rejection must carry a report; a converted row must name its bill and its supplier |
| `inbound_senders` | The allowlist, per supplier | An address rather than a domain - a domain allowlist is the broader grant and nobody revisits it |
| `extraction_templates` | A supplier's document format, and how well it reads | Counters rather than a derived rate: a confirmed proposal's fields are frequently pruned, and a derived rate would fall to zero as they went |
| `extraction_proposals` | A machine's reading of a document | A **proposal**, not a value; one per document; a row naming a bill must have a confirmation with a person and a time behind it |
| `extraction_fields` | What was read, and what a person made of it | A trigger refuses a confidence on an `embedded_xml` field and requires one on an `ocr` field; `needs_review` is stored, not derived from today's threshold |
| `parties.default_expense_account_id` | Where a supplier's purchases usually post | EN 16931 has no term for it: the chart is ours, not theirs. Null means the draft cannot post until a person codes it |

### Banking

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `bank_accounts` | Real accounts | IBAN and BIC validated in the constructor; **1:1 immutable link to a GL account** with the `bank` role; exactly one entity; opening balance immutable once a statement exists |
| `bank_statements` | Numbered, continuous | **Opening balance must equal the previous statement's closing balance**; a mismatch stops the import; never deleted, only superseded |
| `bank_transactions` | The bank's assertions | **Immutable**; booking date **and** value date both stored; ISO 20022 transaction code retained; idempotency key on `(account, bank_reference)` or a content hash; partitioned monthly; `DELETE` revoked |
| `bank_transaction_enrichment` | What we think it is | **Separate nullable columns**, never by rewriting the imported value |
| `matching_rules` | Tenant-authored, versioned | First-match-wins; a past match explainable by the rule as it was |
| `match_proposals` | With confidence and a readable reason | Nothing below the threshold is hidden |
| `matches` | Applied links, including splits both directions | Applied atomically; individually reversible |
| `reconciliations` | **A document**: period, closing balance, matches, authorizer, timestamp | Unreconciling requires a reason and is audited; the prior reconciliation is retained |
| `csv_import_templates` | Per-bank column mapping | Reusable and shareable |

### Settlement

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `payments` | Cash moving | Immutable once posted; a currency mismatch with the account is refused |
| `payment_allocations` | **Which obligation a payment settles** | Cannot exceed the payment's unallocated amount or the document's open balance — enforced in the database, because this keeps AR equal to its control account |
| `customer_credits` | Over-payments and credit balances | Usable against future documents, or refundable |
| `write_offs` | With reason code | Permissioned; SoD-separated from invoicing and reconciliation |
| `ecl_provisions` | IFRS 9 provision matrix results | Reversed and recomputed each period |
| `payment_runs`, `payment_run_lines` | Batches | `state` ∈ draft, prepared, released; **immutable once released**; preparer ≠ releaser enforced against the audit trail |
| `payment_instructions` | Individual instructions within a run | Status applied down to the instruction from PAIN.002, not just the batch |
| `direct_debit_mandates` | Reference, signature date, scheme, sequence type | Sequence type correct per mandate, since getting it wrong is a scheme rejection |

### Reporting

| Table | Holds | Notable constraints |
|-------|-------|---------------------|
| `report_definitions` | Saved parameters | Re-runnable by anyone with permission, producing the same artefact |
| `budgets`, `budget_lines` | By account and dimension, versioned | One locked approved version |
| `consolidation_runs` | Group statements | Records the rates used and the elimination proof |
| *read models* | `account_balances`, aging, open-item | **Derived, never authoritative**; `Reporting` performs no writes to domain tables, enforced by an architecture test |

## Approximate table counts

| Schema | Tables |
|--------|--------|
| Platform | 9 |
| Tenant — identity and tenancy | 13 |
| Tenant — ledger | 9 |
| Tenant — parties and catalog | 16 |
| Tenant — tax | 7 |
| Tenant — sales | 13 |
| Tenant — purchasing | 12 |
| Tenant — banking | 9 |
| Tenant — settlement | 9 |
| Tenant — reporting | 4 + read models |
| **Tenant total** | **≈ 92** |

## The constraints that carry the most weight

Repeated here because they are the ones a reviewer should know by heart:

```sql
-- An entry is transiently unbalanced while its lines insert, so the check
-- MUST be deferred to COMMIT. A per-statement trigger would reject every
-- multi-line entry ever written. Do not "fix" this by moving it into PHP.
CREATE CONSTRAINT TRIGGER journal_entry_must_balance
    AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION assert_entry_balanced();

-- One account per control role per entity.
CREATE UNIQUE INDEX accounts_one_per_control_role
    ON accounts (entity_id, control_role)
    WHERE control_role IS NOT NULL AND deleted_at IS NULL;

-- A tax code's rate versions may not overlap in time.
ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_no_overlap
    EXCLUDE USING gist (tax_code_id WITH =, validity WITH &&);

-- Gapless numbering: a legal sequence, not a surrogate key. nextval() is
-- deliberately non-transactional and would leave a gap on every rollback.
SELECT next_number FROM number_series
 WHERE entity_id = $1 AND series_id = $2 AND period_year = $3
   FOR UPDATE;

-- The application cannot delete posted history, whatever the ORM does.
REVOKE DELETE ON journal_entries, journal_lines, bank_transactions,
                 audit_log, invoices FROM peakflow_app;
```
