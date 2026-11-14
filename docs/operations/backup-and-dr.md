# Backup and disaster recovery

**This is the requirement that chose the tenancy model**, so it has to actually work. The whole argument for database-per-tenant over shared-with-RLS was per-tenant point-in-time recovery — see [`ADR-0003`](../architecture/decisions/ADR-0003-tenancy-model.md). If the restore path is untested, that trade was paid for nothing.

## The question this exists to answer

> **"Show me this company's books as they stood on 31 December."**
>
> An ordinary auditor request. With a dedicated database and a WAL archive it is a documented procedure taking minutes. In a shared database it is cross-table surgery against foreign keys, under time pressure, with no way to restore one tenant without rolling back the others — and nobody will do it correctly.

## What is backed up

| Asset | Mechanism | Frequency | Retention |
|-------|-----------|-----------|-----------|
| Tenant databases | Base backup + **continuous WAL archiving** | Base weekly, WAL continuous | 35 days PITR; monthly base retained 7 years |
| Platform database | Base backup + WAL archiving | Base daily, WAL continuous | 35 days PITR |
| Object storage — archived documents | **WORM / object-lock**, cross-region replication | On write | **Per the jurisdiction's statutory period**, 5–11 years |
| Attachments | Versioned object storage, cross-region | On write | Tenant lifetime + 90 days |
| Secrets and keys | KMS with its own backup and rotation history | Managed | Per key policy |
| Configuration and infrastructure | Git | Per commit | Indefinite |

**Archived documents are not "backed up" in the ordinary sense** — they are under object-lock retention, which means they **cannot be deleted before their retention expires even by an operator**. That is the point: the archive is the legal record.

## Targets

| Tier | RPO | RTO | Scope |
|------|-----|-----|-------|
| Platform database | < 1 minute | < 1 hour | Operator plane |
| Tenant database, single-tenant restore | < 1 minute | **< 30 minutes** | One tenant to a point in time |
| Tenant database, full-region loss | < 5 minutes | < 4 hours | All tenants, standby region |
| Object storage | 0 (replicated on write) | < 1 hour | Documents and attachments |

**These are measured, not aspired to.** The drill below produces the actual numbers, and the release notes record them.

## Restore procedures

### One tenant to a point in time

```bash
# 1. Provision an isolated target. Never restore over a live database.
#    A restore that overwrites production is how a recovery becomes an incident.
pf-restore prepare --tenant 01JD3X... --target isolated-restore-01

# 2. Base backup plus WAL replay to the requested moment.
pf-restore pitr --tenant 01JD3X... \
                --target isolated-restore-01 \
                --to '2026-12-31 23:59:59+03'

# 3. Prove the restored ledger before anyone reads it.
php artisan ledger:verify --connection=isolated-restore-01 --all-entities
php artisan invoices:verify-chain --connection=isolated-restore-01

# 4. Read-only access, time-limited, audited.
pf-restore expose --target isolated-restore-01 --read-only --expires 72h
```

**The restore is always to an isolated target.** `ledger:verify` runs against it **before** anyone reads it, because a restore that silently restored an inconsistent state is worse than a failed restore.

### Full region loss

1. Promote the standby region's replicas.
2. Repoint DNS and the connection registry.
3. Verify object-storage replication currency.
4. Run `ledger:verify` across a **sample of tenants**, weighted toward the largest, before reopening writes.
5. Reopen writes; reconcile any `Exchange` transmissions that were in flight — the dead-letter queue is the authority on what to re-drive.

### A single tenant's logical export

```bash
php artisan tenants:dump 01JD3X... --include-attachments
```

Used for data requests, for migrations, and for a departing customer. It exports **everything that tenant owns and nothing else**, with a manifest and checksums.

## The drill — the part that makes this real

> **A backup nobody has restored is not a backup.**

A **scheduled restore drill** runs automatically:

| Cadence | What it does |
|---------|--------------|
| **Weekly** | Restores a randomly selected tenant to a random point in the last 35 days, into an isolated environment; runs `ledger:verify` and `invoices:verify-chain`; records the measured RTO; tears down |
| **Monthly** | Restores the largest tenant, to measure the realistic worst case |
| **Quarterly** | Full-region failover exercise in staging, including DNS and the connection registry |
| **Weekly** | `archive:verify` — re-checksums the archive manifest against object storage and verifies PDF/A conformance of a restored sample |

Each drill **reports**, and a failure is an alert rather than a line in a log. The measured RTO goes into the release notes next to the ASVS result, so a degrading restore time is visible before it is needed.

## What a restore cannot fix

Stated plainly, because a disaster-recovery document that implies otherwise is dangerous:

| Scenario | Why a restore does not help | What does |
|----------|----------------------------|-----------|
| **A bad posting rule** that produced wrong but *valid* entries for three weeks | The data is internally consistent, so a restore restores the error | Reversing entries, in the open period, with an audit trail. This is what append-only history is for |
| **A document already transmitted to an authority** | The authority has it; restoring our copy changes nothing | A credit note or a correction through the jurisdiction's own mechanism |
| **A payment already released to a bank** | The money has left | A recall request through the bank, which may fail. This is why maker-checker and step-up auth exist at the release boundary |
| **A deleted tenant past its grace window** | The data is gone by design | The grace window itself: 30 days of soft deletion before anything is irrecoverable |

The controls that prevent these are in [`security-hardening.md`](security-hardening.md), and they are the real protection. Backups protect against infrastructure failure; append-only history and maker-checker protect against the failures that are far more likely.

## Tenant-visible controls

- A tenant can request an export of everything it owns, at any time.
- A tenant can see when its data was last backed up and when its last drill succeeded.
- A tenant's declared data region is shown, and a restore stays in region.
- Deletion is soft for 30 days, then irrecoverable — **except** for documents under statutory retention, which survive and are explained rather than silently kept.
