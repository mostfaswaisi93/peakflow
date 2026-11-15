# Archiving an old partition without blocking a posting

`journal_lines` and `audit_log` are range-partitioned by month. Partitions are created ahead of time by a scheduled task, never on demand during a request — a partition created while somebody is posting is a DDL lock taken during the month-end rush.

This document is the other half: detaching an old month, archiving it, and removing it, **without taking a lock that blocks posting**.

---

## The lock that ruins the whole thing, and the one word that avoids it

```sql
-- WRONG. Takes ACCESS EXCLUSIVE on the PARENT for the duration, which blocks
-- every INSERT into every partition, including this month's.
ALTER TABLE journal_lines DETACH PARTITION journal_lines_2024_01;

-- RIGHT. Takes SHARE UPDATE EXCLUSIVE on the parent instead: readers and
-- writers to the other partitions continue.
ALTER TABLE journal_lines DETACH PARTITION journal_lines_2024_01 CONCURRENTLY;
```

`DETACH ... CONCURRENTLY` exists from PostgreSQL 14 and the production target is 16, so there is no version where the blocking form is the only option.

Three constraints come with it, and each one has bitten somebody:

- **It cannot run inside a transaction block.** It is a two-step operation internally and needs to commit between the steps. `psql` with `--single-transaction`, a Laravel migration wrapped in a transaction, or any tool that opens one will fail with `cannot run inside a transaction block` — and the sensible reaction, wrapping it in a transaction "for safety", is exactly wrong.
- **It waits for every transaction that can still see the partition.** A long-running analytical query or an idle-in-transaction session will stall it indefinitely. Check before starting, and do not `kill -9` the detach.
- **If it is interrupted**, the partition is left in a transitional state and the table reports it as `DETACH PENDING`. The only way forward is to finish it:

```sql
ALTER TABLE journal_lines DETACH PARTITION journal_lines_2024_01 FINALIZE;
```

---

## The procedure

### 0. Decide what "old" means, in writing

A partition may be archived when **every period it covers is `locked`** — not merely closed. `locked` is the terminal state, set after statutory filing, with no application path back and a database trigger enforcing it. A closed period can be reopened; a locked one cannot, and that is the only state in which detaching a month cannot possibly need undoing.

```sql
-- Months whose periods are ALL locked, oldest first.
SELECT
    to_char(p.starts_on, 'YYYY_MM')                    AS partition_suffix,
    count(*)                                           AS periods,
    count(*) FILTER (WHERE p.state = 'locked')         AS locked,
    min(p.starts_on)                                   AS from_date,
    max(p.ends_on)                                     AS to_date
FROM periods p
GROUP BY 1
HAVING count(*) = count(*) FILTER (WHERE p.state = 'locked')
ORDER BY 1;
```

Retention is a legal question, not an engineering one. Saudi Arabia's VAT Implementing Regulations require records to be kept for six years, and longer for capital assets and real-estate transactions; Jordan's requirements are their own. **Nothing in this document shortens a statutory retention period** — archiving moves a month out of the hot table, it does not license deleting it.

### 1. Confirm nothing is holding a snapshot

```sql
SELECT pid, state, now() - xact_start AS in_transaction_for, left(query, 60) AS query
FROM pg_stat_activity
WHERE datname = current_database()
  AND xact_start IS NOT NULL
  AND now() - xact_start > interval '1 minute'
ORDER BY xact_start;
```

An `idle in transaction` session here will stall the detach. Deal with it first.

### 2. Detach, concurrently, outside a transaction

```bash
psql "$TENANT_URL" -c \
  "ALTER TABLE journal_lines DETACH PARTITION journal_lines_2024_01 CONCURRENTLY;"
```

The partition is now an ordinary, standalone table with the same name. **It still holds every row**, nothing has been deleted, and the parent no longer routes to it or scans it.

At this point a query for January 2024 returns nothing. That is the moment of visible change, and it is why step 0 insists on `locked`.

### 3. Verify, then archive

```sql
-- The detached table, standing alone.
SELECT count(*), min(accounting_date), max(accounting_date), pg_size_pretty(pg_total_relation_size('journal_lines_2024_01'))
FROM journal_lines_2024_01;
```

```bash
# Archive it with the schema needed to read it back, and verify the dump
# before anything is dropped.
pg_dump "$TENANT_URL" --table=journal_lines_2024_01 --format=custom \
  --file=/archive/${TENANT}/journal_lines_2024_01.dump

pg_restore --list /archive/${TENANT}/journal_lines_2024_01.dump > /dev/null \
  && sha256sum /archive/${TENANT}/journal_lines_2024_01.dump \
     > /archive/${TENANT}/journal_lines_2024_01.dump.sha256
```

