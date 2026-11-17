# Local development

> `composer setup && composer dev` · `compose.yaml` · PF-001, PF-011 · `bin/verify repo`

## One command, and what it actually does

```
composer setup     # a fresh clone to a seeded, signed-in application
composer dev       # the server, the queue worker, Vite and the log tail
```

`composer setup` runs [`bin/setup`](../../bin/setup), which is a script rather
than a list in `composer.json` because the list was five lines and three of them
were wrong in ways a list cannot express:

| It did | Which failed because |
|--------|----------------------|
| `php artisan migrate` | That runs as the **application** role, which PF-010 gives no DDL at all. A fresh clone's first command failed with a permission error that reads as a broken database |
| nothing about services | So the migration had nothing to connect to |
| no seeding | So what a developer got was a login form, no workspace to log into, and no way to discover the credentials |

Those are conditionals, ordering and error messages. A composer script array has
none of the three.

The script is idempotent, waits on compose's healthchecks with `--wait` rather
than racing them, migrates as `peakflow_migrator`, seeds, and ends by printing
the credentials.

### The credentials it prints

| Where | Workspace | Email | Password |
|-------|-----------|-------|----------|
| `/login` | `demo` | `owner@demo.test` | `peakflow` |
| `/ops/login` | — | `ops@peakflow.test` | `peakflow` |

`DevelopmentSeeder` throws outside `local` and `testing`. The password is a
known constant, which is the point of a development seeder and a catastrophe in
production - and `db:seed --class=DevelopmentSeeder --force` is one typo away
from a production shell, with `--force` existing precisely to get past the
confirmation that would otherwise have stopped it.

It provisions the tenant through the real provisioner rather than inserting a
row. A seeder that writes the `tenants` row directly produces a tenant with no
database, which fails at the first query with a message about a missing
password - and the developer's first experience of the product is a bug that
exists only in their seed data.

### Demo books, for a screen with numbers on it

```
php artisan db:seed --class=DemoTenantSeeder
```

Five months of a trading company in the DEMO entity: six customers and five
suppliers, about thirty invoices issued through the real Issuer, about twenty
bills posted through the real Bills service, receipts and payments recorded
and allocated through Settlement, a bank account with one statement a month
imported through the real importer, and every line older than a fortnight
matched. The VAT return periods are filed up to two months back.

Through the services, never into the tables, so the result reconciles: the sum
of every posted line is zero, and the bank account's ledger balance equals the
last statement's closing balance. It refuses outside `local` and `testing`,
runs in one transaction, and a second run says the books are already there
rather than doubling them.

## The commit-message hook

`composer setup` points `core.hooksPath` at `.githooks`, rather than copying
files into `.git/hooks`: a copy goes stale the moment the hook changes and
nobody re-runs the installer, which makes the hook a thing that works on the
machine of whoever wrote it.

The hook is POSIX `sh` with no dependencies - it runs on the machine with no
bash, no node and no vendor directory - and it enforces what CONTRIBUTING.md
states:

```
feat(ledger): enforce the balanced-entry invariant as a deferred constraint

Refs PF-043
```

It refuses a subject that is not `type(scope): summary`, one over 72 characters,
one starting with a capital that is not an acronym or an identifier, one ending
in a full stop, a non-blank second line, and - for `feat`, `fix`, `refactor` and
`perf` - a message with no `PF-###` reference. `docs`, `chore`, `ci`, `build`,
`test` and `revert` need no task: a typo fix with a task number is paperwork,
and paperwork that is required is paperwork that gets faked.

Merge commits and `fixup!`/`squash!`/`amend!` subjects pass through, because a
rebase folds them away.

**A CI check is not a substitute.** It tells you the message is wrong after the
commit exists, and the only fix is to rewrite history - which CONTRIBUTING.md
forbids on a shared branch. So the check has to run before the commit is
written, and the only place that happens is a hook.

## Resource expectations, measured

On this repository, with PostgreSQL 16.15 and the schema as committed:

| Thing | Size |
|-------|-----:|
| Platform database, migrated and seeded | **9 MB** |
| One tenant database, migrated (131 tables) | **19 MB** |
| `vendor/` | **482 MB** |
| `node_modules/` | **88 MB** |

So a hundred tenants is about **2 GB** of PostgreSQL data, and the repository
plus dependencies is about **600 MB** before any of it.

### The number to understand is `max_connections`

`tenancy.connections.max_per_tenant` is **5**. With PostgreSQL's default
`max_connections = 100`, that is **twenty** concurrently-served tenants before
the cluster refuses connections - and `tenants:migrate --all` at the default
concurrency of 4 holds four more.

This is the resource limit that bites first in a database-per-tenant
architecture, and it is a cluster setting rather than an application one, so no
amount of application tuning moves it. In production it is a connection pooler
(PgBouncer in transaction mode); locally it is a number to be aware of before
seeding fifty tenants.

### The cut-down laptop setup is two mechanisms

A compose profile can only include or exclude a *service*. It cannot change
one's command, so running a smaller PostgreSQL needs an override file:

```
docker compose -f compose.yaml -f compose.laptop.yaml --profile laptop up -d --wait
```

- `--profile laptop` selects **postgres + redis only** - no MailPit, no
  Gotenberg, no veraPDF, no Meilisearch.
- `compose.laptop.yaml` makes those two **smaller**: `shared_buffers` 128 MB →
  32 MB, `max_connections` 100 → 40, `synchronous_commit=off`, slow-query log
  at 1000 ms rather than 250 ms, and a Redis with no persistence capped at
  128 MB with an LRU policy.

Two things are deliberately **not** reduced:

- **`pg_stat_statements`** stays loaded. The query-budget checks assert index and
  partition use through `EXPLAIN`, and dropping it would make those checks pass
  on a laptop for the wrong reason.
- **`max_locks_per_transaction` stays at 256.** A journal with monthly
  partitions takes many locks in one transaction, and lowering it turns a
  correct migration into `out of shared memory`. That is not a resource trade,
  it is a feature that stops working.

`synchronous_commit=off` risks losing the last few transactions on a hard power
cut and never corrupts anything, which is an acceptable trade for seed data and
for nothing else.

## PostgreSQL is required, and SQLite will not do

Not a preference. The tenant schema uses deferrable constraint triggers,
row-level security, declarative partitioning and `numeric` semantics, and the
ledger's invariants live there rather than in application code - so the tenant
suite cannot run on SQLite, because those features **are the thing under test**.
`bin/setup` says so and stops rather than falling back. See ADR-0002 and
[`testing-strategy.md`](testing-strategy.md).

## What `composer dev` starts

`bin/dev-up --no-verify` first, to bring the services up and wait for them, then
four processes under `concurrently`: `artisan serve`, `queue:listen`,
`npm run dev` and `pail`.

The `--no-verify` is deliberate. `bin/dev-up` on its own ends by running the
checks, which is the point of running it by hand and is noise in front of four
long-running processes.
