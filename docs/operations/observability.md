# Observability

Three layers, and the third is the one that is unusual and the one that cannot be retrofitted.

## 1. Traces

OpenTelemetry spans across **the HTTP request, the queued job and the outbound transport**, with a correlation id propagated through all three.

> "Why did this invoice take four minutes to clear" is **one trace**, not three log searches across two systems.

| Rule | Reason |
|------|--------|
| Database spans carry the **statement shape**, never bound values | A bound value is customer data, and a trace is not an audit log |
| Attributes pass through the **same allowlist as logs** | A trace is a log with a nicer viewer, and it leaks the same way |
| Sampling **always keeps errors** and **always keeps money-moving operations** | Whatever the rate. A sampled-away payment release is the one you needed |
| Span names are low-cardinality | `POST /invoices/{id}/issue`, never the ulid |

## 2. Structured logs

JSON, with a correlation id, tenant id and entity id on every line.

> **Context is built from an allowlist, not a redaction blocklist.** A blocklist **fails open** the first time someone adds a field — and the field that gets added is an IBAN.

A test asserts that a payload containing an IBAN, a token and a password produces a log line containing none of them. Personal data is not logged at all; identifiers are.

## 3. Business metrics

A dashboard of CPU and p99 latency tells you the servers are fine. It does not tell you that one jurisdiction's clearance has been rejecting every document for an hour.

| Metric | Why it matters |
|--------|----------------|
| Invoices issued / failed / rejected, **per jurisdiction** | A profile breaking affects one country and looks like nothing in aggregate |
| Clearance latency percentiles, **per authority** | The authority's performance is a dependency, and its degradation is not our error rate |
| Unmatched bank lines, **by age** | The leading indicator of a reconciliation that is drifting |
| Suspense balance, by entity | Rising suspense means matching is failing, before anyone complains |
| In-transit aging | A payment that left and never appeared on a statement |
| Documents awaiting approval, by age | An approval chain with nobody in it |
| Payment runs prepared but not released | Work that stopped half way through the highest-risk flow |
| Dead-letter depth, per transport | |
| Failed scheduled runs, by task **and tenant** | Per-tenant scheduling means a per-tenant failure |
| Recurring occurrences missed | A customer who did not get billed |

Dashboards are **per audience** — operator, tenant controller, engineer — because they ask different questions and a single dashboard serves none of them.

## 4. Invariant alerting

The part that matters most in a financial system, and the part that is impossible to add cheaply later.

| Alert | Source | First response |
|-------|--------|----------------|
| **Read-model drift** | Nightly `ledger:rebuild-balances --check` | Do **not** auto-repair. Identify the posting that diverged — silent repair hides the bug that caused it |
| **Hash-chain divergence** | Nightly `invoices:verify-chain` | Freeze issuance on that series, identify the first divergent document, preserve evidence |
| **Subledger ≠ control account** | `ledger:verify` | Find the manual posting or the broken allocation; the diff names the account and period |
| **Unbalanced entry in the database** | `ledger:verify` | Should be impossible — the constraint trigger prevents it. Treat as a tampering or privilege incident |
| **Suspense above threshold** | Hourly | Matching is failing; check the latest import's format and the rule engine |
| **Privilege drift** | Every deploy | A manual grant made during an incident. Revert and record |
| **Archive checksum mismatch** | Weekly `archive:verify` | Potential storage integrity problem; restore from the manifest and compare |
| **Statement continuity gap** | On import | A missing statement, not a bug. Fetch the gap before reconciling |
| **Clearance rejection rate spike, per jurisdiction** | Rolling window | Usually a profile or a credential change at the authority |

Each alert **names the entity, the period and the first divergent record**, so it is actionable at 3am, and each has a runbook entry with a tested, non-destructive first response.

### Game day

Once per release, in staging, each condition above is **injected deliberately** and the alert must fire and the runbook must work. An alert that has never fired is a configuration someone hopes is right.

## Health and readiness

| Endpoint | Checks | Used by |
|----------|--------|---------|
| `/up` | The process is alive | Load balancer |
| `/health/ready` | Platform database, Redis, queue worker heartbeat | Orchestrator |
| `/health/deep` | A sample tenant connection, object storage, PDF service, Meilisearch | Monitoring, not the load balancer |

**`/health/deep` is deliberately not the load-balancer probe.** A deep check on the hot path means one degraded dependency takes every node out of rotation.

## Audit versus observability

Different systems, different retention, and conflating them is a compliance problem.

| | Audit log | Observability |
|---|-----------|---------------|
| Purpose | Evidence for an auditor | Diagnosis for an engineer |
| Store | The tenant's own database | The telemetry backend |
| Retention | Statutory, years | Weeks |
| Mutability | Append-only, hash-chained, `DELETE` revoked | Freely expired |
| Contains personal data | Yes, lawfully, by design | **No** |
| Sampled | **Never** | Yes |

An audit event is never only a log line, and a log line is never evidence.
