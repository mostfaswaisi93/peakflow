# Multi-tenancy and multi-entity

Two separate structures that are easy to conflate and expensive to add late.

| | **Tenant** | **Entity** |
|---|------------|------------|
| Is | A customer of the platform | A legal company that files its own return |
| Isolated by | A separate physical database | `entity_id` + row-level security, inside one database |
| Owns | Users, parties, items, currencies, rates, documents | Chart of accounts, functional currency, fiscal calendar, tax registrations, numbering, all postings |
| Created by | Operator provisioning | A tenant user |

A group with a holding company, two operating companies and a free-zone subsidiary is **one tenant, four entities**.

## Why database-per-tenant

The alternative - one database with row-level security - is less operational work and was seriously considered. The full comparison is in [`decisions/ADR-0003-tenancy-model.md`](decisions/ADR-0003-tenancy-model.md). It lost on one requirement:

> **Per-tenant point-in-time restore.** An auditor asking "show me this company's books as they stood on 31 December" is answerable in minutes with a dedicated database and a WAL archive. In a shared database it is cross-table surgery against foreign keys, and nobody will do it under time pressure.

Three secondary reasons reinforce it: a departing customer must be able to take a `pg_dump` of their own data and nothing else; a per-tenant restore must not roll back other tenants; and data residency is a per-tenant fact, which is simple when a tenant is a database and awkward when it is a predicate.

Row-level security is still used - **inside** each tenant database, to scope rows to an entity, as defence in depth behind the application's own scope.

## Provisioning

`tenants:provision` has two modes.

**Create mode** (self-hosted or a server where the role has the privileges):

```
BEGIN
  1. Insert the tenant row (status: provisioning)
  2. CREATE ROLE tenant_<ulid> WITH PASSWORD <generated>
  3. CREATE DATABASE peakflow_<ulid> OWNER tenant_<ulid>
  4. Store the connection, password encrypted at the column level
  5. Run database/migrations/tenant as peakflow_migrator
  6. Apply row-level security policies
  7. Seed: permissions, roles, chart template, the first entity, the first user
  8. Mark the tenant active
COMMIT  — or roll back everything, including dropping what was created
```

Rules that each exist because of a specific failure:

- **A generated role per tenant**, not a shared application role with many databases. A credential leak is then bounded to one customer.
- **Idempotent and step-wise reversible.** A failure at step 6 must not leave an orphan database that nobody knows about and that still counts against the server's limits. The rollback path is tested by injecting a failure at each step.
- **Provisioning runs as `peakflow_migrator`.** The application role has no `CREATE DATABASE`, which means a compromised application cannot create one.
- **The password is never logged**, never serialized by a model, and is re-encrypted on key rotation.

**Link mode** attaches a pre-created database, for managed hosting where `CREATE DATABASE` is unavailable. The connection is verified, the schema version is checked, and migrations are applied before the tenant becomes usable. The mode is recorded on the tenant, because the backup and restore procedure differs between them.

## Connection resolution

```php
// App\Platform\Services\TenantConnectionResolver
public function resolve(string $tenantKey): Tenant
{
    $tenant = $this->findOrFail($tenantKey);

    throw_if($tenant->isSuspended(), SubscriptionInactive::class);
    throw_if($tenant->isExpired(),   SubscriptionInactive::class);
    throw_if($tenant->isMigrating(), TenantMigrating::class);   // → 503 + Retry-After

    config(['database.connections.tenant' => $tenant->connection->toConfig()]);
    DB::purge('tenant');

    app()->instance('currentTenant', $tenant);

    return $tenant;
}
```

Every tenant model hard-codes its connection:

```php
public function getConnectionName(): string
{
    return 'tenant';
}
```

An architecture test asserts every model under `app/Tenant` does this, so a tenant query follows whichever database was most recently selected and can never read platform data - even if a developer forgets a scope.

### Middleware ordering is load-bearing

```
SetLocale → tenant.session → entity.scope → auth:tenant → …
```

The `users` table is in the tenant database. If `auth:tenant` ran first it would query whatever connection happened to be configured - in development, the platform database; in production, the previous request's tenant. Laravel sorts middleware by a priority list, so both resolvers are pinned ahead of auth in `bootstrap/app.php`:

