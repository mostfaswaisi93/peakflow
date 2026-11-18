# Phase 13 — Security, observability, performance and release

**16 tasks · 77 points**

This phase is a **verification gate, not the first time security is considered**. Every preceding phase carries its own security acceptance criteria - least privilege in Phase 00, SoD in Phase 01, immutability in Phase 02, encryption in Phase 03, sealing in Phase 06, maker-checker in Phase 09. What happens here is that those controls are *proven*, the alerting that watches them is built, and the release machinery that keeps them true is put in place.

The distinguishing idea is **alerting on invariants**. A dashboard of CPU and p99 latency does not tell you that one jurisdiction's clearance has been rejecting every document for an hour, or that a subledger stopped equalling its control account last Tuesday. Those are the alerts that matter in a financial system, and they are impossible to retrofit cheaply.

---

### PF-231 · The ASVS verification pass
`—` · **8 pts** · depends: PF-230 · standards: OWASP ASVS 5.0 · `risk:high` `type:security`

- [ ] Every applicable ASVS requirement assessed, with the verdict, the evidence and the responsible control recorded
- [ ] Gaps become tasks with owners rather than notes in a document
- [ ] The result is recorded **in the release notes** rather than asserted once and forgotten
- [ ] Re-run per release, with a diff against the previous pass so a regression is visible
- [ ] The mapping lives in [`../standards/compliance-matrix.md`](../standards/compliance-matrix.md) as requirement → control → test → evidence

### PF-232 · Threat model and abuse cases
`—` · **5 pts** · depends: PF-231 · standards: OWASP · `type:security`

- [ ] A written threat model per trust boundary: browser to app, app to tenant database, app to platform database, app to Exchange, Exchange to partner, operator to tenant
- [ ] **Abuse cases, not only misuse cases**: beneficiary substitution, self-approval through a delegate, period reopening to hide a posting, credit note to erase a receivable, export as data exfiltration, impersonation without consent
- [ ] Each abuse case names the control that stops it and the test that proves the control
- [ ] A review checklist derived from the model, applied to any pull request touching money, auth or SoD

### PF-233 · Database privilege verification
`—` · **5 pts** · depends: PF-010, PF-045 · standards: — · `risk:high` `type:security`

- [x] An automated check asserting the live privilege grants match the declared intent - not that the migration once ran, but that the grants are as expected now
- [x] `peakflow_app` proven unable to `DELETE` a posted row, `ALTER` anything, or connect to the platform database
  - The first two are in the hardening suite; the third is `the connect matrix is one role, one database` in `DatabaseRoleRules`, which opens real sessions rather than reading a catalog.
- [ ] A drift check runs on every deploy, because a manual grant made during an incident is exactly how this regresses
  - **The check is built; nothing runs it on a deploy.** `privileges:verify [--tenant=] [--repair] [--platform-only]` exits non-zero on any drift and reports it per tenant, and the hardening suite runs the same matrix on every provisioned database. What is missing is the caller: there is no deploy pipeline in this repository yet (PF-240), so there is nowhere to put the one line. Deliberately left open rather than ticked with a note, because a check nobody invokes is exactly the failure mode this criterion was written against.
- [x] Row-level security policies verified present on every table that requires one
  - Proved by `TenantEnforcementRules::everyScopedTableHasAPolicy()` on every provisioned tenant, and carried for the deploy by `privileges:verify`, which calls the same `PrivilegeMatrix::rowSecurityDrift()`. The hardening suite's own matrix check deliberately does not ask again, and says so where it would have.

### PF-234 · Secret management
`—` · **5 pts** · depends: PF-124, PF-061 · standards: OWASP ASVS 5.0 · `risk:high` `type:security`

- [ ] Signing keys and transport credentials in a KMS; the application holds a **reference, not a key**
- [ ] Nothing secret is readable from config at runtime by the `Exchange` plane
- [ ] Key rotation procedures for each key type, exercised rather than documented
- [ ] A log audit asserting no secret, token, IBAN or personal datum reaches a log line, an error report or a trace attribute - tested with a payload containing all of them
- [ ] Secret scanning on the repository, with a documented response procedure for an exposure

### PF-236 · Upload hardening
`—` · **3 pts** · depends: PF-073 · standards: OWASP · `type:security`

- [x] Content type **sniffed, not trusted**; an extension allowlist; a size cap; a malware scan before the file is readable
  - The allowlist admitted the empty string. A file with no extension cleared the sniffer (plain text is on the list) and the mismatch check returned null because there was nothing to compare, so it was stored and served under whatever name the uploader chose - the one case `nosniff` cannot help with, because there is nothing to sniff against.
