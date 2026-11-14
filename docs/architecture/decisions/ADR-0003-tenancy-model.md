# ADR-0003 — Database per tenant, entity scoping inside it

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-019, PF-021, PF-026, PF-244

## Context

Two independent isolation problems, often conflated:

1. **Tenant isolation** — customer A must never see customer B's data.
2. **Entity isolation** — inside customer A, the subsidiary's clerk must not see the holding company's books.

They have different threat models and different operational requirements, and the same mechanism does not serve both well.

## Decision

**A separate physical PostgreSQL database per tenant**, with its own database role, provisioned automatically. A single `tenant` connection is rewritten per request.

**Entity isolation inside the tenant database** by a mandatory non-nullable `entity_id` on every financial table, an application-level global scope, **and** PostgreSQL row-level security policies - two independent mechanisms, because one of them will eventually have a hole.

## Alternatives considered

**One shared database with row-level security for tenants.** Materially less operational work: one migration, one connection pool, straightforward cross-tenant analytics. It was the leading option until one requirement:

> **Per-tenant point-in-time restore.** "Show me this company's books as they stood on 31 December" is an ordinary auditor request. With a dedicated database and a WAL archive it is a documented procedure taking minutes. In a shared database it is cross-table surgery against foreign keys, under time pressure, with no way to restore one tenant without rolling back the others. Nobody will do it correctly.

Three further requirements reinforced it: a departing customer must be able to receive a dump of their own data and nothing else; data residency is a per-tenant fact, trivially expressed as a database location and awkwardly as a predicate; and one tenant's heavy report must not be able to starve another's writes, which per-database connection limits give directly.

**Schema per tenant in one database.** A middle option: real namespace separation, one connection. It loses because PITR is still all-or-nothing, `pg_dump --schema` does not cleanly separate shared extensions, and the catalogue grows to a size where `\dt` and the planner both suffer at a few thousand schemas.

**A separate database per entity rather than per tenant.** Would make entity isolation physical too. Rejected: entities within a tenant share users, parties, items and FX rates, and inter-entity transactions and consolidation would become cross-database joins - which is the problem microservices have, acquired for no benefit.

**Application-scope only for entities, without RLS.** Cheaper. Rejected because a raw query, a console command or a `tinker` session bypasses an Eloquent global scope, and cross-company leakage inside a tenant is a harder failure to detect than cross-tenant leakage - the data looks plausible.

## Consequences

**Easy:** tenant isolation is physical, so a missing scope cannot cross a boundary because there is nothing to cross to; per-tenant PITR, export, residency and resource limits; a credential leak is bounded to one customer.

**Hard — and these are real:**
- **Migrating thousands of databases.** Needs batching, bounded concurrency, per-tenant failure isolation, a schema-version row and an online-safety pre-flight check. Migration time grows linearly with tenant count.
- **Connection pressure.** One pool per active tenant does not scale to a hundred thousand tenants. Mitigated by a shared pooler with per-tenant limits and aggressive idle reaping; the ceiling is real and is a capacity-planning input.
- **Cross-tenant analytics** need a separate aggregation path rather than a query.
- **Schema drift** is possible if a migration fails on a subset; made visible by the schema-version row and a nightly drift report.

**Ruled out:** a single query spanning two tenants, and any feature that would need one.

## Revisit when

Tenant count approaches the point where migration wall-clock time or connection count becomes the binding constraint, and the mitigations above are exhausted. The answer then is probably a hybrid - shared database with RLS for the smallest plan tier, dedicated databases above it - which is a product decision as much as a technical one, and a one-way door for the tenants that move.