```php
$middleware->prependToPriorityList(
    before: \Illuminate\Auth\Middleware\Authenticate::class,
    prepend: [ResolveTenant::class, ScopeEntity::class],
);
```

**A test asserts the resulting order**, because this is exactly the kind of thing a framework upgrade reorders silently and the symptom - intermittent authentication against the wrong database - is one of the worst to diagnose.

## Entity scoping, twice

Two independent mechanisms, because one of them will eventually have a hole.

**1. The application scope.** `entity.scope` middleware binds `currentEntity` and refuses an entity the user holds no role in. A global Eloquent scope applies `entity_id` to every financial model.

**2. Row-level security.** Policies on every financial table in the tenant database, keyed on a session variable set per request:

```sql
ALTER TABLE journal_lines ENABLE ROW LEVEL SECURITY;

CREATE POLICY journal_lines_entity ON journal_lines
    USING (entity_id = current_setting('peakflow.entity_id', true));
```

A test proves that bypassing Eloquent - a raw query, a console command, a `tinker` session - still returns nothing for a foreign entity. The application scope is for correctness and good errors; RLS is for the case where the application is wrong.

Two architecture tests close the loop: every financial table must have a non-nullable `entity_id`, and every such table must have an RLS policy. "We will scope it later" is how cross-company data leaks happen, and later never has a deadline.

## Entity switching

The active entity is re-resolved and re-authorized **on every request**, never trusted from the cookie. Switching re-evaluates permissions, because a user's roles differ per entity.

The active entity is displayed on every screen and on every print output. This is a correctness feature, not decoration: **a user posting into the wrong company** is a mistake that is silent at the time, discovered at consolidation, and corrected by reversing entries in two sets of books.

## Migrating thousands of databases

The hard operational problem of this architecture, and the honest cost of choosing it.

`tenants:migrate --all` runs with batching, bounded concurrency and per-tenant failure isolation - one tenant's broken migration must not stop the other nine hundred. Each tenant carries a schema-version row, so a request can detect a tenant that is behind and respond rather than fail.

**A pre-flight check refuses a migration that is not safe to run while serving traffic:**

| Refused | Because |
|---------|---------|
| A non-nullable column with no default | Rewrites the table and locks it |
| `ALTER TYPE` on a large column | Full table rewrite |
| An `ACCESS EXCLUSIVE` lock on a partitioned table | Blocks every posting across every partition |
| An unbatched backfill | Holds one transaction over millions of rows |
| Adding an index without `CONCURRENTLY` | Blocks writes for the duration |

Every migration declares whether it is online-safe, and the check enforces the declaration. The expand-contract playbook - add, backfill in batches, switch reads, switch writes, drop - is in [`../operations/ci-cd.md`](../operations/ci-cd.md) and is `PF-246`.

A tenant mid-migration returns `503` with `Retry-After` rather than failing, and the operator console shows progress per tenant. A maintenance window that is visible is tolerable; one that presents as random errors is not.

## Per-tenant operations this buys

| Operation | Command | Why it matters |
|-----------|---------|----------------|
| Point-in-time restore | WAL archive per database | The auditor's question, answerable in minutes |
| Logical export | `tenants:dump` | Data requests, migrations, a departing customer |
| Restore drill | Scheduled; restores to an isolated environment and runs `ledger:verify` | A backup nobody has restored is not a backup |
| Residency | A tenant's database lives in its declared region | Provable rather than asserted |
| Noisy-neighbour isolation | Per-database connection limits | One tenant's report cannot starve another |

## Known limits

Stated plainly, because an architecture document that lists only advantages is marketing:

- **Connection pressure.** One pool per active tenant does not scale to a hundred thousand tenants. The mitigation is a shared pooler with per-tenant limits and aggressive idle reaping; the ceiling is real and is a capacity-planning input, not a solved problem.
- **Cross-tenant analytics are awkward.** Platform-wide reporting requires a separate aggregation path - events pushed to a warehouse - rather than a query. Accepted: the operator's analytics needs are modest and known.
- **Migration time grows linearly with tenant count.** Bounded concurrency helps; it does not change the order of growth.
- **Schema drift is possible** if a migration fails on a subset. The schema-version row makes it visible, and a drift report runs nightly.