- [ ] Served from a separate origin with `Content-Disposition: attachment` and a short-lived signed URL, so a stored HTML or SVG cannot execute against the application's origin
  - **Two of three.** `AttachmentDownloadController` sends `Content-Disposition: attachment` with the name quoted and escaped both ways, and `SecurityHeaders` sends `default-src 'none'; sandbox` on that route, so a stored HTML or SVG has no origin to execute against even when it is opened. Both are checked. The **separate origin** and the **short-lived signed URL** are not built: the route is an ordinary authenticated one, so a download link pasted into a chat is useless to whoever receives it, which is the right default but not what this criterion asks. A second origin needs a host and a certificate, which is deployment and not code.
- [x] Filenames normalized; path traversal and unicode-confusable names rejected
  - The rules were four characters wide - only `/`, `\\`, NUL, `.` and `..`. A right-to-left override, zero-width characters, a Cyrillic homoglyph, a trailing dot, an MS-DOS device name and a 10,000-byte name all passed. The hashed storage path means none of them reaches a filesystem, so this is not a traversal bug; the name is what an approver reads before clicking and what their browser writes to disk, and `document.pdf` spelled with a Cyrillic `о` beside the real one is a decision made on a lie. Arabic names are explicitly **not** confusable and a check asserts it, because a mixed-script rule written carelessly refuses half this product's customers.
- [x] Office and PDF documents never rendered server-side in a way that resolves external references
  - Nothing renders one, and the check is a source scan rather than a behavioural one: adding `new \Imagick` to the store is caught. A behavioural check would have to install the renderer in order to prove it is not used.

### PF-237 · HTTP security headers
`—` · **3 pts** · depends: PF-204 · standards: OWASP, W3C · `type:security`

- [ ] **CSP with per-request nonces**, no `unsafe-inline` and no `unsafe-eval`; a report-only phase first, then enforcing
  - **Three of four, and the fourth is honest about itself.** Per-request nonces are built and bound `scoped`, so the value in the header is the value on the tag; a `@cspNonce` directive is the only spelling, so a template cannot acquire a second one. `unsafe-eval` is gone. `CSP_ENFORCE` switches between the enforcing and report-only header names with one policy behind both, and a check asserts they do not drift apart - a report-only phase that reports a weaker policy than the one it is rehearsing teaches nothing.
  - What remains is `style-src-attr 'unsafe-inline'`. Six computed `style` attributes - a column width in `components/grid.blade.php`, a progress bar's share in `app/home.blade.php` - and an attribute cannot carry a nonce. `script-src-attr` stays `'none'`, so `onclick=` and every other event-handler attribute is still refused, and a check asserts `style-src-attr` is the **only** directive in the whole policy carrying the keyword, so the exception cannot quietly spread. Closing it means a nonce'd `<style>` block with a generated class per value, from a reusable grid component: a real refactor of two templates, not a header change.
- [x] `Strict-Transport-Security` with preload, `Permissions-Policy` denying what the application does not use, `Referrer-Policy`, `X-Content-Type-Options`, and COOP/COEP
  - HSTS is two years with subdomains and preload, because the preload list refuses a max-age under a year and ignores a header without `includeSubDomains` - a shorter value produces a header that works in every browser and is rejected by the list, which nobody notices. It is not sent over plain HTTP, and a check asserts that: a browser ignores it there, so sending it only teaches whoever is reading the development traffic what we think we are doing.
- [x] Cookies `Secure`, `HttpOnly` and `SameSite=Lax`, with session cookies host-prefixed
  - `SESSION_SAME_SITE` **unset** was accepted in production: the guard refused only the literal `none`, and unset sends no attribute at all, which leaves the answer to the visitor's browser version. Now `lax` or `strict`. The `__Host-` prefix is derived in `config/session.php` rather than written there, because the prefix is a promise about the cookie's own attributes - no `Domain`, path `/`, `Secure` - and a name that says `__Host-` beside attributes that contradict it is simply ignored by the browser, silently.
- [x] A test asserts the headers on a real response, because a header set in a config file nobody loads is not a header
  - The middleware is global and **prepended**, not in the web group: the responses that most need these headers never reach a route - a 404 from an unmatched URL, a 500 from the exception handler, and `/workspace-unavailable`, which exists precisely because the tenant could not be resolved.

### PF-238 · Access review and the evidence pack
`—` · **5 pts** · depends: PF-034, PF-035 · standards: SOC 2, ISO/IEC 27001 Annex A · `type:security` `type:compliance`

- [ ] A periodic access review: who holds which permission in which entity, who holds a money-moving permission, and their MFA status
- [ ] `compliance:report {tenant}` produces the evidence pack: access review, SoD overrides in force, period state changes and reopenings, chain verification results, close-checklist overrides, failed scheduled runs, privilege drift
- [ ] The pack is a reproducible artefact for a given date, because an auditor asks for a point in time
- [ ] Dormant accounts and unused tokens surfaced for removal

### PF-239 · Data protection
`—` · **5 pts** · depends: PF-129 · standards: GDPR and local equivalents · `risk:high` `type:compliance`

