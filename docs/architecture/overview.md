# Architecture overview

## The shape of the thing

PeakFlow is **one deployable** containing **three planes** and **twelve modules**. It is not microservices, and the reason is specific rather than ideological: a ledger whose postings are eventually consistent with the documents that caused them is not a ledger. The posting must be in the same transaction as the invoice, and that requirement decides the deployment topology.

What it *is* instead is a modular monolith with boundaries that are **compiled rather than documented**. The dependency rules below are the contents of [`deptrac.yaml`](../../deptrac.yaml), and CI fails on a violation. A boundary that is only written down is a boundary that erodes.

## The three planes

| Plane | Connection | Users | Namespace |
|-------|------------|-------|-----------|
| **Platform** | `platform` | Operators | `App\Platform\*` |
| **Tenant** | `tenant` (resolved per request) | A customer's users | `App\Tenant\*` |
| **Exchange** | `platform` + per-tenant outbox | No human; queue workers only | `App\Exchange\*` |

They never share data. A route belongs to exactly one plane, and platform routes never pass through tenant resolution.

**Why the Exchange plane exists.** Two planes were enough for a self-contained application. A document that must reach a government clearance platform, a Peppol access point or a bank's file channel has traffic that belongs to neither the operator nor the tenant: it is long-running, it fails for reasons outside anyone's control, it must retry for hours, and it must never be in the request path of a user pressing a button. Giving it its own plane means **a government endpoint being down is a queue depth, not a 500 on someone's screen**. The invoice is issued; its transmission is pending; those are different facts and the architecture keeps them different.

## The twelve modules

```
                    ┌──────────────────────────────────────────┐
                    │  Reporting  (reads everything, writes    │
                    │              nothing)                    │
                    └──────────────────────────────────────────┘
                           ▲         ▲          ▲         ▲
   ┌───────────┐   ┌───────┴───┐ ┌───┴─────┐ ┌──┴──────┐ ┌┴──────────┐
   │ Exchange  │◄──│   Sales   │ │Purchasing│ │ Banking │ │Settlement │
   └───────────┘   └─────┬─────┘ └────┬─────┘ └────┬────┘ └─────┬─────┘
                         │            │            │            │
              ┌──────────┴────────────┴────────────┴────────────┘
              │
   ┌──────────▼───┐  ┌─────────┐  ┌─────────┐  ┌─────────┐
   │     Tax      │  │ Parties │  │ Catalog │  │ Ledger  │
   └──────┬───────┘  └────┬────┘  └────┬────┘  └────┬────┘
          └───────────────┴────────────┴────────────┘
                               │
                        ┌──────▼──────┐
                        │   Tenancy   │
                        └──────┬──────┘
                        ┌──────▼──────┐
                        │  Identity   │
                        └─────────────┘
```

| Module | Owns | May depend on |
|--------|------|---------------|
| `Identity` | Users, sessions, MFA, permissions, SoD, audit log | `Support` |
| `Tenancy` | Tenants, entities, subscriptions, settings | `Identity` |
| `Ledger` | Accounts, journals, entries, periods, dimensions, posting rules | `Tenancy` |
| `Parties` | Customers, suppliers, bank details, credit control | `Tenancy` |
| `Catalog` | Items, price lists, currencies, FX, numbering series | `Tenancy` |
| `Tax` | Codes, rates, place of supply, computation, returns | `Tenancy`, `Ledger` |
| `Sales` | Quotes, orders, invoices, credit notes, dunning, recognition | `Ledger`, `Parties`, `Catalog`, `Tax` |
| `Purchasing` | Purchase orders, bills, approvals, three-way match, claims | `Ledger`, `Parties`, `Catalog`, `Tax` |
| `Banking` | Bank accounts, statements, matching, payment files | `Ledger`, `Parties` |
| `Settlement` | Payments, allocations, FX realization, write-offs, runs | `Ledger`, `Sales`, `Purchasing`, `Banking` |
| `Reporting` | Read models, statements, aging, drill-down, export | everything, **read-only** |
| `Exchange` | Serializers, validation, transports, webhooks | `Sales`, `Purchasing` |

### The two rules that matter most

**`Ledger` depends on nothing but `Tenancy`.** It must not know what an invoice is. This is what makes the posting rules for a jurisdiction changeable without touching `Sales`, and it is enforced by Deptrac rather than by discipline.

**`Reporting` may read everything and may write nothing.** No domain module may depend on `Reporting`, and an architecture test fails on any write operation inside it. A report that can write has become a second source of truth, and the whole promise of the product - that every balance is explainable from the journal - depends on there being exactly one.

