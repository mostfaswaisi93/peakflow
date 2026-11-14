# PeakFlow

> A multi-tenant financial operations platform where every invoice, every bank line and every payment is a **posting into an immutable double-entry ledger** - not a PDF with a total on it. One tenant can run **many legal entities**, each with its own chart of accounts, functional currency, tax registration and fiscal calendar, and consolidate across them. Invoices are issued against **EN 16931** semantics and delivered as **UBL 2.1 / Peppol BIS Billing 3.0**; bank statements arrive as **ISO 20022 CAMT.053** and payments leave as **PAIN.001**; and nothing in the system can report a balance it cannot explain line by line. Arabic-first (RTL), bilingual AR/EN, server-rendered and reactive, with a versioned REST API.

<p align="center">
<img src="https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
<img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13">
<img src="https://img.shields.io/badge/PostgreSQL-16+-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL 16+">
<img src="https://img.shields.io/badge/Livewire-3-FB70A9?logo=livewire&logoColor=white" alt="Livewire 3">
<img src="https://img.shields.io/badge/Tailwind_CSS-4-38BDF8?logo=tailwindcss&logoColor=white" alt="Tailwind CSS 4">
<img src="https://img.shields.io/badge/EN_16931-compliant-0B5FFF" alt="EN 16931">
<img src="https://img.shields.io/badge/ISO_20022-CAMT_%2F_PAIN-00843D" alt="ISO 20022">
<img src="https://img.shields.io/badge/WCAG-2.2_AA-2E7D32" alt="WCAG 2.2 AA">
<img src="https://img.shields.io/badge/License-Proprietary-lightgrey" alt="License">
</p>

---

## Status of this repository

> **This repository is the specification, the architecture and the delivery backlog. It is the contract the code is written against, and it was written before the code on purpose.**
>
> Financial software is the wrong place to discover your domain model halfway through. A gapless invoice sequence, a ledger that must balance to the cent, a bank reconciliation that an auditor will re-perform, and a tax engine whose rounding rule is written into law are all decisions that are cheap on paper and expensive in a migration. So the whole of PeakFlow is specified here first - **246 tasks across 14 phases**, each with acceptance criteria, dependencies and the standard it answers to - and the implementation is tracked against it in GitHub Issues.

| Legend | Meaning |
|--------|---------|
| **Specified** | Designed here, with acceptance criteria and a task ID. Not yet code. |
| **In progress** | A task is open against it. |
| **Shipped** | Merged, tested, and the task is closed. |

Everything in this README is **Specified** unless a section says otherwise. Nothing here describes behaviour that exists today - see [`docs/tasks/`](docs/tasks/) for what is being built, in what order, and why that order.

---

## Table of contents