- [ ] A documented lawful basis and retention period per data category
- [ ] A tenant can **export everything it owns and nothing else**, including attachments, as a machine-readable archive
- [ ] Erasure requests are **reconciled against statutory retention, which wins**, and the product says so in the UI rather than pretending to delete - a tax authority requiring seven years of invoice retention overrides a deletion request
- [ ] Personal data in non-production environments is pseudonymized by the process that creates them, not by a cleanup afterwards
- [ ] Data residency recorded per tenant, and a tenant's data provably does not leave its declared region

### PF-240 · Distributed tracing
`—` · **5 pts** · depends: PF-014 · standards: OpenTelemetry

- [ ] Spans across the HTTP request, the queued job and the outbound transport, with the correlation id propagated through all three
- [ ] "Why did this invoice take four minutes to clear" is **one trace**, not three log searches
- [ ] Database spans carry the statement shape, never its bound values
- [ ] Sampling that always keeps errors and always keeps money-moving operations, whatever the rate
- [ ] Attributes pass through the same allowlist as logs

### PF-241 · Business metrics
`—` · **5 pts** · depends: PF-240 · standards: — · `risk:high`

Technical metrics are the floor. These are the ones that describe whether the product is working.

- [ ] Invoices issued, failed and rejected per jurisdiction; clearance latency percentiles per authority
- [ ] Unmatched bank lines by age; suspense balance by entity; in-transit aging
- [ ] Dead-letter depth per transport; failed scheduled runs by task and tenant
- [ ] Documents awaiting approval by age; payment runs prepared but not released
- [ ] Dashboards per audience - operator, tenant controller, engineer - because they ask different questions

### PF-242 · Invariant alerting
`—` · **5 pts** · depends: PF-241, PF-054 · standards: — · `risk:high`

The unusual part, and the part that cannot be retrofitted.

- [ ] Alerts on: read-model drift detected by the nightly rebuild, a hash-chain divergence, a subledger no longer equal to its control account, a suspense balance crossing a threshold, an unbalanced entry reaching the database, a privilege drift, an archive checksum mismatch
- [ ] Each alert names the entity, the period and the first divergent record, so it is actionable at 3am
- [ ] Each has a documented runbook entry and a tested, non-destructive first response
- [ ] A game-day exercise injects each condition in staging and verifies the alert fires and the runbook works

### PF-243 · Performance
`—` · **5 pts** · depends: PF-058, PF-205 · standards: Core Web Vitals

- [ ] **Query budgets per page**, asserted: a page costs the same number of queries at 5 rows as at 500. An N+1 is invisible to every other kind of test
- [ ] `EXPLAIN` assertions on the critical queries - trial balance, aging, GL detail, the grid - proving index and partition use rather than hoping for it
- [ ] A seeded 10-million-line dataset as the performance fixture, with time budgets on the statements
- [ ] A k6 profile for realistic concurrency: month-end close with twenty accountants in one tenant
- [ ] Core Web Vitals budgets on the interactive screens, enforced in the pipeline

### PF-244 · Backup and disaster recovery
`—` · **5 pts** · depends: PF-019 · standards: — · `risk:high`

- [ ] **Per-tenant point-in-time recovery** via WAL archiving - which is the requirement that chose database-per-tenant over row-level security, so it has to actually work
- [ ] Stated RPO and RTO per plan tier, and a measured actual rather than an aspiration
- [ ] `tenants:dump` for a logical per-tenant export, used for both data requests and migrations
- [ ] **A scheduled restore drill** that restores a tenant to a point in time into an isolated environment, runs `ledger:verify` against it, and reports - a backup nobody has restored is not a backup
- [ ] "Show me this company's books as they stood on 31 December" is answerable in minutes, and the procedure is documented and timed

### PF-245 · Release engineering
`—` · **5 pts** · depends: PF-013 · standards: SemVer 2.0, Conventional Commits, Keep a Changelog, CycloneDX

- [ ] Semantic versioning with a written definition of what constitutes a breaking change for this product
- [ ] Conventional Commits enforced; the changelog generated and then edited for humans, because a generated changelog is a list of commits and not a description of a release
- [ ] A **CycloneDX SBOM published per release**, with `composer audit`, Dependabot and Trivy results attached
- [ ] Release notes record the ASVS pass result, the restore-drill result and any schema change requiring operator action
- [ ] Tagged, reproducible builds; an artefact's provenance is verifiable

### PF-246 · Zero-downtime migration and rollback
`—` · **5 pts** · depends: PF-023, PF-245 · standards: — · `risk:high`

- [ ] A written playbook: expand-contract in separate releases, no non-nullable column without a default, no blocking lock on a partitioned table, backfills batched and resumable
- [ ] Every migration declares whether it is safe to run while serving traffic; the pre-flight check from `PF-023` enforces the declaration
- [ ] **Feature flags on anything that changes existing behaviour**, with a documented default and a removal task so flags do not accumulate
- [ ] A rollback procedure per release, tested in staging, including the case where the schema moved forward and the code must go back
- [ ] A tenant mid-migration returns `503` with `Retry-After` rather than failing, and the operator console shows migration progress per tenant