## How modules communicate

Three mechanisms, in order of preference:

### 1. Domain events, for consequences

`Sales` does not insert a journal line. It dispatches `InvoiceIssued`, and a `Ledger` listener posts the entry from a posting rule that `Ledger` owns.

```php
// app/Tenant/Sales/Actions/IssueInvoice.php
DB::transaction(function () use ($invoice) {
    $this->validate($invoice);
    $this->freezeTotals($invoice);
    event(new InvoiceIssued($invoice));   // Ledger posts, synchronously, in this transaction
    $this->allocateNumber($invoice);      // last, and with no I/O under the lock
    $this->linkHashChain($invoice);
});
// queued only after commit: PDF render, delivery, clearance submission
```

**Posting listeners are synchronous and inside the originating transaction.** Everything else that reacts to the same event - email, webhooks, search indexing - is queued, and a failure in any of them can never fail or roll back the posting.

### 2. Application services, for queries and commands across a boundary

`Settlement` needs to know an invoice's open balance. It asks `Sales` through a service with a declared return type, not by querying `Sales`' models. Deptrac forbids the second thing, and an architecture test forbids reaching into another module's `Models` namespace.

### 3. Shared value objects, for vocabulary

`Support\Money`, `Support\Iso\Iban`, `Support\Money\RoundingPolicy`. Every module may depend on `Support`; `Support` depends on nothing. A value object here must be immutable and must validate in its constructor, so an invalid instance cannot exist to be passed around.

## Cross-cutting concerns, and where each one lives

| Concern | Where it is handled | Why there |
|---------|---------------------|-----------|
| Tenant and entity resolution | Middleware, pinned ahead of auth | The user table is in the tenant database; a guard running first queries the wrong one |
| Authorization | Policies per module, permissions in `Identity` | The check belongs next to the thing being protected; the vocabulary is shared |
| Segregation of duties | `Identity`, evaluated at the second act | It is a question about history, not about the current request |
| Money arithmetic | `Support\Money`, always with an explicit policy | One implementation, no default rounding mode to get wrong silently |
| Posting | `Ledger` posting rules, registered by each module | Lets the ledger stay ignorant of documents |
| Idempotency | API middleware + a per-operation key in domain actions | The HTTP layer and the domain both need it, for different reasons |
| Audit | `Identity`, driven by domain events and a model observer | Must not be something a developer remembers to call |
| Localization | Request-scoped locale; documents carry the **recipient's** locale | A document is rendered for its reader, not its author |
| Observability | OpenTelemetry at the boundaries, business metrics in the modules | Technical spans at the edges, domain meaning in the middle |

## What is deliberately forbidden

Each of these is enforced by a test rather than by review, because review misses things consistently and tests do not.

- A `float` or `double` anywhere in the money path.
- A write from `Reporting`.
- A module reaching into another module's models.
- A financial table without `entity_id` and without a row-level security policy.
- A `DELETE` on a posted financial row - revoked at the **database role** level, not merely unused.
- An update to a posted journal entry or an issued document.
- A physical CSS property in a template or stylesheet.
- A `__()` key present in one catalog and missing from the other.
- A route that writes without checking a permission.
- A new PHPStan baseline entry.

## Request lifecycle

**Web:**
```
SetLocale → tenant.session → entity.scope → auth:tenant → [plan] → [permission] → Livewire
```

**API:**
```
throttle → idempotency → tenant.header → entity.header → auth:sanctum → [permission] → controller
```

**Operator:**
```
SetLocale → auth:platform → [ability] → controller
```

The ordering is not cosmetic. Both resolvers are pinned ahead of the auth middleware via `prependToPriorityList`, and a test asserts the resulting order - because the ordering is the kind of thing a framework upgrade changes silently.

Three failure states are handled explicitly rather than as errors:

| State | Response |
|-------|----------|
| Subscription became invalid between page loads | Tear down the session, redirect to a dedicated page |
| Tenant database is mid-migration | `503` with `Retry-After`; a maintenance window is a documented state |
| User's entity scope no longer valid | Re-resolve rather than trust the cookie; refuse with an explanation |

## Where to look next

- [`multi-tenancy.md`](multi-tenancy.md) — provisioning, resolution and migrating at scale
- [`money-and-precision.md`](money-and-precision.md) — the three scales and the rounding rule
- [`decisions/`](decisions/) — why each of the above is the way it is, and what lost
