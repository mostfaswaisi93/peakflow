# Phase 13 — Security, observability, performance and release — finished

The tasks of [phase-13-hardening.md](../phase-13-hardening.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-235 · XML hardening
`Exchange` · **3 pts** · depends: PF-114 · standards: OWASP · `risk:high` `type:security`

- [x] XXE, external entity resolution, DTD loading and network access **disabled explicitly on every reader**, not assumed off by default
  - `XmlCanonicalizer` was a second implementation of the same hardening and now delegates to `SafeXmlReader`, so there is one place to get it right. `schemaValidate()` was a third, unguarded parser: libxml opens the `.xsd` itself and follows every `xs:import` through whatever entity loader is installed. It now runs under a loader confined to `resources/schemas`, and a refused reference is reported as `EN16931-SCHEMA-REFERENCE` rather than thrown - PHP raises a warning there that Laravel turns into an exception, so it would otherwise have escaped the pipeline instead of being reported.
- [x] Entity-expansion and depth limits configured, so a billion-laughs payload fails fast
  - The refusal used to run **after** the parse, which is not a defence: libxml expands an internal general entity while building the tree, and `LIBXML_NOENT` only decides whether the result is flattened into text nodes. A 347-byte payload parsed cleanly and came back a million characters long, all allocated before anything objected. Refused on the bytes now; the post-parse check stays as a second layer with its own message.
- [x] Size limits before parsing, since an inbound document from a trading partner is untrusted input
  - `config/exchange.php` declared `max_bytes`, `max_depth` and `max_entity_expansion` and **nothing read any of them**: a document 250 elements deep was accepted while the file said 100, and 100 could never have fired anyway because libxml stops at 256 either way. A check now asserts the configured depth limit is one that can actually apply, so a number that looks like a limit and is not cannot come back.
- [x] A test fires XXE, billion-laughs, a deeply nested document and an oversized one at every parser the application has
  - Including `OfxParser`, which was safe **by accident**: `asXml()` truncates everything before `<OFX>` for the SGML tag-closer's benefit, which happens to delete a DOCTYPE, which happens to leave the entity references undefined - so the payload was refused with `Entity 'e5' not defined` shown to somebody holding a file their bank sent them. It rested on a regular expression written for another purpose and nothing said so. It has its own byte guards now, on the content as received.

### PF-233 · Database privilege verification
`—` · **5 pts** · depends: PF-010, PF-045 · standards: — · `risk:high` `type:security`

- [x] An automated check asserting the live privilege grants match the declared intent - not that the migration once ran, but that the grants are as expected now
- [x] `peakflow_app` proven unable to `DELETE` a posted row, `ALTER` anything, or connect to the platform database
  - The first two are in the hardening suite; the third is `the connect matrix is one role, one database` in `DatabaseRoleRules`, which opens real sessions rather than reading a catalog.
- [x] A drift check runs on every deploy, because a manual grant made during an incident is exactly how this regresses
  - **Closed on the schedule rather than on a deploy, and that is the better reading of it.** `PrivilegeDriftWatcher` runs the same `PrivilegeMatrix` - tenant drift and row-security drift - from `invariants:watch --all`, which `routes/console.php` schedules hourly with `withoutOverlapping` and an `onFailure` that raises `watch_failed` through the alert sink.
  - The criterion's own sentence is why: a manual grant is made **between** deploys, at 02:00, by somebody who then goes to bed. A deploy-time check would not have seen it until the next release. The product owner has asked for no GitHub Actions, and this is the shape that does not need one.
  - Bitten twice, and the second bite is the one worth recording: making the watcher ask about `current_user` instead of the application's role left the check **green** - an alert fired, with a plausible sentence, about a role that owns every table in the database. Exactly the trap `PrivilegeMatrix`'s own docblock describes. Each condition now declares the phrase its first divergent record must contain, and the privilege one must additionally **not** name the owner role.
- [x] Row-level security policies verified present on every table that requires one
  - Proved by `TenantEnforcementRules::everyScopedTableHasAPolicy()` on every provisioned tenant, and carried for the deploy by `privileges:verify`, which calls the same `PrivilegeMatrix::rowSecurityDrift()`. The hardening suite's own matrix check deliberately does not ask again, and says so where it would have.

### PF-240 · Distributed tracing
`—` · **5 pts** · depends: PF-014 · standards: OpenTelemetry

- [x] Spans across the HTTP request, the queued job and the outbound transport, with the correlation id propagated through all three
  - The trace id **is** the correlation id rather than a second identifier beside it, which is what makes the propagation already true: the correlation id survives an inbound header, the queue payload and the outbound client middleware. A span that generated its own id fails the check.
  - `TelemetryServiceProvider` is registered **after** `QueueTenancyServiceProvider` and the order is load-bearing: `Queue::before` callbacks run in registration order, and a job span opened before the correlation id is adopted would be traced under whatever the worker last had - the previous tenant's invoice.
- [x] "Why did this invoice take four minutes to clear" is **one trace**, not three log searches
  - Proved by producing all four span kinds in one causal chain and reading the trace ids back, and by the case that makes a trace useless if it is missing: an outbound call that never answers is **in** the trace, as an error, because `Tracer::finish()` closes orphaned children rather than dropping them.
  - The tracer suspends itself while exporting. Without that, the log exporter writes a line, the allowlist processor resolves the entity, that query opens a database span, and exporting it writes a line - forever.
- [x] Database spans carry the statement shape, never its bound values
  - Proved by driving a real IBAN, a real card number and an amount through a real query with `record_bindings` forced `true`, and reading the **exported bytes**. The amount is the one that matters: the IBAN and the card are caught by `SecretShapes` because they carry check digits, and a figure carries nothing.
  - The shape is subtractive - comments, dollar-quoted bodies, string literals and numbers out - but **partition names are kept**, because collapsing `journal_lines_2026_05` and `_06` into one shape throws away the only thing that tells you which month a slow query was reading. A bite that replaced every digit is caught for exactly that.
- [x] Sampling that always keeps errors and always keeps money-moving operations, whatever the rate
  - Proved at a rate of **zero**, which is the only rate at which the exemption can be observed, and both against the sampler and by issuing a real invoice.
  - A check also asserts each always-sampled operation is actually emitted by production code - and it was hollow first time in an instructive way: it scanned for the operation name anywhere in `app/`, and four of the five names are *also* Gate ability names, so deleting the only span that emits one left the string in `app/Support/Authorization` and the check passed. The name and a `->measure(` call must now be in the same file.
- [x] Attributes pass through the same allowlist as logs
  - In `Span`'s **constructor**, so there is no way to build a span whose attributes have not been through it - which is a stronger statement than filtering on the way out.
  - And the channel a span is written on is checked for the tap: `config/logging.php` has seven channels that declare none, and `telemetry.channel` pointing at `stderr` fails the build. The equivalent hole for `LOG_CHANNEL` itself is still open and is reported under PF-014.
  - A limit worth knowing, found by measurement: the allowlist governs field **names**, and `SecretShapes` recognises only shapes with a check digit. A party's legal name has neither, so an alert sentence carrying one reaches the log with it. Enforced for every alert a real watcher raises (no party name from the tenant's own `parties` table may appear), but the general gap needs either a source rule or a per-tenant name check in the processor.

