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

## What a log line may and may not contain

### An allowlist, because a blocklist fails open

A blocklist of fields to redact fails open the first time somebody adds a field, and the field that gets added is an IBAN. It fails open silently, in production, on a code path that looked like a debugging convenience — and the log is the one place nobody can delete from.

An allowlist fails the other way: a new field is absent until somebody adds it deliberately, which is a missing diagnostic rather than a leaked account number.

| on the list | not on it |
|-------------|-----------|
| `correlation_id`, `tenant_id`, `entity_id` | `email`, `name`, `phone`, `address` |
| `route`, `method`, `status`, `duration_ms` | `iban`, `token`, `password`, `api_key` |
| `document_type`, `document_number`, `account_code` | anything nobody has thought about yet |
| `findings`, `kinds`, `checked`, `run_id` | |

**Below the top level a key survives when it is allowlisted *or* its value is not a string.** `['kinds' => ['missing' => 2, 'wrong' => 1]]` is a map of classification to count, and filtering its keys against a field allowlist is a category error — the first version dropped the whole map and the drift alert said "3 findings" with no breakdown. A count survives as data; free text survives only under a name somebody allowlisted, so `findings[0]['iban' => '…']` still goes.

### And a second layer, for values

A field allowlist cannot know what a caller put in a legitimate field. Measured: an IBAN passed under the allowlisted name `reference` survived every field check and reached the line.

So values are also checked by **shape** — and this is not the blocklist that was rejected, because it inspects values rather than names, so a new field cannot bypass it:

| scrubbed | left alone |
|----------|-----------|
| `SA0380000000608010167519` → `[IBAN …7519 removed]` | `INV-2026-000471` |
| `4111 1111 1111 1111` → `[card …1111 removed]` | `1.1.2.1` |
| | `01M7FW…` (a ULID) |
| | `SA0480000000608010167519` (bad check digit) |

Only shapes with a **check digit** are scrubbed — IBAN by mod-97, card numbers by Luhn — so a false positive needs a string that already looks exactly like the thing *and* accidentally satisfies its checksum. A heuristic for "looks like a token" was considered and left out: it would eat account codes, document numbers and correlation ids, and a scrubber that eats the diagnostics is one somebody turns off.

The marker keeps the last four digits, because `[REDACTED]` is a line nobody can tie to a record, and the last four is what every bank statement and support call already uses.

**The message is scrubbed too.** `could not validate SA0380000000608010167519` is an ordinary exception message, and the exception message *is* the log line. Filtering the context while passing the message through is redacting the half nobody puts secrets in.

### The ids cannot be forged by the caller

`tenant_id` and `entity_id` are stripped from whatever a caller passed and replaced with the ambient values. Letting a caller pass its own would let it pass the wrong one, and a line attributed to the wrong customer is worse than one attributed to none.

The entity is read from the **database session variable**, not the container: that is the value row-level security is actually using, and a line saying which entity a query was scoped to has to agree with the scope the query ran under.

### One correlation id, through everything

An invoice is issued. That posts a journal entry, queues a PDF render, pushes a webhook and calls a tax authority — five log streams, four processes. The question afterwards is "what happened to invoice 4471", which is unanswerable unless every line carries the same id.

- **In**: an inbound `X-Correlation-Id` is honoured, so the caller's own logs join ours.
- **Through a queue**: the id travels on the job payload and is *adopted*, not regenerated. A job generating its own would be correct and useless.
- **Out**: a global HTTP middleware adds it to every outbound request, so a disputed e-invoicing clearance can be looked up on both sides by the same value rather than by a timestamp and a hope.
- **Back**: on the response, including the error response — the error page is exactly when somebody needs it.

It is **sanitised and bounded**: the header is attacker-controlled and lands in log lines, so a newline in it forges a log entry. A JSON formatter does *not* stop that on its own, because the line is valid JSON holding a string that a line-based collector then splits.

### The check asserts what is attached, not what is declared

The first version of the tap named a Monolog constant that does not exist in this version. Laravel catches errors while resolving a log channel — reasonably, because a broken logger must not take the application with it — so the tap never ran, the formatter stayed line-based, and every secret went to the log.

It was configured. It looked configured. The probe that found it reported five leaks and all five were real.

So `bin/verify logging` resolves every channel that declares the tap and asserts the processor is **on the logger** and the formatter **is** a `JsonFormatter`. Breaking the constant again fails it, by name, on both channels.

