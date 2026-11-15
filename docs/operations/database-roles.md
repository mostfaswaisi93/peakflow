# Database roles and what each one can reach

> `database/docker/init/01-roles.sql` · `php artisan databases:seal` · PF-010 · `bin/verify roles`

Four roles, and a boundary that is enforced at the connection rather than at
the query.

| Role | Reaches | Can |
|------|---------|-----|
| `peakflow_migrator` | everything | own the schema, create databases and roles, run migrations |
| `peakflow_app` | the platform database only | SELECT/INSERT/UPDATE/DELETE, no DDL |
| `pf_t_<ulid>` | one tenant's database | the same, inside that tenant, under RLS |
| `peakflow_readonly` | the platform database only | SELECT |

## The criterion as written, and why it is reframed

PF-010 asks that `peakflow_app` *"cannot connect to the platform database at
all"*. Taken literally that is impossible in this architecture: a request
resolves its tenant by reading the platform database, so the role the
application connects as has to be able to connect to it. A role that cannot is
a role that cannot serve a request.

What the criterion is reaching for is the separation, and the separation runs
the other way round - which is both achievable and stronger:

- the **platform** credential reaches the tenant registry and **no tenant's
  data**, and
- a **tenant** credential reaches one customer's database and **not the
  registry**, and not another customer's.

That is what `bin/verify roles` asserts, in every direction, with real
connection attempts.

## What was found when it was first measured

Both directions were open.

A PostgreSQL database with an empty ACL is **not** one nobody may connect to. An
empty ACL means the built-in default applies, and the built-in default grants
`CONNECT` and `TEMPORARY` to `PUBLIC`. A `GRANT CONNECT ON DATABASE x TO role`
does not change that, because there was never anything to revoke - so the grant
script looked correct and every login role on the cluster could open a session
in every database.

Measured, with the platform credential against a live tenant database:

```
peakflow_app -> a TENANT database   CONNECTED
  information_schema.tables: 0      (the schema grants held)
  pg_class:                 129 tables, with row estimates
      accounts            (~336 rows)
      account_roles       (~120 rows)
      audit_log_2026_01   ...
```

No business row was readable. That is not much comfort: how many accounts a
customer has, which modules they use and whether they have posted anything are
facts about that customer. And `pg_class` is world-readable - it cannot be
revoked - so **the boundary has to be the connection itself**.

The same probe in the other direction had a tenant role opening a session in the
platform database, where every other customer is listed.

## The fix, in three places

1. **`database/docker/init/01-roles.sql`** - `REVOKE ALL ON DATABASE peakflow
   FROM PUBLIC` *before* the grants, so the built-in default is gone. Same for
   the two test databases, which hold whatever a developer last seeded.
2. **`TenantProvisioner`** - every database it creates is sealed in the same
   step that creates it. Sealing afterwards with a command is a window, not a
   fix: between provisioning and the next maintenance run, every role on the
   cluster can reach the new customer.
3. **`php artisan databases:seal`** - for the clusters that already exist, which
   is every cluster that already has customers on it.

`TEMPORARY` goes with `CONNECT`: a role that can create temporary tables in a
database it has no business in can fill that database's disk.

### `databases:seal` means *exactly* these roles

`REVOKE ALL ... FROM PUBLIC` removes only the built-in default. A grant somebody
made by hand survives it, and the first run of the matrix check found two:
`peakflow_readonly` had `CONNECT` on both test databases, from a line nobody
could point to.

So the command also revokes from every login role that is not on the target
list. That is a decision with a cost - it can revoke the access an undocumented
job depends on - which is why it prints every role that loses access, by name,
as it goes:

```
  ok    peakflow_testing
         no longer able to connect: peakflow_readonly
```

Superusers are left alone. Revoking from one does nothing, because a superuser
bypasses privilege checks entirely, and issuing the statement would only make
the output claim a tightening that did not happen.

It is idempotent and safe on a live cluster: `REVOKE` and `GRANT` on a database
take a brief catalog lock and do not touch open sessions, so the sessions it
prevents are gone at their next reconnect rather than mid-transaction.

`peakflow_readonly` keeps the platform database and nothing else. It exists for
reporting replicas and analytics, and the registry is what analytics reads -
plans, subscriptions, tenant counts. Reporting on a customer's own ledger goes
through that tenant's connection, so a blanket `CONNECT` on every tenant
database would be access nothing uses, held by the credential most likely to be
pasted into a BI tool.

## Migrations run as the migrator

`TenantProvisioner::migrate()` clones the pointed tenant connection with the
migrator's credentials and runs `migrate --database tenant_migrator`. The
application's connection keeps no DDL at all - asserted by attempting
`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, `CREATE SCHEMA` and `TRUNCATE` on
it and requiring every one to be refused.

The check asserts this **behaviourally**, and the first version did not. It
grepped `TenantsMigrate.php` for the word "migrator" and failed - correctly, in
the sense that the word is not there, and uselessly, because the command
delegates to the provisioner. A rule that reads files cannot tell delegation
from absence. So DDL is attempted on both connections: refused on the tenant
role, required to succeed on the migrator's.

Over-revoking is not caution here. A `tenant_migrator` that cannot create a
table is a tenant that can never be migrated again, which is why that direction
is asserted too.

## How this is checked

`bin/verify roles` - seven rules, in 1.3 seconds, because a check that is slow
is a check people stop running. It provisions one real throwaway tenant (no
migrations - what is under test is the `CREATE DATABASE` path) and drops it.

| Bite | Fired |
|------|-------|
| `peakflow_app` granted `CONNECT` on a tenant database | The matrix rule and the real-connection rule |
| `PUBLIC` granted `CONNECT` on the platform database | The PUBLIC rule, and the matrix for all three tenant roles |
| `peakflow_app` granted `CREATE` on the platform schema | The DDL rule, on `CREATE TABLE` |
| The provisioner's `sealDatabase()` call removed | The provisioning rule, both halves |

The matrix is built from `has_database_privilege()` rather than by parsing
`datacl`, because an empty `datacl` is exactly the case that matters and parsing
it would read as "nobody" - the misreading that produced the hole. And because a
rule built only from the catalog asserts that the catalog agrees with itself,
the crossings are also attempted with a live connection.

That rule tests **every** tenant pair, in a deterministic order. It used
`DatabaseConnection::first()` - unordered, so one arbitrary tenant - and a probe
that widened a grant on a different one left it passing while the matrix rule
failed. One tenant is a sample; a boundary that holds for one customer and not
the next is the failure this is for.