- [Overview](#overview)
  - [What problem this actually solves](#what-problem-this-actually-solves)
  - [What PeakFlow is not](#what-peakflow-is-not)
- [Architecture](#architecture)
  - [The three planes](#the-three-planes)
  - [Multi-tenancy - database per tenant](#multi-tenancy---database-per-tenant)
  - [Multi-entity - many companies inside one tenant](#multi-entity---many-companies-inside-one-tenant)
  - [Bounded contexts, and a compiler that enforces them](#bounded-contexts-and-a-compiler-that-enforces-them)
  - [Request lifecycle](#request-lifecycle)
  - [Authentication](#authentication)
  - [Authorization, and segregation of duties](#authorization-and-segregation-of-duties)
  - [Everything financial is append-only](#everything-financial-is-append-only)
- [Money, precision and rounding](#money-precision-and-rounding)
  - [Why not floats, and why not minor-unit integers either](#why-not-floats-and-why-not-minor-unit-integers-either)
  - [The three decimal scales](#the-three-decimal-scales)
  - [Where rounding happens, exactly once](#where-rounding-happens-exactly-once)
- [The general ledger](#the-general-ledger)
  - [Chart of accounts](#chart-of-accounts)
  - [Journals, entries and lines](#journals-entries-and-lines)
  - [The balanced-entry invariant is a database constraint](#the-balanced-entry-invariant-is-a-database-constraint)
  - [Periods, closing and locking](#periods-closing-and-locking)
  - [Dimensions](#dimensions)
- [Invoicing](#invoicing)
  - [The document lifecycle](#the-document-lifecycle)
  - [Numbering is gapless, and that is harder than it looks](#numbering-is-gapless-and-that-is-harder-than-it-looks)
  - [Tamper evidence - the hash chain](#tamper-evidence---the-hash-chain)
  - [Recurring billing and revenue recognition](#recurring-billing-and-revenue-recognition)
  - [Dunning](#dunning)
- [E-invoicing and compliance](#e-invoicing-and-compliance)
  - [One semantic model, many syntaxes](#one-semantic-model-many-syntaxes)
  - [Validation is a pipeline, not a checkbox](#validation-is-a-pipeline-not-a-checkbox)
  - [Jurisdiction profiles](#jurisdiction-profiles)
- [Banking](#banking)
  - [Bank accounts and their GL mirror](#bank-accounts-and-their-gl-mirror)
  - [Statement ingestion](#statement-ingestion)
  - [Reconciliation - the matching engine](#reconciliation---the-matching-engine)
  - [Payment initiation](#payment-initiation)
  - [Cash position and forecast](#cash-position-and-forecast)
- [Payments, allocation and FX](#payments-allocation-and-fx)
- [The tax engine](#the-tax-engine)
- [Purchasing and accounts payable](#purchasing-and-accounts-payable)
- [Reporting](#reporting)
- [Standards register](#standards-register)
- [Tech stack](#tech-stack)
- [Requirements](#requirements)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [Artisan commands](#artisan-commands)
- [Scheduled tasks](#scheduled-tasks)
- [REST API](#rest-api)
- [Design system](#design-system)
- [Localization and RTL](#localization-and-rtl)
- [Accessibility](#accessibility)
- [Project structure](#project-structure)
- [Testing strategy](#testing-strategy)
- [Continuous integration](#continuous-integration)
- [Security](#security)
- [Observability](#observability)
- [The delivery backlog](#the-delivery-backlog)
- [Documentation map](#documentation-map)
- [License](#license)

---

## Overview

**PeakFlow** is a **database-per-tenant** SaaS platform for running the money side of a business: issue invoices, collect them, pay suppliers, import bank statements, reconcile, file tax, and close the books - with a double-entry general ledger underneath all of it rather than beside it.

A platform operator sells **plans** to organizations. Each organization (a **tenant**) gets its own dedicated PostgreSQL database, provisioned, migrated and seeded automatically on signup. Inside it, the tenant creates one or more **entities** - legal companies - and the whole application is scoped to the entity a user is currently working in.

### What problem this actually solves

Most invoicing tools and most accounting tools are different products, and the seam between them is where the money goes missing. The invoicing tool knows a customer owes 4,000; the accounting tool knows the bank received 3,950; nobody owns the 50 that was a wire fee, and at year end someone reconciles it in a spreadsheet.

PeakFlow refuses that seam. Three rules are structural, not features:

1. **No financial fact exists outside the ledger.** Issuing an invoice posts a journal entry. Receiving a payment posts one. Importing a bank line posts one, or sits in a suspense account until someone says what it was. A report is never a second opinion about the data - it is a query over the journal.
2. **Nothing financial is ever updated in place.** A posted entry cannot be edited; it is reversed and re-posted. A sent invoice cannot be altered; it is credited. This is not conservatism, it is what makes the audit trail worth having.
3. **Every balance is explainable.** Any figure on any screen drills down to the journal lines that produced it, and from there to the source document and the user who created it. If a number cannot be explained, it is a bug, not a rounding difference.

### What PeakFlow is not

Being explicit about this early is what keeps the scope honest:

- **Not a POS.** There is no cash drawer, no shift, no barcode scanner at a till. Point-of-sale retail is [Hawalaty](https://github.com/mostfaswaisi93/hawalaty-laravel)'s problem; PeakFlow consumes POS output as a sales journal if you want it to.
- **Not inventory.** Items exist as billable lines with a classification code and a tax treatment. There is no stock ledger, no costing, no batches. A `cost_of_sales` posting can be imported, not derived.
- **Not payroll.** Salaries arrive as a journal import or a bill, not as a payroll calculation. Payroll tax tables are a jurisdiction minefield and are deliberately out of scope.
- **Not a bank.** PeakFlow reads statements and emits payment instruction files. It never holds funds and never moves money by itself - a human or a bank's own channel does that.
- **Not an audit opinion.** It produces the records an auditor reads. It does not sign them.

## Architecture

### The three planes

The codebase is split into three planes that never share data. Hawalaty proved two planes works; PeakFlow needs a third, because an invoice that must reach a government clearance platform has traffic that belongs to neither the operator nor the tenant.

| Plane | Database connection | Who uses it | Namespace root |
|-------|---------------------|-------------|----------------|
| **Platform** | `platform` (the operator's own DB) | Platform operators | `App\Platform\*` |
| **Tenant** | `tenant` (dynamic, resolved per request) | An organization's users | `App\Tenant\*` |
| **Exchange** | `platform` + per-tenant outbox rows | No human - queue workers only | `App\Exchange\*` |

The **Exchange** plane is where documents leave and arrive: Peppol access-point calls, ZATCA clearance, bank file transfer, webhook delivery. It runs entirely on queues, holds its own retry and dead-letter state, and is the only part of the system allowed to talk to a third party about a tenant's document. Giving it its own plane means a government endpoint being down is a queue depth, never a 500 on a user's screen.

### Multi-tenancy - database per tenant

Each tenant has its **own physical PostgreSQL database**. A single `tenant` connection is declared in `config/database.php` whose settings are **rewritten at runtime, per request**, by `App\Platform\Services\TenantConnectionResolver`:

1. Resolve the `Tenant` from the session, the API token, or the `X-Tenant` header.
2. Reject it if the subscription is **suspended**, **expired** or **migrating**.
3. Read credentials from the tenant's `DatabaseConnection` row (password encrypted at rest with the app key, never logged).
4. `config(['database.connections.tenant' => ...])` then `DB::purge('tenant')`.
5. Bind the resolved tenant into the container as `currentTenant`, and the active entity as `currentEntity`.

Every tenant model hard-codes `getConnectionName(): 'tenant'`, so a tenant query can never accidentally read platform data even if a developer forgets the scope.

> **Why not a shared database with row-level security?** RLS is less operational work and it was seriously considered - see [`ADR-0003`](docs/architecture/decisions/ADR-0003-tenancy-model.md). It lost on one requirement: **per-tenant point-in-time restore**. An auditor asking "show me this company's books as they stood on 31 December" is answerable in minutes with a dedicated database and a WAL archive, and is a cross-table surgical nightmare in a shared one. A customer leaving must also be able to take a `pg_dump` of their own data and nothing else. RLS is still used - *inside* each tenant database, to scope rows to an entity as defence in depth behind the application's own entity scope.

### Multi-entity - many companies inside one tenant

This is the single biggest structural difference from a per-shop product, and it drives a lot of the schema.

A **tenant** is a customer of the platform. An **entity** is a legal company that files its own tax return. A group with a holding company, two operating companies and a free-zone subsidiary is **one tenant, four entities**.

An entity owns, and does not share:

| Owned per entity | Why it cannot be shared |
|------------------|-------------------------|
| Chart of accounts | Different statutory account codes per jurisdiction |
| Functional currency | IAS 21 - the currency of the primary economic environment |
| Fiscal calendar | A subsidiary can have a different year end |
| Tax registrations | One VAT number per registration, per country |
| Numbering series | Invoice sequences are legally per issuer |
| Journals and all postings | Books are per legal person |

Shared across the tenant: users, roles, the party master (a customer can be billed by two entities), item catalogue, currencies, FX rates, and documents. **Inter-entity transactions** post two mirrored journal entries and create a reciprocal pair of due-to / due-from balances that must net to zero in consolidation - a check the close process runs and refuses to pass silently.

Every tenant table that holds financial data carries a non-nullable `entity_id`. There is a Pest architecture test that fails the build if a new migration adds a table to the financial schema group without one, because "we'll scope it later" is how cross-company data leaks happen.

### Bounded contexts, and a compiler that enforces them

PeakFlow is a **modular monolith**: one deployable, twelve modules with explicit boundaries. Not microservices - a ledger whose postings are eventually consistent with the invoice that caused them is not a ledger.

| Module | Owns | May depend on |
|--------|------|---------------|
| `Identity` | Users, sessions, MFA, API tokens | - |
| `Tenancy` | Tenants, entities, subscriptions, provisioning | `Identity` |
| `Ledger` | Accounts, journals, entries, periods, dimensions | `Tenancy` |
| `Parties` | Customers, suppliers, contacts, bank details | `Tenancy` |
| `Catalog` | Items, price lists, units, classification codes | `Tenancy` |
| `Tax` | Tax codes, rates, rules, returns | `Tenancy`, `Ledger` |
| `Sales` | Quotes, orders, invoices, credit notes, dunning | `Ledger`, `Parties`, `Catalog`, `Tax` |
| `Purchasing` | Purchase orders, bills, expense claims, approvals | `Ledger`, `Parties`, `Catalog`, `Tax` |
| `Banking` | Bank accounts, statements, reconciliation, payment files | `Ledger`, `Parties` |
| `Settlement` | Payments, allocations, FX gain/loss, write-offs | `Ledger`, `Sales`, `Purchasing`, `Banking` |
| `Reporting` | Read models, statements, aging, drill-down | everything, read-only |
| `Exchange` | Peppol, clearance, webhooks, file transfer | `Sales`, `Purchasing` |

The dependency rules above are **compiled, not documented**: [`deptrac.yaml`](deptrac.yaml) declares each module as a layer and the table above as its ruleset, and CI fails on a violation. `Reporting` is allowed to read every module and is forbidden from writing to any. `Ledger` depends on nothing but `Tenancy`, which is the whole point - the ledger must not know what an invoice is.

Modules talk through **domain events** and **application services**, never by reaching into each other's Eloquent models. `Sales` does not insert a journal line; it dispatches `InvoiceIssued`, and a `Ledger` listener posts the entry from a **posting rule** it owns. Which means the posting rules for a jurisdiction can change without `Sales` being touched.

### Request lifecycle

The tenant and the entity are both resolved **before** authentication runs, and that order is load-bearing: the user table lives in the tenant database, so a guard that ran first would query the wrong one.

**Web (session cookie):**
```
Request → SetLocale → tenant.session → entity.scope → auth:tenant → [plan] → [permission] → Livewire/controller
          │           │                │              │
          │           │                │              └─ authenticates the User against the tenant DB
          │           │                └─ binds currentEntity; refuses an entity the user has no role in
          │           └─ points the `tenant` connection at the right database
          └─ resolves locale + direction from the user, then the tenant default, then the Accept-Language header
```

**API (bearer token):**
```
Request → throttle:api → idempotency → tenant.header → entity.header → auth:sanctum → [permission] → controller
                         │              │
                         │              └─ reads X-Tenant / X-Entity
                         └─ replays a stored response for a repeated Idempotency-Key instead of double-posting
```

**Platform (operator):**
```
Request → SetLocale → auth:platform → [operator-ability] → controller
                      └─ authenticates an Operator against the platform DB. /ops/* never touches a tenant connection.
```

Laravel sorts middleware by a priority list, so both resolvers are pinned **ahead of** the auth middleware in `bootstrap/app.php` via `prependToPriorityList(...)`. The same file makes the unauthenticated redirect guard-aware: the API gets an RFC 9457 `problem+json` 401, `/ops/*` redirects to the operator login, everything else to the tenant login.

If a subscription becomes invalid *between* page loads, the web resolver tears down the session and redirects to a dedicated page rather than letting a stale connection fail the next query. If a tenant database is mid-migration, the resolver returns `503` with a `Retry-After` - a maintenance window is a documented state, not an error.

### Authentication

| Guard | Driver | Provider | Used for |
|-------|--------|----------|----------|
| `platform` | session | `operators` (platform DB) | Operator console (`/ops/*`) |
| `tenant` | session | `users` (tenant DB) | The application, web |
| `sanctum` | token | resolved per plane | REST API, both planes |

On top of the guard:

- **Passkeys (WebAuthn, FIDO2)** are the preferred second factor and can be the only factor. TOTP (RFC 6238) is the fallback; SMS is deliberately not offered.
- **MFA is mandatory, not optional, for any user holding a permission that moves money** - releasing a payment run, posting a journal, closing a period. The permission cannot be granted to a user without an enrolled factor; the UI explains that rather than failing on use.
- **Step-up authentication** is required again, at the moment of the act, for releasing a payment file and for unlocking a closed period - a stolen session must not be enough.
- Sessions are bound to a device fingerprint, and a session's entity scope is re-checked on every request rather than trusted from the cookie.

### Authorization, and segregation of duties

Role-based permissions are the floor. The part that matters in finance is the part above it.

**Permissions** are fine-grained and verb-shaped (`invoice.issue`, `payment.release`, `period.close`, `journal.post`, `bank.reconcile`). Roles bundle them. A user holds roles **per entity** - a group accountant can post in all four companies, a subsidiary's clerk in one.

**Segregation of duties** is a separate layer, and it answers a different question. A permission answers *may this user do X*. An SoD rule answers *may the same user do X and then Y to the same object*. The two are not the same, and conflating them is the classic finance-software hole: a clerk who may both create a supplier and release a payment can pay themselves.

PeakFlow declares SoD as **incompatible duty pairs** evaluated at the moment of the second act, against the audit trail of the first:

| Duty A | Duty B | Why they must differ |
|--------|--------|----------------------|
| `supplier.create` / `supplier.bank_details.update` | `payment.release` | Redirect a payment to an account you created |
| `bill.create` | `bill.approve` | Approve your own invoice |
| `payment.prepare` | `payment.release` | Maker-checker on outgoing funds |
| `journal.post` | `period.close` | Hide a posting by closing over it |
| `credit_note.issue` | `invoice.issue` *(same invoice)* | Write off a receivable you created |
| `reconciliation.match` | `reconciliation.write_off` | Clear a difference into expense unreviewed |

A conflict is **refused, logged, and explained** - the message names the conflicting act, who performed it and when, so the user knows who to ask rather than guessing. A tenant with three staff who genuinely cannot separate two duties may record a **documented override** with a reason, which is itself an audit event and appears on a standing exceptions report. Silently allowing it, or silently blocking it, are both worse.

### Everything financial is append-only

| Object | Can be edited? | Correction mechanism |
|--------|----------------|----------------------|
| Draft invoice / bill | Yes | Edit freely; it has not posted |
| Issued invoice | **No** | Credit note, then re-issue |
| Posted journal entry | **No** | Reversing entry, linked both ways |
| Payment allocation | **No** | Unallocate (a reversing posting), then re-allocate |
| Bank transaction | **No** | Imported facts are facts; re-match, never rewrite |
| Reconciliation | **No** | Unreconcile, which records who and why |
| Closed period | **No** | Reopen, which requires step-up auth and writes an audit event |

Deletes are soft everywhere, and `DELETE` on a posted financial row is revoked at the **database role** level, not just guarded in the application. An ORM cannot be trusted to protect an invariant that a migration, a console command or a future developer's `tinker` session can reach around.

## Money, precision and rounding

This section is first among the domain sections on purpose. Get it wrong and every other number in the system inherits the error.

### Why not floats, and why not minor-unit integers either

**Floats are disqualified outright.** IEEE 754 binary floating point cannot represent `0.1`. `0.1 + 0.2 != 0.3`. Summing a ledger of 400,000 lines accumulates a drift that is small, non-deterministic and impossible to explain to an auditor. There is no float anywhere in PeakFlow's money path - not in a column, not in a cast, not in a JSON response.

**Minor-unit integers** - store 1,234.56 as `123456` - are the usual next answer, and they are *nearly* right. PeakFlow rejects them too, for three concrete reasons:

1. **The exponent is not 2.** ISO 4217 assigns each currency a minor unit, and it is not always two. `BHD`, `JOD`, `KWD`, `OMR`, `TND`, `LYD` and `IQD` have **three**. `JPY`, `KRW`, `VND`, `CLP` and `ISK` have **zero**. So an integer column is meaningless without its currency, and every single read has to divide by a per-currency power of ten. One forgotten division is a 1000x error in a Jordanian dinar amount, and it will look plausible.
2. **Two currencies do not have decimal subunits at all.** `MGA` (ariary = 5 iraimbilanja) and `MRU` (ouguiya = 5 khoums) are base-5. No power of ten represents them.
3. **Unit prices need more precision than amounts.** A price of `0.0725` per unit is normal in utilities, telecoms and fuel. Minor units force you to pick one scale for both, and either the price or the total loses.

**PeakFlow stores money as PostgreSQL `numeric`** - arbitrary-precision exact decimal - and does arithmetic in PHP through [`brick/money`](https://github.com/brick/money) with an explicitly named rounding context on every operation. `numeric` is exact, `SUM()` over it is exact, and the database refuses to coerce it to a float. The cost is that `numeric` is slower than `bigint`; the benchmark that matters is a trial balance over ten million lines, and a covering index on `(entity_id, period_id, account_id)` with `numeric` sums wins against a correctness bug every time.

> **`decimal` in a Laravel cast is a string.** `'balance' => 'decimal:4'` returns `"1234.5600"`, a PHP string. That is the correct behaviour and it is deliberate - the moment a money value becomes a PHP `float` it is already wrong. Do not cast to `float` to "make the maths easier". Use `Money::of($string, $currency)`.

### The three decimal scales

Exactly three scales exist in the schema, and a migration that introduces a fourth fails the architecture test:

| Scale | Column type | Used for | Why |
|-------|-------------|----------|-----|
| **Amount** | `numeric(21,4)` | Journal line amounts, invoice totals, tax subtotals, payments, balances | Four decimals absorbs the three-decimal currencies plus one guard digit, and the presentation layer rounds to the currency's own exponent for display |
| **Unit price** | `numeric(21,8)` | Item prices, line unit prices, per-unit tax | Eight decimals covers per-litre, per-kWh, per-minute and per-thousand pricing without a scaling factor |
| **Rate** | `numeric(21,12)` | FX rates, tax percentages, allocation ratios | Twelve decimals is enough that a round trip through an inverse rate does not drift. FX rates are also stored with their quotation direction, never "inverted when needed" |

`numeric(21,4)` holds 17 integer digits - about 10 quadrillion - which is past any real general ledger and well inside `numeric`'s limits.

### Where rounding happens, exactly once

Rounding twice is how a document's lines stop adding up to its total. The rule is that **a value is rounded when it becomes a legal amount, and never again.**

```
unit price (8dp) × quantity (6dp)
        ↓  round HALF_UP to 4dp  ──→  line net amount        ← rounded here, once
line net × tax rate (12dp)
        ↓  accumulate unrounded per (tax category, rate)
        ↓  round HALF_UP to 4dp  ──→  tax subtotal per rate  ← rounded here, once
Σ line nets ────────────────────────→ document net           ← addition of rounded values; not re-rounded
Σ tax subtotals ───────────────────→ document tax            ← addition of rounded values; not re-rounded
net + tax ──────────────────────────→ document gross
        ↓  round to currency exponent only for *display and payment*
```

Three consequences that are easy to get wrong and are each covered by a test:

- **Tax is computed per rate group, not per line and not per document.** EN 16931 requires a `VAT BREAKDOWN` (BG-23) with one entry per unique combination of tax category and rate, and the document's tax total is the sum of those entries - rule `BR-CO-14`. Rounding each line's tax and summing gives a different answer, and in most jurisdictions the wrong one.
- **The displayed total is a rounded view, not a stored second total.** A `JPY` invoice shows `¥1,235`; the stored amount is `1234.5600`. Storing the display value is how a ledger stops balancing.
- **A payment rounding difference is posted, not absorbed.** When a customer pays 999.99 against 1,000.00 and the tenant's policy says settle it, the 0.01 goes to a configured `rounding_difference` account as its own journal line. It does not quietly shrink the receivable, and the tolerance is a per-entity setting with a maximum the operator sets.

> **Half-up, not banker's rounding, and this is a jurisdiction setting rather than a preference.** EN 16931 and most VAT regimes specify half-up (round half away from zero). IEEE 754 default and many programming languages default to half-even ("banker's"). `brick/money` requires the mode to be passed explicitly, which is exactly why it was chosen over a hand-rolled helper - there is no default to get wrong silently.

## The general ledger

### Chart of accounts

An entity's chart of accounts is a tree of `accounts`. Each carries:

- A **code** - unique per entity, sortable, and the thing accountants actually use
- A **type**: `asset`, `liability`, `equity`, `revenue`, `expense`. The type decides the natural sign, whether the account closes at year end, and which statement it lands on
- An **IFRS statement classification** - which line of the Statement of Financial Position or Profit or Loss it rolls up to, so a statement is a grouping rather than a hand-built report
- Flags: `postable` (a header account is not), `requires_dimension`, `reconcilable` (bank and control accounts), `blocked`
- An optional **control-account role**: `accounts_receivable`, `accounts_payable`, `vat_input`, `vat_output`, `bank`, `fx_gain_loss`, `rounding_difference`, `suspense`, `retained_earnings`, `inter_entity`

**Control accounts cannot be posted to by hand.** A journal entry that names the AR control account is refused unless it came from a `Sales` or `Settlement` posting rule. This is the invariant that keeps the AR subledger equal to its control account - the reconciliation every month-end close starts with, and the one that is impossible to restore once a human has posted a manual adjustment into it.

Charts are seeded from **templates** per jurisdiction so a new entity opens with a usable, statutory-shaped chart rather than an empty tree.

### Journals, entries and lines

| Table | Holds |
|-------|-------|
| `journals` | The books: `sales`, `purchases`, `cash`, `bank`, `general`, `opening`, `closing`. Each has a numbering series and a default posting behaviour |
| `journal_entries` | Header: entity, journal, entry number, accounting date, period, narration, source document reference, status, created-by, posted-by, posted-at |
| `journal_lines` | Account, signed amount, currency, FX rate, functional amount, dimensions, party, tax code, line narration |

An entry's **status** is `draft` → `posted` → (`reversed`). There is no `deleted`. A draft entry has no effect on any balance and may be edited; posting is the irreversible act, and it stamps the period.

### The balanced-entry invariant is a database constraint

PeakFlow stores a **single signed `amount`** per line rather than separate `debit` and `credit` columns. Debits are positive, credits negative, and the invariant is simply:

```
SUM(functional_amount) = 0  per journal entry
```

One constraint instead of two, no "which column did this go in" bugs, and `SUM()` over any slice of the ledger is directly meaningful. The UI still renders Dr/Cr columns, because that is what accountants read - the presentation splits the sign, the storage does not.

**The constraint is enforced in PostgreSQL, deferred to commit:**

```sql
CREATE CONSTRAINT TRIGGER journal_entry_must_balance
    AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION assert_entry_balanced();
```

> **`DEFERRABLE INITIALLY DEFERRED` is the whole trick.** An entry is *transiently unbalanced* while its lines are being inserted - after the first line and before the last, the sum is not zero. A normal trigger fires per statement and would reject every multi-line entry ever written. A deferred constraint trigger fires once at `COMMIT`, which is the only moment the invariant is meaningful. Getting this wrong and then "fixing" it by validating in PHP is how an unbalanced entry eventually reaches the database through a console command.

Two further things the database, not the application, refuses:

- **Posting into a closed period.** A trigger on `journal_entries` checks the target period's status. The application checks it too, for a good error message; the database checks it because a backfill script will not.
- **Mutating a posted entry.** `UPDATE` and `DELETE` on `journal_entries` and `journal_lines` where `status = 'posted'` raise an exception. The application's `migrator` role is the only role permitted to bypass it, and it is not the role the app connects as.

**Multi-currency balances in functional currency, not in transaction currency.** Each line stores the transaction-currency amount, the FX rate and the functional-currency amount. The zero-sum invariant applies to the **functional** amount only. A single entry recording a USD receipt against an EUR invoice is correctly unbalanced in both USD and EUR and must balance in the entity's functional currency - the difference being the FX gain or loss line. Asserting the invariant on the transaction currency is a bug that only appears once a customer pays in a foreign currency, which is to say in production.

### Periods, closing and locking

| State | Postings allowed | Who can change it |
|-------|------------------|-------------------|
| `future` | No | - |
| `open` | Yes | - |
| `soft_closed` | Only by a user with `period.post_adjusting` | Controller |
| `closed` | No | Reopening needs `period.reopen` + step-up auth + a reason |
| `locked` | No, ever | Nobody. Set after statutory filing; irreversible by design |

Fiscal years are per entity and need not be calendar years. A year can have 12 monthly periods, 13 four-weekly ones, or a 53-week retail calendar. An **adjusting period 13** that carries no calendar days exists so audit adjustments land after December without re-dating them into it.

**Year-end close** posts a closing entry that zeroes every revenue and expense account into `retained_earnings`, and an opening entry in the new year for balance-sheet accounts. Both are ordinary journal entries in reserved journals - visible, drillable and reversible if the close was wrong - rather than a flag on a row.

### Dimensions

Beyond the account, a line may carry **dimensions**: cost centre, project, department, branch, fund, or a tenant-defined axis. Dimensions are what make a P&L by project possible without inventing a thousand accounts.

- Up to **eight** dimension axes per entity, each a tenant-defined list with its own hierarchy
- An account can **require** a dimension; the posting is refused without it
- Dimensions are stored as columns on `journal_lines`, not as a JSON blob or an EAV side table, because every financial report filters and groups by them and an index on a JSON path is not a substitute
- **Validity rules** can forbid a combination - a project that belongs to one entity cannot be used by another

## Invoicing

### The document lifecycle

```
quote ──accept──► order ──invoice──► invoice ──┬──► paid
  │                 │                          ├──► partially paid
  └──expire         └──cancel                   ├──► overdue ──► dunning ──► written off
                                                └──► credited (credit note)
```

| Document | UNCL 1001 | Posts to the ledger? |
|----------|-----------|----------------------|
| Quote / estimate | - | No |
| Sales order | - | No (optionally a commitment, never a posting) |
| **Commercial invoice** | `380` | Yes, on issue |
| **Credit note** | `381` | Yes, on issue |
| **Debit note** | `383` | Yes, on issue |
| **Prepayment invoice** | `386` | Yes - to a liability, not revenue |
| **Self-billed invoice** | `389` | Yes |
| **Corrected invoice** | `384` | Yes, with a reference to what it corrects |

A **draft** invoice is a working document: editable, no number, no posting, no effect. **Issuing** is a single transaction that allocates the number, computes and freezes the totals, writes the hash chain link, posts the journal entry and emits `InvoiceIssued`. It either all happens or none of it does.

**What gets frozen at issue**, and why each one matters: the party's legal name and address (a customer who moves must not retroactively change an issued invoice), the tax registration numbers of both parties, the tax rates applied, the FX rate, the entity's own legal identity, and the payment terms. An issued invoice is a historical statement, so it carries its own copies rather than foreign keys to things that can change.

### Numbering is gapless, and that is harder than it looks

Most jurisdictions require invoice numbers to be **sequential with no gaps** per issuer per series. A gap is a question an auditor will ask, and "the transaction rolled back" is not an answer that satisfies a tax authority.

**A PostgreSQL `SEQUENCE` cannot do this, by design.** `nextval()` is deliberately non-transactional - it does not lock, and a rollback does not give the number back. That is exactly what you want for a surrogate key and exactly wrong for a legal sequence. Using `nextval()` here produces gaps on every validation failure, deadlock and crash.

The allocation is instead a row lock on the series:

```sql
SELECT next_number FROM number_series
 WHERE entity_id = $1 AND series_id = $2 AND period_year = $3
   FOR UPDATE;              -- serializes concurrent issuers on this series only
UPDATE number_series SET next_number = next_number + 1 WHERE ...;
```

Three rules make this safe in practice:

1. **Allocate last.** The number is taken at the very end of the issuing transaction, after validation, tax computation and the posting rule have all succeeded. Holding the series lock for the length of a slow transaction serializes every concurrent issuer behind it.
2. **Never do I/O inside the lock.** No PDF rendering, no HTTP call to a clearance platform, no mail. Those happen after commit, from a queued job keyed on the invoice. A tax authority that takes 30 seconds to answer must not hold the invoice sequence for 30 seconds.
3. **One series, one lock.** Series are per entity, per document type, per year, and optionally per branch - so two entities, or two document types, never contend.

> The concurrency test for this issues 200 invoices from 20 parallel workers against one series and asserts the resulting numbers are exactly `1..200` with no gaps and no duplicates. It is the single most important test in the suite, and it is the one that fails if someone "optimizes" the allocation by moving it earlier.

Formats are templated per series - `INV-{entity}-{YYYY}-{seq:6}` - and the format is **frozen once the series has issued its first document**, because changing the shape of a sequence mid-year breaks its own gaplessness.

### Tamper evidence - the hash chain

Each issued document stores:

- `canonical_hash` - SHA-256 over a canonicalized representation of the document's legally significant fields (C14N for the XML syntaxes)
- `previous_hash` - the `canonical_hash` of the previously issued document in the same series
- `chain_index` - its position

This makes insertion, deletion and alteration detectable: changing any document invalidates every hash after it, and removing one breaks the link. It is the mechanism behind ZATCA's Previous Invoice Hash (PIH) and is applied in every jurisdiction profile because it costs nothing and answers "has this ledger been edited" definitively. A console command re-walks the chain and reports the first divergence; it runs nightly and on demand before an audit.

The chain is **not** a signature - it proves internal consistency, not authorship. Where a jurisdiction requires a cryptographic seal (eIDAS qualified seal, ZATCA's cryptographic stamp), that is a separate signing step performed in the `Exchange` plane with a key held in a KMS or HSM and never in the database.

### Recurring billing and revenue recognition

**Recurring invoice schedules** carry a template, a cadence (RFC 5545 `RRULE`, so "last working day of every quarter" is expressible), a next-run date, proration rules, an end condition, and a dry-run preview. Generation is idempotent on `(schedule_id, occurrence_date)` - a scheduler that fires twice cannot bill twice.

**Revenue recognition follows IFRS 15.** An invoice is a billing event; revenue is recognized when the performance obligation is satisfied, and the two are frequently not the same month. A line may carry a **recognition schedule** - straight-line over a service period, on milestones, or on delivery - which posts to `deferred_revenue` on issue and releases to `revenue` on a schedule. The recognition run is a monthly process that posts one entry per period and is re-runnable without double-posting.

Prepayment invoices are handled the same way and for the same reason: cash received before performance is a liability, not revenue.

### Dunning

Overdue receivables escalate through a **dunning policy**: a sequence of steps, each with an offset in days past due, a channel (email, in-app, PDF letter), a template, and optional actions - apply a late fee, place the customer on credit hold, escalate to a collections owner.

Two things that distinguish a usable dunning engine from an annoying one: it is **evaluated per invoice but sent per customer**, so a customer with six overdue invoices gets one statement rather than six emails; and it **respects a dispute flag**, so an invoice the customer has formally queried stops escalating and appears on a dispute report instead.

## E-invoicing and compliance

### One semantic model, many syntaxes

The mistake to avoid is building for one country's XML. PeakFlow holds **one internal semantic invoice model** aligned to **EN 16931** - the European standard that defines the *meaning* of an invoice as numbered business terms (`BT-1` invoice number, `BT-2` issue date, `BT-112` invoice total with VAT, and so on) grouped into business groups (`BG-*`) - and then **serializes** that model into whatever syntax a recipient needs.

```
                       ┌─► UBL 2.1 (OASIS / ISO 19845)  ─► Peppol BIS Billing 3.0
  PeakFlow invoice     ├─► UN/CEFACT CII D16B           ─► Factur-X / ZUGFeRD 2.3
  (EN 16931 semantics) ├─► PDF/A-3 + embedded XML       ─► archival + human copy
                       ├─► ZATCA UBL subset + QR (TLV)  ─► KSA Fatoora clearance
                       └─► JSON (the REST API's shape)  ─► integrations
```

Mapping the internal model to EN 16931 business terms is written down term by term in [`docs/standards/en16931-mapping.md`](docs/standards/en16931-mapping.md), including which terms are mandatory, which are conditional and on what, and which PeakFlow deliberately does not populate. A field with no `BT` number and no business reason does not get added to the invoice.

> **The semantic layer earns its keep the first time a second country is added.** A tenant selling into the EU and to Saudi Arabia needs Peppol BIS and ZATCA, which disagree about almost everything at the XML level and agree about nearly everything at the meaning level. Two serializers over one model is a week's work; two invoice systems is a product.

### Validation is a pipeline, not a checkbox

A document is validated in **four ordered stages**, and it must pass all four before it can be sent. Each stage catches a different class of error, and running them in the wrong order produces unhelpful messages:

| Stage | Checks | Tooling |
|-------|--------|---------|
| 1. **Internal** | Business rules PeakFlow owns: totals add up, party has a tax registration where required, period is open, currency is permitted | PHP, in the issuing transaction |
| 2. **Syntax (XSD)** | The XML is structurally valid against the UBL 2.1 or CII schema | `libxml` schema validation |
| 3. **Semantic (Schematron)** | The ~160 EN 16931 business rules - `BR-*` (core) and `BR-CO-*` (calculation), e.g. `BR-CO-10`: sum of line nets must equal `BT-106` | Schematron, compiled to XSLT |
| 4. **Profile (Schematron)** | The recipient's own rules on top: Peppol BIS `PEPPOL-EN16931-*`, or a national CIUS/extension | Schematron per profile |

Validation artefacts are **vendored and version-pinned** in the repository, not fetched at runtime. A government updating its Schematron on a Tuesday must not silently change what PeakFlow accepts on Wednesday - the update is a pull request with a test-suite diff, so a newly failing rule is visible before it reaches a customer.

Every validation failure is reported as a **business-term-addressed** error: `BT-112` rather than `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount`. Users do not read XPath.

### Jurisdiction profiles

A **profile** bundles everything country-specific behind one interface, so adding a country is a new profile class plus its fixtures - never a change to `Sales`:

```php
interface JurisdictionProfile
{
    public function documentTypes(): array;           // which UNCL 1001 codes are legal
    public function numberingRules(): NumberingRules; // gapless scope, format constraints
    public function taxEngine(): TaxRuleSet;          // rates, categories, place of supply
    public function roundingPolicy(): RoundingPolicy; // per-line vs per-group, direction
    public function serializer(): DocumentSerializer; // UBL / CII / national subset
    public function validators(): array;              // ordered Schematron chain
    public function transport(): ?Transport;          // Peppol AP, clearance API, or none
    public function sealing(): ?SealingPolicy;        // signature / cryptographic stamp
    public function archivePolicy(): ArchivePolicy;   // format, retention years, immutability
    public function qrPolicy(): ?QrPolicy;            // payload spec and placement
}
```

Shipping targets, in build order, chosen so the hardest constraint is met early rather than discovered late:

| Profile | Standard | Transport | Notable constraint |
|---------|----------|-----------|--------------------|
| **Generic EN 16931** | EN 16931 + UBL 2.1 | File / email | The baseline every other profile specializes |
| **Peppol BIS Billing 3.0** | EN 16931 CIUS | Peppol access point (AS4) | Participant lookup via SML/SMP; EAS-coded identifiers |
| **ZATCA (Saudi Arabia)** | ZATCA UBL subset | Clearance / reporting API | Cryptographic stamp, PIH chain, TLV QR, 24h reporting window |
| **Factur-X / ZUGFeRD 2.3** | CII D16B in PDF/A-3 | Email | The PDF *is* the invoice; the XML is an attachment and both must agree |
| **Egypt ETA** | ETA JSON | ETA API | Signed JSON, GS1/EGS item coding |
| **Jordan JoFotara** | UBL subset | National portal | Three-decimal currency (`JOD`), Arabic-mandatory fields |

> **Archiving is part of compliance, not an afterthought.** Most jurisdictions require the *original* electronic document to be retained, readable and unaltered, for 5-11 years. PeakFlow stores the exact bytes that were sent - XML, signature and PDF - in object storage with **object-lock / WORM** retention and a checksum manifest, and a restore drill is a scheduled task rather than a hope. A re-rendered PDF is not an archive copy.

## Banking

### Bank accounts and their GL mirror

A `bank_account` is a real account at a real institution and it carries, with validation rather than as free text:

- **IBAN** - validated for length per country and MOD-97-10 check digits (**ISO 13616**, **ISO/IEC 7064**)
- **BIC** - 8 or 11 characters, structurally validated (**ISO 9362**)
- Domestic identifiers where IBAN does not apply: sort code, routing number, BSB, IFSC
- **Currency** (ISO 4217), the institution, the branch, the holder's legal name
- A **1:1 link to a GL account** with the `bank` control role

> **The GL account is the bank account's mirror, and the distinction between them is the entire basis of reconciliation.** The GL account holds what PeakFlow *believes*; the statement holds what the bank *says*. Reconciliation is the process of explaining the difference, and a design that stores one balance for both has deleted the question. Three balances are therefore tracked and always displayed together: **ledger balance** (posted GL), **statement balance** (last imported closing balance), and **available balance** (statement minus unpresented payments).

Bank account details are **encrypted at rest at the column level**, separately from database-level encryption, and are **audited on change**: the SoD rule pairing `supplier.bank_details.update` against `payment.release` exists because changing a beneficiary's account is the highest-value attack in the entire product. A changed beneficiary account triggers re-verification before the next payment to it, and the previous value is retained in the audit trail.

### Statement ingestion

| Format | Standard | Notes |
|--------|----------|-------|
| **CAMT.053.001.08** | ISO 20022 | The target format. Booked and pending entries, structured remittance, bank transaction codes |
| **CAMT.052 / CAMT.054** | ISO 20022 | Intraday report and debit/credit notification, for same-day cash position |
| **MT940 / MT942** | SWIFT FIN | Legacy, still ubiquitous. Lossy - `:86:` is semi-structured free text and parsing it is bank-specific |
| **OFX / QFX** | OFX 2.x | Retail and North American banks |
| **CSV** | - | Per-bank column mapping, saved as a reusable template with a preview |

Ingestion rules that each exist because of a specific failure mode:

- **A statement is a numbered, continuous object.** Each import asserts that its opening balance equals the previous statement's closing balance for that account. A mismatch stops the import and reports the gap rather than importing a hole - the alternative is a reconciliation that can never be completed and nobody knows why.
- **Imports are idempotent.** A transaction is keyed on `(bank_account, bank_reference)` where the bank supplies one, and otherwise on a content hash of `(value_date, amount, currency, counterparty, remittance)`. Re-importing an overlapping file is a no-op, because people re-download overlapping files constantly.
- **Bank transactions are immutable once imported.** They are a third party's assertion. Enrichment - the counterparty we think it is, the category we guessed - lives in separate nullable columns, never by rewriting the imported value.
- **Booking date and value date are both kept.** Interest, FX and cash forecasting use the value date; the GL posts on the booking date. Collapsing them into one "date" is a bug that surfaces at month end.
- **ISO 20022 bank transaction codes** (`domain/family/sub-family`, e.g. `PMNT/RCDT/ESCT`) are retained and drive the default matching strategy - a `PMNT/ICDT/ESCT` outgoing SEPA credit transfer is matched against payment runs, a `PMNT/RCDT` incoming against receivables.

### Reconciliation - the matching engine

Reconciliation runs in passes, cheapest and most certain first. Each pass proposes matches with a **confidence score** and a human-readable reason; nothing auto-posts above the tenant's configured confidence threshold without being auditable, and nothing below it is hidden.

| Pass | Strategy | Typically matches |
|------|----------|-------------------|
| 1 | **Exact reference** - a payment reference, invoice number or end-to-end ID found in the remittance field | Most B2B receipts |
| 2 | **Payment-run match** - an outgoing amount equal to a released batch's total, or to a line within it | AP payments |
| 3 | **Exact amount + party + date window** | Single-invoice receipts |
| 4 | **Structured remittance** - ISO 20022 `RmtInf/Strd` referenced documents | Modern SEPA/Peppol-aware payers |
| 5 | **Subset-sum** - one credit against a combination of open invoices, bounded and tie-broken by oldest-first | Customers paying several invoices in one transfer |
| 6 | **Rule-based** - tenant-authored rules on counterparty, code, amount range → GL account + dimensions | Bank fees, interest, standing charges, payroll |
| 7 | **Fuzzy party** - normalized name distance on the counterparty, proposed only, never auto-applied | Messy retail payers |

Beyond matching:

- **Splits in both directions**: one bank line across many invoices and many bank lines against one invoice.
- **Unmatched lines land in a suspense account** rather than nowhere. Suspense ages, appears on a dashboard, and an entity cannot close a period with a non-zero suspense balance unless a controller signs it off with a reason.
- **Differences are classified before they are written off**: bank charge, FX difference, short payment, overpayment, rounding. Each classification has its own posting rule and its own tolerance, and the tolerance is a setting with a ceiling - "write off anything under 50" is a policy decision, not a developer's constant.
- **A reconciliation is a document.** It has a statement period, a closing balance, an authorizing user, a timestamp, and the full set of matches. It can be unreconciled - with a reason, which is audited - and it is what an auditor re-performs.
- **Learning, not guessing.** When a user corrects a proposed match, the correction is stored as a rule candidate and offered back as a suggested rule. The engine never silently changes its own behaviour.

### Payment initiation

PeakFlow **never moves money**. It produces an instruction file that a human releases into the bank's own channel. That boundary is deliberate and is stated in the UI, because a product that appears to send money carries a duty of care it cannot discharge.

```
select approved bills ─► payment run (draft) ─► prepare ─► [maker-checker] ─► release ─► PAIN.001 file
                                                                │                          │
                                                                │                          └─► bank channel (human)
                                                                └─ SoD: preparer ≠ releaser; step-up auth on release
```

- **PAIN.001.001.09** (customer credit transfer initiation) for outgoing batches; **PAIN.008** for direct debit collections where a mandate exists; **PAIN.002** status reports ingested to update each instruction.
- **SEPA SCT / SCT Inst** rulebook constraints are validated *before* the file is produced - IBAN reachability, character set (SEPA permits a restricted Latin set, so an Arabic beneficiary name must be transliterated and the user must see what will be sent), 140-character remittance limit, amount ceilings.
- A payment run is **immutable once released** and carries its own GL posting: bills move from AP to a `payments_in_transit` account on release, and clear to the bank account when the statement line reconciles. The in-transit account is what makes the three-way tie between AP, the bank and the GL possible.
- Duplicate-payment detection runs before release: same beneficiary, same amount, within a configurable window, warns and requires an acknowledgement.

### Cash position and forecast

A dated cash view per entity and consolidated, in any presentation currency: actual balances today, then expected movements forward - receivable due dates weighted by each customer's historical payment behaviour, payable due dates, recurring commitments, released-but-unpresented payments, and manual expectations. It answers *will we be short in week six*, and it shows which assumptions drive the answer rather than producing one number.

## Payments, allocation and FX

A **payment** is cash moving. An **allocation** is the act of saying which obligation it settles. They are separate objects because they are separate events, often days apart and performed by different people.

- Cash can be received with **no allocation at all** - that is `unapplied_cash`, a liability, and it is correct. Forcing an allocation at receipt time is how payments get applied to the wrong invoice to make a screen close.
- An allocation can be **partial**, across **many invoices**, and reversed. Reversal is a new posting, not a delete.
- **Over-payment** becomes a customer credit balance, usable against future invoices or refundable.
- **Write-offs** are a permissioned act with a reason code, posting to a bad-debt expense account, and are separated by SoD from both invoicing and reconciliation. Under **IFRS 9**, expected credit loss provisioning on a receivable aging matrix is supported as a periodic posting - a provision is an estimate and lives in its own account, distinct from a write-off, which is a conclusion.

**FX follows IAS 21**, and this is where most systems get vague:

| Event | Rate used | Posting |
|-------|-----------|---------|
| Invoice issued in a foreign currency | Spot on the **tax point date** | Receivable in transaction currency, converted at that rate |
| Payment received | Spot on the **value date** | Difference between the receivable's booked functional value and the payment's is a **realized** FX gain or loss |
| Period end, open balance | **Closing rate** | **Unrealized** revaluation on monetary items only, to a separate account, reversed and recomputed each period rather than accumulated |
| Consolidation into a group presentation currency | Closing rate for the statement of financial position, average for profit or loss | Translation difference to **other comprehensive income**, not to profit or loss |

Rates are **effective-dated, sourced and stored with their provider and direction**. A rate is never inverted on the fly - `USD/EUR` and `EUR/USD` are stored separately if both are used, because `1/x` on a rounded rate does not round-trip and a one-cent difference in a consolidation is a question someone will ask. Multiple rate sets coexist: a daily market rate, a monthly average, and a statutory central-bank rate for tax purposes, because tax authorities frequently mandate their own.

## The tax engine

Tax is configuration, not code. A rate that changes on a date must be a row.

- **Tax codes** carry a **category** (UNCL 5305: `S` standard, `Z` zero-rated, `E` exempt, `AE` reverse charge, `O` out of scope, `K` intra-community supply, `G` export) and a **rate**, and both are **effective-dated**. A rate change creates a new version; historical documents keep the rate that was in force on their tax point.
- **The tax point is its own date**, distinct from the invoice date and the delivery date, because jurisdictions differ on which one determines the period a transaction falls in - and a document issued on the 1st for a supply on the 30th belongs to the earlier return in many of them.
- **Place of supply** rules decide which jurisdiction's tax applies, from the parties' countries, their registration status, whether the item is a good or a service, and whether it is digital.
- **Reverse charge** reverses who accounts for the tax. The invoice carries no tax and a mandatory legal note; the recipient self-assesses. Modelled as a category, not as "zero rate with a comment".
- **Withholding tax** is deducted by the *payer* from the payment, not from the invoice. It therefore lives in the settlement path with its own certificate and its own reporting, and an invoice total that does not match the cash received is correct when withholding applies - a case that breaks naive allocation logic.
- **Tax returns** are generated as a period's box-by-box summary with full drill-down to the journal lines behind each box, a filed snapshot that is frozen on submission, and explicit handling for post-filing adjustments, which land in the next period with a reference rather than editing a filed return.

## Purchasing and accounts payable

| Document | Posts? | Notes |
|----------|--------|-------|
| Purchase requisition | No | Optional internal approval before committing |
| Purchase order | No | A commitment; optionally shown against budget |
| Goods / service receipt | Optional accrual | Confirms what arrived |
| **Bill (supplier invoice)** | Yes | AP + input tax + expense or asset |
| **Supplier credit note** | Yes | Reverses a bill |
| **Expense claim** | Yes | Employee out-of-pocket; posts to a payable to the employee |

**Three-way match** compares the bill against the purchase order and the receipt on quantity and price, within per-supplier tolerances, and routes a mismatch to an exception queue with the specific discrepancy named. **Approval workflows** are rule-driven on amount, account, dimension and supplier, with delegation, out-of-office reassignment and a full approval trail; an approval limit is per entity and per currency, converted at the approval date so the limit cannot be dodged by invoicing in a weak currency.

Incoming documents arrive by **email intake**, upload, or Peppol inbound through the `Exchange` plane, and are parsed - embedded XML where present, OCR as a fallback that proposes rather than asserts - into a draft bill a human confirms. **Duplicate bill detection** keys on `(supplier, supplier_invoice_number)` and warns on near-matches of amount and date, because paying the same invoice twice is the most common and most expensive AP error.

## Reporting

Reports are **queries over the journal**, not a parallel set of totals - with materialized read models where performance requires, rebuilt from the journal and verified against it by a nightly check that reports drift rather than hiding it.

**Statutory**, IFRS-shaped and drill-down on every figure, down to the source document and the user who created it:

- Trial balance (any date, any period, with and without adjusting entries)
- Statement of Financial Position - **IAS 1**
- Statement of Profit or Loss and Other Comprehensive Income - **IAS 1**
- Statement of Cash Flows - **IAS 7**, indirect and direct
- Statement of Changes in Equity
- General ledger detail, account activity, journal register

**Operational:**

- AR and AP aging, by bucket, with a configurable aging basis (due date or document date) and DSO / DPO
- Customer and supplier statements, open-item and balance-forward
- Tax return working papers and a reconciliation of tax accounts to the return
- Bank reconciliation statements and a suspense aging
- Cash flow forecast, budget vs actual, P&L by dimension
- Consolidated group reporting with inter-entity elimination and a proof that eliminations net to zero

Every report is exportable to **XLSX** with live formulas and number formats intact rather than as flattened text, **CSV** (UTF-8 with BOM, and with formula-injection neutralization on every cell that begins with `=`, `+`, `-`, `@`, tab or carriage return), **PDF/A** for archiving, and **JSON** through the API. A report is reproducible: the same parameters and the same as-of timestamp return the same numbers, and the parameters are printed on the output.

## Standards register

PeakFlow is built against published standards rather than against a competitor's screenshots. Every standard below is referenced from the specific feature that implements it, and the full register - with version, scope, and what conformance means for each - is [`docs/standards/README.md`](docs/standards/README.md).

**Accounting and financial reporting**

| Standard | Applied to |
|----------|------------|
| IFRS / IAS 1 | Statement presentation, the chart's statement classification |
| IAS 7 | Statement of Cash Flows |
| IAS 8 | Accounting policies, changes in estimates, prior-period error handling |
| IAS 21 | Foreign currency translation, functional vs presentation currency |
| IAS 10 | Events after the reporting period, and the close/lock boundary |
| IFRS 9 | Expected credit loss provisioning on receivables |
| IFRS 15 | Revenue recognition, performance obligations, deferred revenue |
| Double-entry bookkeeping | The ledger's zero-sum invariant |

**Invoicing and document exchange**

| Standard | Applied to |
|----------|------------|
| EN 16931-1 | The semantic invoice model - the internal source of truth |
| OASIS UBL 2.1 / ISO/IEC 19845 | Primary XML serialization |
| UN/CEFACT CII D16B | Alternate serialization, basis of Factur-X |
| Peppol BIS Billing 3.0 | CIUS for Peppol delivery |
| Factur-X / ZUGFeRD 2.3 | Hybrid PDF/A-3 with embedded CII |
| ISO 19005-3 (PDF/A-3) | Archival PDF with embedded XML |
| OASIS AS4 / ebMS 3.0 | Peppol transport binding |
| UNCL 1001 | Document type codes |
| UNCL 5305 | Tax category codes |
| UNCL 4461 | Payment means codes |
| UNCL 7143 / UNSPSC / GS1 GPC | Item classification |
| UN/CEFACT Rec 20 + Rec 21 | Units of measure |

**Banking and payments**

| Standard | Applied to |
|----------|------------|
| ISO 20022 CAMT.052 / .053 / .054 | Statement, report and notification ingestion |
| ISO 20022 PAIN.001 / .002 / .008 | Payment initiation, status report, direct debit |
| ISO 13616 + ISO/IEC 7064 | IBAN structure and MOD-97-10 check digits |
| ISO 9362 | BIC validation |
| EPC SEPA SCT / SDD rulebooks | SEPA character set, limits, mandates |
| SWIFT MT940 / MT942 | Legacy statement ingestion |
| ISO 4217 | Currency codes and minor units |

**Engineering, security and accessibility**

| Standard | Applied to |
|----------|------------|
| OWASP ASVS 5.0 | The security requirement baseline, verified per release |
| OWASP Top 10 / API Top 10 | Threat model and review checklist |
| ISO/IEC 27001 Annex A | Control mapping for the operator plane |
| SOC 2 Trust Services Criteria | Evidence-generating controls: audit log, access review, change management |
| GDPR + local data protection | Lawful basis, retention, erasure, DPA, residency |
| eIDAS | Qualified electronic seal where a jurisdiction requires one |
| WCAG 2.2 AA + WAI-ARIA 1.2 + EN 301 549 | The accessibility floor, tested |
| RFC 9457 | `application/problem+json` error responses |
| OpenAPI 3.1 | The API contract, spec-first |
| RFC 5545 | Recurrence rules for schedules |
| RFC 6238 / WebAuthn L3 | TOTP and passkey authentication |
| RFC 8785 (JCS) / XML C14N | Canonicalization before hashing |
| RFC 9110 / 9111 | HTTP semantics, conditional requests, caching |
| ISO 8601 / RFC 3339 | Date and timestamp representation |
| Semantic Versioning 2.0, Conventional Commits, Keep a Changelog | Release engineering |
| The Twelve-Factor App | Configuration and deployment discipline |
| CycloneDX | SBOM published per release |

## Tech stack

Chosen for a long-lived financial system: boring where correctness matters, modern where the user experience does.

- **PHP 8.3+** - typed class constants, readonly classes, `json_validate`. 8.4 is the target and 8.3 is the floor; the code is verified on 8.3
- **Laravel 13** - the application framework
- **PostgreSQL 16+** - `numeric` exact decimal, deferrable constraint triggers, row-level security, declarative partitioning for the journal, exclusion constraints, `pg_stat_statements`, PITR via WAL archiving. Every one of those exists in 16, which is the verified floor; 17 and 18 work. **The ledger's integrity rules are expressed as database constraints, which is the single strongest argument for PostgreSQL over the alternative**
- **Redis 8** - cache, queues, rate limiting, and distributed locks for scheduled runs
- **Laravel Horizon** - queue supervision and visibility, which the `Exchange` plane needs rather than wants
- **Laravel Octane + FrankenPHP** - persistent workers, HTTP/3, early hints
- **Livewire 3 + Alpine.js 3** - server-driven reactivity. Chosen over an SPA deliberately: a financial UI's hard parts are a 50,000-row grid, a reconciliation split view and an invoice builder whose tax recalculates as you type - all of which need the server's answer to be the only answer. A client-side tax engine is a second implementation of the rules, and it will disagree
- **Tailwind CSS 4** - configured in CSS, no `tailwind.config.js`
- **Vite 8** - asset pipeline, self-hosted variable fonts
- **Apache ECharts** - financial charts; canvas rendering handles the data density, and it has a real accessibility layer
- **Gotenberg** - HTML→PDF as a service, then **Ghostscript** for PDF/A-3 conversion and **veraPDF** to *verify* conformance in CI. A PDF that merely claims PDF/A is not an archive
- **`brick/money`** + **`brick/math`** - exact decimal money with explicit rounding
- **Laravel Sanctum** + **Fortify** + **WebAuthn** - API tokens, session auth, passkeys
- **Meilisearch** - global search across documents and parties
- **OpenTelemetry** + **Laravel Pulse** + **Sentry** - traces, runtime metrics, error tracking

**Quality tooling**, all of it enforced rather than suggested:

| Tool | Gate |
|------|------|
| **`bin/verify`** | The architecture rules and the specification's worked examples, with **no test runner at all**. One implementation, two runners - so the checks run even where the dev dependencies cannot be installed |
| **Pest 4** (on PHPUnit 11) | Unit, feature, architecture and browser tests |
| **PHPStan 2 / Larastan**, level `max` | Static analysis, no baseline suppressions for new code |
| **Deptrac 3** | Module boundary enforcement - the table in [Bounded contexts](#bounded-contexts-and-a-compiler-that-enforces-them) is its config |
| **Rector 2** | Automated upgrades and dead-code removal. Configured in `rector.php` and installed on demand - see `composer suggest` |
| **Laravel Pint** | Formatting |
| **Infection** | Mutation testing, with a minimum MSI on the `Ledger`, `Tax` and `Settlement` modules - the places where a passing test suite with weak assertions is most dangerous |
| **Composer audit** + **Dependabot** + **Trivy** | Dependency and image vulnerabilities |
| **CycloneDX** | SBOM per release |

## Requirements

- PHP **8.3+** with `intl`, `pgsql`, `dom`, `xsl`, `zip`, `sodium`, `mbstring`
  - `intl` is **required**, not optional: ICU drives number and date formatting, currency symbols and digit shaping, Arabic plural selection, and locale-aware collation
  - `xsl` is **required**: Schematron validation compiles to XSLT 2.0
  - `bcmath` or `gmp` are **recommended, not required**: `brick/math` falls back to a pure-PHP calculator without them, which is exactly as correct and measurably slower. The money layer is verified on a build with neither
- Composer 2.8+
- **PostgreSQL 16+**. The provisioner issues `CREATE DATABASE` / `CREATE ROLE` / `GRANT`, so the configured role needs those privileges. On managed hosting without them, the provisioner's **link** mode attaches a pre-created database instead
- **Redis 8+**
- Node.js **22 LTS+** and npm
- **Gotenberg** and **veraPDF** reachable for PDF generation and PDF/A verification. Both run as containers in development; `PDF_DRIVER=stub` renders an HTML placeholder so a fresh clone works with neither
- **Java 17+** only if you regenerate the compiled Schematron XSLT; the compiled artefacts are vendored, so a normal checkout does not need it

## Getting started

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
```

Configure the platform database in `.env`:

```dotenv
APP_NAME=PeakFlow
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en

DB_CONNECTION=platform
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=peakflow
DB_USERNAME=postgres
DB_PASSWORD=

REDIS_HOST=127.0.0.1
PDF_DRIVER=stub
EXCHANGE_DRIVER=log
```

```bash
# 3. Platform schema, then the operator, plans and a real tenant
php artisan migrate
php artisan db:seed

# 4. Optional: a fully worked demo entity - chart of accounts, a year of
#    invoices, bills, statements and a completed reconciliation
php artisan db:seed --class=DemoTenantSeeder

# 5. Build assets
npm run build
```

Run the stack:

```bash
composer dev     # server + queue + Horizon + Vite + log tail
```

> **`EXCHANGE_DRIVER=log` and `PDF_DRIVER=stub` are the defaults on purpose.** A fresh clone must be able to issue an invoice end to end with no Peppol credentials, no clearance certificate and no PDF service. The `log` driver writes the document that *would* have been transmitted into the log and marks the transmission succeeded, so the state machine is exercised without a third party. Switching to a real driver is a configuration change, not a code path.

## Configuration

| Area | Where | Note |
|------|-------|------|
| Tenant resolution | `config/tenancy.php` | Connection template, provisioning mode (`create` / `link`), migration batch size |
| Jurisdictions | `config/jurisdictions.php` | Which profiles are enabled; a disabled profile cannot be selected by a tenant |
| Money | `config/money.php` | Scales, rounding mode per jurisdiction, display policy |
| Tax | `config/tax.php` | Default rounding policy, tolerance ceilings |
| Banking | `config/banking.php` | Supported statement formats, matching confidence thresholds, write-off tolerance ceilings |
| Exchange | `config/exchange.php` | Drivers, endpoints, retry and dead-letter policy, circuit breaker |
| PDF | `config/pdf.php` | Gotenberg URL, PDF/A profile, veraPDF binary |
| Observability | `config/telemetry.php` | OTLP endpoint, sampling, redaction rules |

**Tenant-level settings override platform config** for everything a tenant may legitimately decide - dunning policy, matching thresholds, rounding tolerance, numbering formats, which jurisdictions it operates in. A **ceiling** is enforced at the platform level for anything with a risk dimension: a tenant may lower a write-off tolerance but not raise it past the operator's maximum.

Secrets are **never** read from config at runtime by the `Exchange` plane; signing keys and transport credentials come from a KMS, and the application holds a reference, not a key.

## Artisan commands

| Command | Does |
|---------|------|
| `php artisan tenants:provision {name}` | Create or link a tenant database, migrate, seed |
| `php artisan tenants:migrate {tenant?} [--all]` | Run tenant migrations against one tenant or every active one |
| `php artisan tenants:dump {tenant}` | Per-tenant logical backup, for export or for a data request |
| `php artisan ledger:verify {entity} [--period=]` | Re-prove the ledger: every entry balances, subledgers equal their control accounts, no posting in a closed period |
| `php artisan ledger:rebuild-balances {entity}` | Rebuild balance read models from the journal, then diff against the live ones and exit non-zero on drift |
| `php artisan invoices:verify-chain {entity}` | Re-walk the hash chain and report the first divergence |
| `php artisan invoices:generate-recurring` | Run due recurring schedules, idempotently |
| `php artisan revenue:recognize {period}` | Post the IFRS 15 recognition run for a period |
| `php artisan fx:fetch-rates` | Pull the day's rates from each configured provider |
| `php artisan fx:revalue {entity} {period}` | IAS 21 period-end revaluation of monetary items |
| `php artisan bank:import {file} --account=` | Import a statement from the CLI |
| `php artisan bank:reconcile {account} --propose` | Run the matching passes and report proposals without applying |
| `php artisan periods:close {entity} {period}` | Run the close checklist and report every blocking item |
| `php artisan dunning:run` | Evaluate dunning policies and queue the day's communications |
| `php artisan exchange:retry [--dead-letter]` | Re-drive failed transmissions |
| `php artisan archive:verify` | Checksum the archive manifest against object storage |
| `php artisan compliance:report {tenant}` | Produce the evidence pack: access review, SoD overrides, period changes, chain verification |

## Scheduled tasks

| Task | Cadence | Note |
|------|---------|------|
| `fx:fetch-rates` | Hourly on weekdays | Several providers; a failure uses the last known rate and raises an alert rather than blocking issuance |
| `invoices:generate-recurring` | Daily, 01:00 tenant time | Idempotent on `(schedule, occurrence)` |
| `dunning:run` | Daily, 07:00 tenant time | Grouped per customer |
| `bank:import` (feeds) | Every 4 hours | Where a feed is configured |
| `exchange:retry` | Every 15 minutes | Exponential backoff, then dead letter |
| `invoices:verify-chain` | Nightly | Alerts on divergence |
| `ledger:rebuild-balances --check` | Nightly | Reports read-model drift |
| `archive:verify` | Weekly | Retention and checksum proof |
| `subscriptions:expire` | Daily | A subscription is valid *through* its end date, so the comparison is strictly `<` |

> **Scheduled work is per tenant and must run in the tenant's own timezone.** "Daily at 07:00" means 07:00 where the business is, and a tenant in Riyadh and one in Casablanca do not share a moment. Every scheduled run takes a Redis lock keyed on `(task, tenant, occurrence)` so a two-node scheduler cannot double-bill a customer.

## REST API

A versioned, spec-first JSON API at `/api/v1`, documented as **OpenAPI 3.1** in [`docs/api/openapi.yaml`](docs/api/openapi.yaml). The spec is the contract: it is written first, and a contract test asserts every route and schema in the spec exists and that no undocumented route exists.

**Conventions**, in full in [`docs/api/conventions.md`](docs/api/conventions.md):

| Concern | Decision |
|---------|----------|
| Identity | `X-Tenant` and `X-Entity` headers, resolved before auth |
| Auth | `Authorization: Bearer` (Sanctum); scopes mirror permissions |
| Errors | **RFC 9457** `application/problem+json`, with `type`, `title`, `status`, `detail`, `instance` and an `errors` array addressed by field - and by EN 16931 business term where the failure is a document rule |
| Idempotency | `Idempotency-Key` **required** on every POST that posts to the ledger. The response is stored and replayed for 24 hours; a replay with a different body is a `409` |
| Pagination | Cursor-based by default (`?cursor=`), because offset pagination over a growing journal both drifts and degrades. Offset is available where a UI genuinely needs a page number, capped |
| Money | Always an object: `{"amount": "1234.5600", "currency": "SAR"}`. A bare number is never returned - a client parsing it as a float is the bug this prevents |
| Dates | `YYYY-MM-DD` for accounting dates, RFC 3339 UTC for timestamps. An accounting date is **not** a timestamp and must never be timezone-shifted |
| Concurrency | `ETag` + `If-Match` on mutable resources; a stale write gets `412` |
| Filtering | A documented allowlist of fields per resource, never arbitrary query interpolation |
| Rate limits | Per tenant and per token, with `RateLimit` headers; writes are limited more tightly than reads |
| Webhooks | HMAC-SHA256 signed, timestamped, replay-protected, with a documented retry schedule and a delivery log the tenant can inspect and replay |

**Deprecation policy:** a version is supported for 18 months after its successor ships. Deprecated endpoints return `Deprecation` and `Sunset` headers and a `Link` to the migration note, for the whole window. A breaking change inside a version is a bug.

## Design system

The goal is a financial application that feels **fast, dense and calm** - and modern without being decorative. Finance users live in tables for eight hours; the design brief is legibility and velocity, not delight. Full specification in [`docs/design/design-system.md`](docs/design/design-system.md).

**Colour is OKLCH and two-layered.** Raw values are declared as `--c-<name>` under `:root` and again under `.dark`, then mapped once inside `@theme`:

```css
:root { --c-primary: 54.1% 0.192 258.3; }
.dark { --c-primary: 78.4% 0.118 254.1; }
@theme { --color-primary: oklch(var(--c-primary)); }
```

One utility name (`text-primary`) therefore follows the theme with no `dark:` variant at the call site. OKLCH rather than hex or HSL because it is perceptually uniform: a ramp generated by stepping lightness actually *looks* evenly stepped, and a contrast target can be hit by adjusting one number instead of by trial and error.

> **The dark variant must be redefined, and this one line is load-bearing:**
>
> ```css
> @custom-variant dark (&:where(.dark, .dark *));
> ```
>
> Tailwind v4 defaults `dark:` to `@media (prefers-color-scheme: dark)`. PeakFlow toggles a `.dark` class, so without that line **every `dark:` utility silently does nothing** - the utility compiles, the class lands in the HTML, and the colour never changes. This is inherited as a known trap from a sibling project where it shipped a status pill at **1.62:1** contrast in dark mode.

**Semantic colour for finance**, and never colour alone - **WCAG 1.4.1**:

| Meaning | Treatment |
|---------|-----------|
| Debit / positive | Neutral foreground. Money is not good news by default |
| Credit / negative | Accounting negative: parentheses **and** a sign **and** a colour. A colour-blind user reading a variance column needs the parentheses |
| Overdue | Colour, an icon, and the word |
| Reconciled / matched | A check glyph plus a text state, never a green row alone |
| Draft / unposted | A hatched left border and the literal word "draft" - including on print |

**Numerals are tabular, always.** `font-variant-numeric: tabular-nums` on every figure in a table, so digits align in columns and the eye can scan a thousands place. A proportional `1` in a currency column is the single most common reason a financial UI feels amateur. The numeral face is a variable Latin font even in Arabic UI, because financial documents in the region use Western digits - and the digit shape is a per-tenant setting (`latn` / `arab`) applied through ICU, not by string replacement.

**Density is a user setting**, not a breakpoint: `comfortable`, `compact`, `dense` change row height, font step and padding through tokens, persisted per user. An accountant on a 27-inch monitor wants 40 rows; the default serves neither if it only serves one.

**Interaction**, specified in [`docs/design/interaction-and-motion.md`](docs/design/interaction-and-motion.md):

- **Keyboard first.** The data grid is fully operable without a mouse: arrow navigation, range selection, type-to-edit, `Enter` to commit and move down, `Tab` to commit and move right, `Esc` to revert. Finance users touch-type numbers and a mouse round trip per cell is the difference between usable and abandoned
- **Command palette** (`Ctrl`/`⌘`+`K`) over documents, parties, accounts and actions, with scoped results and recent items
- **Virtualized tables** with sticky headers, frozen leading columns, column pinning, resizing and per-user saved views
- **Drag-and-drop reconciliation** in a two-pane split, with a running difference that is always visible and keyboard-equivalent actions for every drag
- **Optimistic UI only where the server cannot disagree.** Renaming a saved view is optimistic. Posting a journal entry is not - it shows a pending state and waits, because an optimistically "posted" entry that the server then refuses is a lie about money
- **Motion is 120-240ms**, `cubic-bezier(0.2, 0, 0, 1)`, and every transition is wrapped in `@media (prefers-reduced-motion: reduce)`. The View Transitions API is used for list→detail where supported and degrades to no animation
- **Skeletons that match the final layout**, so nothing reflows on arrival
- **Empty, loading, error, partial, offline and permission-denied states are designed for every screen**, not left to the framework. A table with no rows must say whether that is because there is no data, the filter excluded everything, or the request failed - three different states that a spinner-then-blank cannot distinguish

**Print is a first-class medium.** Invoices, statements and reports have a dedicated print stylesheet: A4 and Letter, correct page breaks inside tables, repeated table headers, a page `x of y`, and no interactive chrome. The print view is what gets attached to an email, so it is tested by rendering and diffing a PDF, not by eyeballing a browser preview.

## Localization and RTL

Arabic-first and genuinely bilingual, which is a deeper requirement than a translated string file.

- **Two locales, `ar` and `en`**, with ICU **MessageFormat** - not `:placeholder` string interpolation - because Arabic has six plural categories (`zero`, `one`, `two`, `few`, `many`, `other`) and a format that cannot express them produces wrong grammar for most numbers
- **Never concatenate a translated label with a value.** Arabic adjectives agree in gender with the noun, so a template like `":label جديدة"` is wrong for every masculine noun. Always a placeholder inside the full phrase, so the translator controls agreement
- **Logical CSS properties only.** `margin-inline-start`, `padding-inline-end`, `inset-inline-start`, `border-start-start-radius`. A single `left:` in a component is an RTL bug waiting for a customer to find it, and a stylelint rule fails the build on the physical properties
- **Bidi-safe money and identifiers.** An IBAN, an invoice number or a negative amount inside Arabic text reorders unless isolated. Every such value is wrapped in a `<bdi>` or `isolate`d, because `JO94-CBJO-0010` rendered in logical order is a different string to the one the user must verify, and they will not notice
- **Numbers, dates and currency through ICU**, respecting the tenant's digit shape, calendar (Gregorian and Hijri display), first day of week, and currency symbol placement - none of which are derivable from the language alone
- **A missing translation key fails the test suite.** Every `__()` string in the codebase must exist in both catalogs, both files must be sorted and have identical key sets, and no English value may be left as Arabic text. A missing key does not throw - it silently prints the source string, which is exactly why it needs a test rather than a code review

## Accessibility

**WCAG 2.2 AA is the floor and it is tested, not asserted.** A control with no accessible name renders perfectly and is noticed only by someone using a screen reader, so these are enforced by an architecture test over the Blade and Livewire templates:

- **Every `<label>` must name a control** - via `for`/`id`, by wrapping it, or through `aria-labelledby`. A bare `<label>` beside an input names nothing
- **A placeholder is not a name.** Filter inputs and selects carry `aria-label`
- **Ids are unique per render.** A component used twice on one page derives its ids from an instance key. A duplicate id is worse than none, because the label points at whichever copy the browser saw first
- **Every shell starts with a skip link**, and `<main>` carries `tabindex="-1"` - without it the browser scrolls to the target but leaves focus behind, so the next `Tab` returns to the navigation the user just skipped. A skip link that only moves the viewport is worse than none, because it looks like it worked
- **A data grid declares its semantics** - `role="grid"`, `aria-rowcount` reflecting the *total*, not the rendered window, `aria-sort` on sorted headers, and a live region announcing "showing 1-50 of 12,480"
- **Charts are not the only representation.** Every chart has an accessible table behind a disclosure, and a text summary of the trend. A dashboard that only exists as pixels excludes an auditor using a screen reader
- **Focus is never lost.** A dialog traps focus, restores it on close, and a row deleted from a grid moves focus to its neighbour rather than to `<body>`
- Contrast is verified programmatically for every token pair in both themes, so a palette change cannot quietly regress it

## Project structure

```
app/
├── Platform/                  # Operator plane - the `platform` connection
│   ├── Models/                #   Operator, Tenant, Plan, Subscription, DatabaseConnection
│   ├── Services/              #   TenantProvisioner, TenantConnectionResolver, PlanGate
│   └── Http/                  #   /ops/* console
├── Tenant/                    # The application - the `tenant` connection
│   ├── Identity/              #   Users, roles, permissions, MFA, SoD evaluation
│   ├── Ledger/                #   Accounts, journals, entries, periods, dimensions, posting rules
│   ├── Parties/               #   Customers, suppliers, contacts, bank details
│   ├── Catalog/               #   Items, price lists, units, classification
│   ├── Tax/                   #   Codes, rates, rules, returns
│   ├── Sales/                 #   Quotes, orders, invoices, credit notes, dunning, recognition
│   ├── Purchasing/            #   Purchase orders, bills, expense claims, approvals, 3-way match
│   ├── Banking/               #   Accounts, statements, parsers, matching engine, payment runs
│   ├── Settlement/            #   Payments, allocations, FX, write-offs
│   └── Reporting/             #   Read models, statements, aging, export
├── Exchange/                  # Outbound/inbound document plane - queues only
│   ├── Peppol/  Zatca/  Eta/  #   Per-jurisdiction transports
│   ├── Serializers/           #   UBL 2.1, CII D16B, national subsets
│   ├── Validation/            #   XSD + compiled Schematron chain
│   └── Webhooks/              #   Signing, delivery, replay
└── Support/
    ├── Money/                 # Money, Rate, RoundingPolicy, Scale
    ├── Iso/                   # Iban, Bic, CurrencyRegistry, CountryRegistry, UnitOfMeasure
    └── Hashing/               # Canonicalization (JCS, XML C14N), chain linking
database/
├── migrations/                # Platform schema
├── migrations/tenant/         # Per-tenant schema, including the constraint triggers
└── seeders/                   # Operator, plans, chart templates, DemoTenantSeeder
docs/                          # The specification - see the documentation map below
lang/                          # ar / en, ICU MessageFormat
resources/
├── css/app.css                # Tailwind v4 entrypoint, OKLCH tokens, print styles
├── js/                        # Alpine entrypoint, grid, command palette, charts
└── views/                     # Blade + Livewire components
schematron/                    # Vendored, version-pinned validation artefacts
routes/                        # web.php, ops.php, api.php, console.php, channels.php
```

### Shared conventions

- **Primary keys are ULIDs** - sortable by creation time, safe to generate client-side for offline and idempotent writes, and they do not leak a row count the way a sequence does
- **Every financial tenant table carries `entity_id`**, non-nullable, indexed first in every composite index
- **Append-only tables are partitioned by period** - `journal_lines`, `bank_transactions` and `audit_log` are declarative range partitions on their date column, because they grow for the life of the account and every query is bounded by a period
- **Append-only logs are indexed for their actual query**: a composite `(filtered_column, created_at)` rather than a single-column index, because PostgreSQL uses one index per table reference and a lone `category` index lets it filter *or* sort, never both
- **Soft deletes on everything referenced by history**, so a deleted customer's name still resolves on an invoice issued three years ago
- **Every table that a user can create a row in has a `created_by`**, and every financial table has `posted_by` and `posted_at` separately, because who drafted a document and who approved it are different questions and the second is the one an auditor asks

## Testing strategy

Full strategy, with the reasoning per layer, in [`docs/operations/testing-strategy.md`](docs/operations/testing-strategy.md).

| Layer | Tool | What it is for |
|-------|------|----------------|
| **Unit** | Pest | Money arithmetic, rounding policies, IBAN/BIC validation, parsers, posting rules |
| **Property-based** | Pest + generators | The invariants: a random journal entry that balances still balances after a round trip; any invoice's line sum equals its net; any rounding path is idempotent |
| **Feature** | Pest + real PostgreSQL | HTTP and Livewire behaviour against the real constraint triggers. **The tenant suite cannot run on SQLite** - deferrable constraint triggers, RLS, partitioning and `numeric` semantics are the thing under test |
| **Architecture** | Pest arch + Deptrac | Module boundaries, no `float` in the money path, no physical CSS properties, every financial table has `entity_id`, no `Reporting` writes |
| **Concurrency** | Parallel workers | Gapless numbering under contention, payment-run double-release, scheduled-run locks, idempotency replay |
| **Golden file** | Pest snapshots | Serialized UBL/CII output byte-compared against fixtures from the official test corpora, validated through the full Schematron chain |
| **Contract** | Spectator / OpenAPI | Every response matches the published schema; no undocumented route exists |
| **Mutation** | Infection | A minimum MSI gate on `Ledger`, `Tax`, `Settlement`. A money test suite that passes against mutated arithmetic is not testing the arithmetic |
| **Browser** | Pest 4 browser tests | Keyboard operation of the grid, focus management, the reconciliation drag with its keyboard equivalent |
| **Accessibility** | axe + arch tests | Automated contrast and ARIA checks on every route, plus the template rules above |
| **Visual regression** | Playwright screenshots | Light and dark, LTR and RTL, three densities, and every print view |
| **Performance** | k6 + `EXPLAIN` assertions | Query budgets: a page must cost the same number of queries at 5 rows as at 500, and the trial balance must stay inside its budget at 10M lines |

**The ledger has its own proof.** `ledger:verify` is not only a command - it runs as the final assertion of every feature test that posts anything. A test that creates an invoice, takes a payment and reconciles it ends by re-proving that every entry balances, every subledger equals its control account and nothing posted into a closed period. An individual assertion can be written to pass; the global invariant cannot.

## Continuous integration

> ### ⚠️ No GitHub Actions workflow is installed in this repository, deliberately.
>
> There is **nothing in `.github/workflows/`**. The pipeline below is fully specified and kept as a disabled, non-executing reference at [`docs/operations/ci-pipeline.yml.example`](docs/operations/ci-pipeline.yml.example). Nothing runs on push, and nothing will until someone copies that file into `.github/workflows/` on purpose. This is a conscious choice for the specification phase - CI minutes spent linting a repository that contains no code buy nothing - and it is also a tracked task (`PF-013`), so enabling it is a decision with a name on it rather than an oversight.

When it is enabled, the pipeline runs in this order, and the order is the point:

```
lint & static ──┬─► build assets ──► unit + arch ──► feature (PostgreSQL 16+ + Redis service)
  pint          │                                       │
  phpstan max   │                                       ├─► concurrency suite
  deptrac       │                                       ├─► contract (OpenAPI)
  stylelint     │                                       ├─► golden-file + Schematron
  rector --dry  │                                       └─► a11y + visual regression
                └─► composer audit, Trivy, CodeQL, SBOM ──► mutation (Ledger/Tax/Settlement)
```

- **The front-end build runs before the PHP tests.** The translation-coverage and template-markup tests read compiled output, so a build failure would otherwise let a broken page through a green suite.
- **A real PostgreSQL service is mandatory.** The tenant schema uses deferrable constraint triggers, partitioning and RLS; it cannot run on SQLite, and a suite that silently skipped those tests would be worse than no suite.
- **The deploy gates on everything above it.** A guard nobody runs is not a guard - which is the specific lesson carried over from a sibling project whose deployment workflow uploaded on every push with no test job in front of it.

## Security

Threat model and the full control list in [`docs/operations/security-hardening.md`](docs/operations/security-hardening.md). The controls that are structural rather than configurable:

- **Tenant isolation is physical** - a separate database and a separate database role per tenant, so a missing `where tenant_id` cannot cross a boundary because there is nothing to cross to
- **The application's database role cannot `DELETE` posted financial rows, cannot `ALTER`, and cannot read the platform database.** Migrations run as a different role, from a different process
- **Secrets never touch the database or a log.** Signing keys live in a KMS; transport credentials are referenced, not stored. Structured logging has an allowlist, not a redaction blocklist - a blocklist fails open the first time a field is added
- **MFA is mandatory for money-moving permissions**, with step-up re-authentication at the act, and SoD evaluated against the audit trail
- **The audit log is append-only, hash-chained and covers reads of sensitive data**, not only writes. Who *looked* at a bank account is an auditable event
- **Every input is validated at the boundary against an allowlist.** Filters, sorts and exports name their permitted fields; CSV export neutralizes formula injection; uploaded documents are type-sniffed, size-capped, scanned, and served from a separate origin with `Content-Disposition: attachment`
- **Webhooks are signed and replay-protected**; inbound documents from a trading partner are treated as untrusted input and validated before they are parsed, with XXE and billion-laughs protections explicitly configured on every XML reader
- **Rate limiting, CSP with nonces, `Strict-Transport-Security`, `Permissions-Policy`, `COOP`/`COEP`**, and no inline script without a nonce
- **OWASP ASVS 5.0 is the checklist**, reviewed per release with the result recorded in the release notes rather than asserted once

**Data protection:** a tenant can export everything it owns and can request erasure; erasure is reconciled against statutory retention, which wins and is explained rather than silently ignored - a tax authority requiring seven years of invoice retention overrides a deletion request, and the product must say so in the UI instead of pretending to delete.

## Observability

- **OpenTelemetry** traces across the HTTP request, the queue job and the outbound transport, so "why did this invoice take four minutes to clear" is one trace rather than three log searches
- **Structured JSON logs** with a correlation id propagated into queue jobs and outbound calls, and a field allowlist
- **Business metrics, not only technical ones**: invoices issued and failed per jurisdiction, clearance latency percentiles, unmatched bank lines by age, suspense balance by entity, dead-letter depth, failed scheduled runs. A dashboard of CPU and p99 latency does not tell you that one country's clearance has been rejecting everything for an hour
- **Alerts on invariants**, which is the unusual part: ledger drift detected by the nightly rebuild, a hash-chain divergence, a subledger that no longer equals its control account, a tenant whose suspense balance crossed a threshold. These are the alerts that matter in a financial system and they are impossible to retrofit

## The delivery backlog

**246 tasks across 14 phases**, each with an ID, acceptance criteria, dependencies, an estimate and the standard it answers to.

| Phase | Theme | Tasks |
|-------|-------|-------|
| [00](docs/tasks/phase-00-foundations.md) | Foundations, tooling and guardrails | 17 |
| [01](docs/tasks/phase-01-tenancy-identity.md) | Tenancy, entities, identity, SoD | 19 |
| [02](docs/tasks/phase-02-ledger.md) | The general ledger | 22 |
| [03](docs/tasks/phase-03-masters.md) | Parties, catalog, currencies, FX | 16 |
| [04](docs/tasks/phase-04-tax.md) | The tax engine | 14 |
| [05](docs/tasks/phase-05-sales.md) | Quotes, orders, invoices, credit notes | 21 |
| [06](docs/tasks/phase-06-einvoicing.md) | EN 16931, serializers, validation, profiles | 20 |
| [07](docs/tasks/phase-07-purchasing.md) | Purchase orders, bills, approvals, 3-way match | 15 |
| [08](docs/tasks/phase-08-banking.md) | Bank accounts, statements, reconciliation | 21 |
| [09](docs/tasks/phase-09-settlement.md) | Payments, allocation, FX, payment runs | 16 |
| [10](docs/tasks/phase-10-reporting.md) | Reporting, close, consolidation | 17 |
| [11](docs/tasks/phase-11-design-system.md) | Design system, grid, a11y, RTL | 18 |
| [12](docs/tasks/phase-12-api-exchange.md) | REST API, webhooks, Exchange plane | 14 |
| [13](docs/tasks/phase-13-hardening.md) | Security, observability, performance, release | 16 |

Phase ordering is driven by dependency, not by visibility. The ledger comes before invoicing because an invoice that cannot post is not an invoice; the tax engine comes before sales because retrofitting a tax point into issued documents is a data migration; and the design system runs late only in its *systematic* form - a usable shell and the grid exist from Phase 00, because building eleven phases of screens against a design system that does not exist yet is how a product ends up with eleven design systems.

See [`docs/tasks/README.md`](docs/tasks/README.md) for the task format, the estimation basis, the Definition of Ready and the Definition of Done.

## Documentation map

| Document | Covers |
|----------|--------|
| [`docs/architecture/overview.md`](docs/architecture/overview.md) | Planes, modules, boundaries, cross-cutting concerns |
| [`docs/architecture/multi-tenancy.md`](docs/architecture/multi-tenancy.md) | Provisioning, resolution, entity scoping, migration at scale |
| [`docs/architecture/money-and-precision.md`](docs/architecture/money-and-precision.md) | Scales, rounding, currency edge cases, worked examples |
| [`docs/architecture/decisions/`](docs/architecture/decisions/) | ADRs - the decisions, their alternatives and why they lost |
| [`docs/domain/general-ledger.md`](docs/domain/general-ledger.md) | Accounts, journals, posting rules, periods, close |
| [`docs/domain/invoicing.md`](docs/domain/invoicing.md) | Lifecycle, numbering, hash chain, recognition, dunning |
| [`docs/domain/banking.md`](docs/domain/banking.md) | Statement formats, the matching engine, payment initiation |
| [`docs/domain/tax-engine.md`](docs/domain/tax-engine.md) | Codes, place of supply, reverse charge, withholding, returns |
| [`docs/domain/schema.md`](docs/domain/schema.md) | Every table, every column, every constraint |
| [`docs/domain/glossary.md`](docs/domain/glossary.md) | The bilingual domain vocabulary - AR/EN, with the standard each term comes from |
| [`docs/standards/README.md`](docs/standards/README.md) | The standards register, with versions and conformance scope |
| [`docs/standards/en16931-mapping.md`](docs/standards/en16931-mapping.md) | Business-term-by-business-term mapping |
| [`docs/standards/iso20022-mapping.md`](docs/standards/iso20022-mapping.md) | CAMT and PAIN element mapping |
| [`docs/standards/compliance-matrix.md`](docs/standards/compliance-matrix.md) | Requirement → control → test → evidence |
| [`docs/design/design-system.md`](docs/design/design-system.md) | Tokens, type, colour, density, components |
| [`docs/design/interaction-and-motion.md`](docs/design/interaction-and-motion.md) | Grid behaviour, keyboard model, motion, states |
| [`docs/design/rtl-and-i18n.md`](docs/design/rtl-and-i18n.md) | Arabic-first rules, bidi, ICU, digit shaping |
| [`docs/design/accessibility.md`](docs/design/accessibility.md) | WCAG 2.2 AA conformance plan and test method |
| [`docs/api/conventions.md`](docs/api/conventions.md) | Errors, idempotency, pagination, money, versioning |
| [`docs/api/webhooks.md`](docs/api/webhooks.md) | Events, signing, retries, replay |
| [`docs/operations/testing-strategy.md`](docs/operations/testing-strategy.md) | Every test layer and what it is for |
| [`docs/operations/security-hardening.md`](docs/operations/security-hardening.md) | Threat model, controls, ASVS mapping |
| [`docs/operations/observability.md`](docs/operations/observability.md) | Traces, metrics, the invariant alerts |
| [`docs/operations/backup-and-dr.md`](docs/operations/backup-and-dr.md) | Per-tenant PITR, RPO/RTO, restore drills |
| [`docs/operations/ci-cd.md`](docs/operations/ci-cd.md) | The pipeline, and why it is currently disabled |
| [`docs/tasks/`](docs/tasks/) | The 229-task delivery backlog |
| [`docs/roadmap.md`](docs/roadmap.md) | Phases, milestones, sequencing rationale |

## License

Proprietary. © 2026 Mustafa T. Al-Swaisi. All rights reserved. See [`LICENSE`](LICENSE).

Built with the [Laravel](https://laravel.com) framework.
