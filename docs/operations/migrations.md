# Migrations against a database somebody is using

**PF-246.** This is the playbook. It is not advice: every rule below is
enforced by `MigrationSafetyInspector`, which `bin/verify arch` runs over every
file in `database/migrations/` and which `tenants:migrate` runs as a pre-flight
before it touches a tenant. A migration that breaks one of these does not get
as far as the cluster.

The reason the rules are written down as well as enforced is that the inspector
can only say *no*. It cannot say what to do instead at the moment somebody is
deciding, which is a week earlier and in a design conversation.

---

## 1. Expand and contract are two releases, never one

The shape of every schema change that touches something already in use:

| Release | What it does | What is running |
|---|---|---|
| **Expand** | Add the new column, table or index. Nothing reads it. | Old code, unaffected |
| **Migrate** | Write to both. Backfill the old rows in batches. | Both shapes valid |
| **Contract** | Stop writing the old one, then drop it. | New code only |

The rule behind the table: **at every instant, the schema must be valid for the
code that is running, and the code must be valid for the schema that exists.**
A deploy is not atomic - old and new instances overlap for seconds or minutes,
and a queued job enqueued before the deploy runs after it.

That is why a rename is not a rename. `renameColumn` is instant in the database
and breaks every running instance of the old code the moment it commits. It is
a deploy-coordination problem wearing a migration costume, and it is done as
expand-contract like everything else.

## 2. Every migration declares whether it may run live

Three markers, one of which must appear in every file, each with a reason
after it. A bare marker fails the check, because a marker with nothing after
it says the same as none except that it silences the question.

- **`@online-safe <why>`** - it may run while traffic is served.
- **`@online-unsafe <why>`** - it may not. The reason names what it locks, so
  a deployment can schedule it. This answers the lock hazards and only those:
  it does not excuse a rename or a dropped column, which break the running
  code *after* the window closes.
- **`@unsafe-migration-reviewed: <why>`** - a hazard the inspector flagged
  that is genuinely fine here. Per line as well as per file, so one accepted
  hazard does not blanket-excuse every other one the file might grow later.

Writing the declaration is the point. `@online-safe` next to an
`ALTER TABLE ... ADD CONSTRAINT` is a claim somebody can be shown to be wrong
about; writing nothing is not. Sixty-four of a hundred and thirty-seven
migrations carried no marker at all before this was enforced, including nine
that scan an existing table under an exclusive lock.

## 3. What the inspector refuses, and what to do instead

Each heading is a hazard name as the inspector reports it. The wording is kept
in step by a check - `ArchitectureRules::everyHazardIsInThePlaybook()` fails if
the inspector learns a hazard this file does not name.

### not-null column without a default

Adds a NOT NULL column with no default, which fails outright if the table has
any rows and rewrites it if a volatile default is supplied.

**Instead:** add it nullable, backfill in batches, then set NOT NULL in a later
migration - or give it a constant default, which PostgreSQL 11+ applies without
a rewrite.

### column type change

Rewrites the table and holds ACCESS EXCLUSIVE for the duration, except for a
few widening casts PostgreSQL can do in place.

**Instead:** add a new column, backfill, switch readers, then drop the old one.
If the cast really is in place - `varchar(n)` to `varchar(m)` where m > n,
`varchar` to `text` - say so in the annotation.

### index without CONCURRENTLY

Builds the index while holding a lock that blocks writes to the table.

**Instead:** `CREATE INDEX CONCURRENTLY` in a migration of its own, outside a
transaction. A Laravel migration wraps itself in one, so this needs
`$this->withinTransaction = false`.

### foreign key without NOT VALID

Takes a SHARE ROW EXCLUSIVE lock on both tables and validates every existing
row.

**Instead:** `ADD CONSTRAINT ... NOT VALID`, then `VALIDATE CONSTRAINT` in a
second migration - the validation takes only SHARE UPDATE EXCLUSIVE and does
not block writes.

### CHECK constraint without NOT VALID

Scans every row of an existing table under ACCESS EXCLUSIVE, so reads and
writes both wait for the length of a full scan.

**Instead:** the same two-step as a foreign key. `2026_01_03_000800_a_bill_can_be_cancelled`
is the pattern to copy. A constraint on a table the same migration creates is
not a hazard and the inspector does not report it - nothing can be reading a
table that did not exist a moment ago.

### rename

Instant in the database, and breaks every running instance of the OLD code the
moment it commits.

**Instead:** add the new name, write to both, migrate readers, then drop the
old one.

### dropping a column

Instant, and breaks any running code still selecting it - including a queued
job enqueued before the deploy.

**Instead:** stop writing it, deploy, confirm nothing reads it, then drop it in
a later release.

### ALTER on a partitioned table

Takes ACCESS EXCLUSIVE on the parent **and every partition**, so a table with
25 monthly partitions locks 26 relations at once. `journal_lines` is RANGE
partitioned on `accounting_date`, and it is the busiest table in the product.

**Instead:** alter each partition individually where the operation allows it,
or schedule the parent alteration in a maintenance window and say so in the
annotation.

### unbounded UPDATE

Writes every row in one transaction, holding row locks and generating WAL
proportional to the table.

**Instead:** batch it. See below.

## 4. Backfills are batched and resumable

A backfill is not a migration statement. It is a job that runs for hours,
survives being killed, and can be started again without doing the work twice.

- **Batched**, by primary key, with a pause between batches. `chunkById`, not
  `chunk`: an offset walks past rows that moved.
- **Resumable**, which means the batch is idempotent and the progress is
  derivable from the data. `WHERE new_column IS NULL` is a resumable predicate;
  a counter in a file is not.
- **Outside the migration.** A migration that backfills holds its transaction
  for the length of the backfill, and a deploy that waits on it is a deploy
  that times out halfway through.

## 5. A tenant mid-migration answers

`tenants:migrate` sets a tenant's status to `migrating`, and
`TenantConnectionResolver` refuses it with **503 and `Retry-After`** rather
than letting requests run against a schema the code does not match. Without
it, every request becomes its own failure - a missing column here, a
constraint violation there - and what reaches the operator is a scatter of
unrelated-looking errors during the ten minutes they are least able to read
them.

The operator console shows each tenant's `schema_version` beside its status,
marked when it is behind what the migration files declare. "migrating" on its
own says a migration is happening, not whether it is progressing.

A tenant left in `migrating` by a run that died is returned with
`tenants:migrate --unstick <minutes>`.

## 6. Rolling back

A migration's `down()` is not the rollback procedure. It is one tool in it,
and for several migrations in this product it deliberately does less than
`up()` did:

- `a_bill_can_be_cancelled` drops its constraints and **keeps its columns**,
  because `cancellation_reason` holds the only record of why a bill is not a
  bill, and a rollback is run in a hurry by somebody who has decided the
  deployment was wrong - the worst moment to find that the undo also deleted
  the explanations.
- `refuse_posted_mutation_speaks_on_lines` reverts **nothing**, because
  putting the old function back would restore a trigger that raises the wrong
  error. `down()` exists to undo a change that turned out to be wrong, not to
  reinstate a defect.

The case the procedure has to cover is the schema moving forward while the
code goes back. Expand-contract is what makes that survivable: after an expand
release, the old code still works, because nothing it reads has moved. After a
contract release, it does not - which is why contract is a separate release
with its own decision.