Then, and only then:

```sql
DROP TABLE journal_lines_2024_01;
```

> **A dump that has never been restored is not a backup.** The archive restore is exercised as part of the DR drill in [`backup-and-dr.md`](backup-and-dr.md), against a scratch database, and the row count and date range are compared to what step 3 recorded. An archive nobody has read back is a file, not a record.

### 4. Record it

```sql
-- The audit log is the right place: the question is "where did January 2024 go",
-- and it is asked years later.
INSERT INTO audit_log (...)  -- via AuditTrail, with action 'ledger.partition_archived'
```

The entry carries the partition name, the row count, the date range, the archive path and the dump's checksum. Without it, the answer to "why does this report stop in February 2024" is archaeology.

---

## Reattaching

A month comes back as an ordinary attach, and the parent does take `ACCESS EXCLUSIVE` for it — briefly, because PostgreSQL has to validate that every row falls inside the bound. The validation is what costs, and it can be avoided entirely:

```sql
-- Restore the table first.
pg_restore --dbname="$TENANT_URL" /archive/${TENANT}/journal_lines_2024_01.dump

-- A matching CHECK constraint lets ATTACH skip the scan: PostgreSQL trusts
-- the constraint instead of reading every row. Without this, attaching a
-- 40-million-row month holds the lock for the length of a full scan.
ALTER TABLE journal_lines_2024_01
    ADD CONSTRAINT journal_lines_2024_01_bound
    CHECK (accounting_date >= '2024-01-01' AND accounting_date < '2024-02-01');

ALTER TABLE journal_lines ATTACH PARTITION journal_lines_2024_01
    FOR VALUES FROM ('2024-01-01') TO ('2024-02-01');

-- The constraint is redundant once the bound is in place.
ALTER TABLE journal_lines_2024_01 DROP CONSTRAINT journal_lines_2024_01_bound;
```

Do it in a maintenance window regardless. The lock is short, not absent.

---

## What must not be done

| Never | Because |
|-------|---------|
| `DETACH PARTITION` without `CONCURRENTLY` | `ACCESS EXCLUSIVE` on the parent blocks every posting in every month |
| `DETACH ... CONCURRENTLY` inside a transaction | It cannot run in one, and wrapping it "for safety" is the instinct that breaks it |
| `DROP TABLE` before the archive has been restored once | A dump that has never been read back is a file, not a record |
| Archive a month with any period not `locked` | A closed period can be reopened; a locked one cannot, and that is the whole test |
| `TRUNCATE` a partition to "clean it up" | It is a deletion with no archive and no audit entry, of records under statutory retention |
| Detach the **default** partition | It is where a posting outside the declared range lands. Detaching it turns "stored somewhere the planner cannot prune" into "rejected" — see below |

---

## The default partition is a safety net, and a non-empty one is an alert

`journal_lines_default` exists so that a posting dated outside the declared range is never *rejected*. Losing a posting because nobody extended the partitions would be far worse than storing it somewhere the planner cannot prune.

Which makes a non-empty default partition an **alert**, not a state to tidy away:

```sql
SELECT count(*) AS rows_in_default, min(accounting_date), max(accounting_date)
FROM journal_lines_default;
```

Anything other than zero means the scheduled task that extends the range has stopped running. The fix is to create the missing partitions and move the rows into them — which, unlike everything else in this document, is best done in a transaction:

```sql
BEGIN;
CREATE TABLE journal_lines_2027_01 (LIKE journal_lines INCLUDING ALL);
INSERT INTO journal_lines_2027_01
    SELECT * FROM journal_lines_default
    WHERE accounting_date >= '2027-01-01' AND accounting_date < '2027-02-01';
DELETE FROM journal_lines_default
    WHERE accounting_date >= '2027-01-01' AND accounting_date < '2027-02-01';
ALTER TABLE journal_lines ATTACH PARTITION journal_lines_2027_01
    FOR VALUES FROM ('2027-01-01') TO ('2027-02-01');
COMMIT;
```

`DELETE` on `journal_lines` is revoked from the application role, so this runs as the migrator. The deletion is of rows being moved, not removed — and the `INSERT` before it is what makes that true, so the two belong in one transaction.

---

## Why pruning is worth all of this

Measured on a seeded year, by `bin/bench trialbalance`:

| Range | Partitions scanned |
|-------|--------------------|
| one month | 1 |
| one quarter | 3 |
| the whole year | 12 |

A query without a date bound scans every month ever posted and gets slower every month — correct, fast in the first year, and nobody notices until year three. The benchmark asserts the partition count read off `EXPLAIN`, so a report that loses its bound fails the build rather than a support call.
