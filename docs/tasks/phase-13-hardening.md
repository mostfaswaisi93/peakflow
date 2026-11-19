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
- [x] Served from a separate origin with `Content-Disposition: attachment` and a short-lived signed URL, so a stored HTML or SVG cannot execute against the application's origin
  - All three, and the note that said otherwise was stale. `AttachmentStore::signedUrl()` mints a `temporarySignedRoute` whose TTL is `attachments.url_ttl_seconds` (300 by default, and the production guard refuses over an hour - a link ends up in a browser history and a chat log), then re-homes it onto `attachments.origin`; the signature covers the path and the query and not the host, which is what makes moving it possible. The route carries `signed`, so an edited path or expiry is a 403 before a tenant is resolved. An empty origin is refused in production, so "where one is configured" is not a deployment's choice to skip.
  - The response is what makes the file inert, and it is now checked rather than described: `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `default-src 'none'; sandbox` and `no-store`, each bitten separately. Three layers because each has been bypassed somewhere on its own - the allowlist is the first line, and these are what hold when something gets past it.
  - The hostile-name half moved where it belongs. The first draft of the check drove a name carrying a newline and could not run: the **uploader** refuses a control character, with its own sentence about a carriage return ending one header and starting another. So the download never sees one, and what it still has to handle is the quote, which is a permitted character. Both the balanced quoting and the RFC 5987 `filename*` are asserted - an Arabic name that arrived as underscores would make every attachment in an Arabic workspace download as a file nobody can tell apart.
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

- [x] A periodic access review: who holds which permission in which entity, who holds a money-moving permission, and their MFA status
  - `access:review {tenant}`, over `AccessReview`. One row per user per **entity**, not per permission: a review with a line per permission runs to thousands and is read by nobody, and the decision being made is "should this person have this role here" - the permissions are what makes that decision informed.
  - It exits non-zero on exactly one thing: a money-moving permission held by somebody with no confirmed second factor. Everything else it reports is a fact about an organisation rather than a fault in the system, and a scheduler paging somebody at 03:00 about a role that outlived its reason is a page nobody reads twice. That one row is different because `RoleGrantService` **refuses** to create it - so one that exists arrived by an import, a direct write, or before the rule did.
  - It found one immediately: the development fixture's own seeded owner, because `DevelopmentSeeder` writes `user_entity_roles` straight at the table. A review that only reported what the code permits would never have found it.
- [x] `compliance:report {tenant}` produces the evidence pack: access review, SoD overrides in force, period state changes and reopenings, chain verification results, close-checklist overrides, failed scheduled runs, privilege drift
  - JSON, because the next thing that happens to a pack is that somebody diffs it against last quarter's. A table printed to a terminal cannot be diffed and a PDF can be edited between the printing and the handing over.
  - **Overrides in force**, not overrides granted. An override granted in January and still live in March is what an auditor asking about March is looking for, and a list of grants made in March does not contain it.
  - **Two sections have no source and the pack says so in itself** rather than omitting them. *Failed scheduled runs* need a durable record of scheduled-run outcomes and there is no table for one - the failures raise `scheduled_job_failed` through the alert sink, so a section built from the log would be a section whose completeness depends on log retention, which is not evidence. *Close-checklist overrides* have no source because there is no override: `PeriodStateMachine` refuses a close whose checks have not passed and it cannot be waived, so the related act that IS recorded is a reopening, which is in the pack.
- [x] The pack is a reproducible artefact for a given date, because an auditor asks for a point in time
  - Two runs for the same date are byte-identical once `generated_at` is set aside, and the sha256 printed on the console covers exactly that - the evidence, not the timestamp. A reader has to know when the pack was produced, and hashing it would mean two packs for the same date never match, which is the opposite of what a digest is for.
  - **Each section states its own basis**, because the honest answer differs by section. Event-derived ones (`as_at`) are reconstructible for any past date. State-derived ones (`as_now`) are not: `user_entity_roles` keeps no history, so March cannot be rebuilt from it. A pack that mixed them silently would answer "who could release payments in March" with today's list - and the one person the question is about is the one who left in April.
  - The first version of the check asserted only that a pack dated before the first audit record was empty, and it **passed with the date filter removed**: this fixture holds no period transition, so an empty list excluded from an empty list proves nothing. It now records a real transition through the trail - not at the table, because the pack verifies the chain two sections later - and asserts today's pack carries it while yesterday's does not.
- [x] Dormant accounts and unused tokens surfaced for removal
  - Dormancy for a **user** is derived from the audit trail, not from a `last_login_at` column. A column has to be written by every path that signs somebody in, and the day one of them forgets, the review reports an active account as dormant - or worse, the other way. The trail already records `session.started` because it is evidence, and a review is made of evidence.
  - A **token** is different: `last_used_at` is written by the one middleware that authenticates it, so there is a single writer and nothing to drift. A token that has never been used at all is reported with a null rather than left out - an integration that was switched off and whose credential was not is the quietest standing grant there is.
  - The window is a date the caller passes, not a constant. Ninety days is the usual answer and it is not the service's to assume: a seasonal business has staff who sign in twice a year, and a review that called them dormant would be training its reader to ignore it.

### PF-239 · Data protection
`—` · **5 pts** · depends: PF-129 · standards: GDPR and local equivalents · `risk:high` `type:compliance`

- [ ] A documented lawful basis and retention period per data category
- [ ] A tenant can **export everything it owns and nothing else**, including attachments, as a machine-readable archive
- [ ] Erasure requests are **reconciled against statutory retention, which wins**, and the product says so in the UI rather than pretending to delete - a tax authority requiring seven years of invoice retention overrides a deletion request
- [ ] Personal data in non-production environments is pseudonymized by the process that creates them, not by a cleanup afterwards
- [ ] Data residency recorded per tenant, and a tenant's data provably does not leave its declared region

### PF-241 · Business metrics
`—` · **5 pts** · depends: PF-240 · standards: — · `risk:high`

Technical metrics are the floor. These are the ones that describe whether the product is working.

- [ ] Invoices issued, failed and rejected per jurisdiction; clearance latency percentiles per authority
  - **Issued is built and bitten** - by jurisdiction profile, document type and state. The other two facts **do not exist**: "failed" and "rejected" are clearance outcomes and latency is the interval between a submission and an authority's answer, and there is no transmission table, no submission timestamp and no authority response anywhere in the schema. The Exchange transport is PF-122 and PF-124.
  - A `rejected` series was deliberately **not** derived from a validation failure or an unissued draft. That would be a figure on a dashboard that does not mean what its name says, which is worse than an absent series - somebody would report it to a board.
- [x] Unmatched bank lines by age; suspense balance by entity; in-transit aging
  - Each one proved to **move** when the fact underneath it changes, through the real services - not asserted against a fixed figure. A reversed match counts as unmatched again, which was hollow first time: the check matched a line and watched the count fall, and never reversed one.
  - Suspense is read from `journal_lines` rather than from the parked rows, so it is the ledger's answer and not a second one.
- [ ] Dead-letter depth per transport; failed scheduled runs by task and tenant
  - **Half of it cannot be reached.** `queue_health{queue, state}` reports queue depth and `failed_jobs` depth, which is the dead letter of the only transport the product has - but "per transport" means the Exchange plane's own, where a clearance submission that exhausted its retries waits for a human because the document is already legally issued. That table does not exist.
  - "Failed scheduled runs by task and tenant" needs a durable record of scheduled-run outcomes, which needs a migration. One was deliberately **not** added: half the criterion is unreachable anyway, and a table added to half-close a criterion is a sketch. What is in place instead is the failure that matters most - the schedule's `onFailure` raises `watch_failed` through the sink, and a watcher that throws becomes the same alert rather than silence, both bitten.
  - A `scheduled_runs` table plus a `ScheduledTaskFailed` listener is small, well-defined, and the right next step here and for PF-238's evidence pack.
- [x] Documents awaiting approval by age; payment runs prepared but not released
  - Both proved to move in **both** directions, including the falling one: a released run leaves the prepared count. A figure that only ever goes up is a figure nobody checks.
- [x] Dashboards per audience - operator, tenant controller, engineer - because they ask different questions
  - Three derived dashboards, each non-empty, and a check asserting **no two of them are the same** - because the easy way to satisfy this criterion is three names over one list, and that passes any check that only counts.
  - A caveat worth stating: these are console dashboards plus a published metric stream, not screens. The data, the audience separation and the derivation are built and bitten; the rendering surface is Phase 11's.
  - A metric may not be labelled by a party, a document or a correlation id, and that is a check: a metric labelled by customer id is a customer list on whoever's dashboard.

### PF-242 · Invariant alerting
`—` · **5 pts** · depends: PF-241, PF-054 · standards: — · `risk:high`

The unusual part, and the part that cannot be retrofitted.

- [x] Alerts on: read-model drift detected by the nightly rebuild, a hash-chain divergence, a subledger no longer equal to its control account, a suspense balance crossing a threshold, an unbalanced entry reaching the database, a privilege drift, an archive checksum mismatch
  - All seven are registered watchers, and `invariants:watch --all` runs them hourly on the schedule. Measured off the registry rather than off this list: `read_model_drift`, `hash_chain_divergence`, `subledger_mismatch`, `suspense_above_threshold`, `unbalanced_entry_in_database`, `privilege_drift`, `archive_checksum_mismatch`.
  - Proved by breaking each invariant in turn and asserting its own alert fires and names the breach - `invariants:game-day`, run as a check. A registry that merely held seven keys would pass a check that only counted them.
- [x] Each alert names the entity, the period and the first divergent record, so it is actionable at 3am
  - `Alert`'s **constructor** refuses an empty entity, period or first divergent record, and the two conditions that genuinely have no period must say so with a named constant - a null period reads as "we did not look".
  - All three go in the **sentence** as well as the fields, because `LogContext::build()` replaces a caller's `entity_id` with the ambient one and drops it when there is none: an alert whose entity lived only in a field would reach the log without it.
  - It also refuses a monolingual alert **and two identical sentences**, because an Arabic field holding English reports as covered.
- [x] Each has a documented runbook entry and a tested, non-destructive first response
  - Checked three ways, and the third is what makes "non-destructive" mean something: **each distinct first response is executed and the database fingerprinted either side of it**, so a command that writes anything fails by name. `--repair`, `--force`, `--fix` and a bare `ledger:rebuild-balances` are refused in the document without being run.
  - Bitten by pointing a first response at the repair instead of the check, which is the mistake somebody writing a runbook in a hurry actually makes.
- [x] A game-day exercise injects each condition in staging and verifies the alert fires and the runbook works
  - `invariants:game-day {tenant}`: one transaction, a savepoint per condition, always rolled back, and it refuses any environment outside local/development/testing/staging with **no `--force`** - because even rolled back it takes locks on `journal_lines`.
  - The injectors are **production** code, not test code, because `invariants:game-day` and `bin/verify` both use them: one implementation, two runners. So the same eight injections run on every verify, which is the difference between a game day somebody remembers to hold and one that cannot be skipped.
  - "Not injectable" is reported and never counted as a pass. Run against the demo tenant it injected the privilege drift, the alert fired naming the planted grant, and it reported the other seven as not injectable with a reason each - the correct answer for a tenant with no books, and the one a game day must never dress up.

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

- [x] A written playbook: expand-contract in separate releases, no non-nullable column without a default, no blocking lock on a partitioned table, backfills batched and resumable
  - `docs/operations/migrations.md`. It exists because the inspector can only say **no**: it cannot say what to do instead at the moment somebody is deciding, which is a week earlier and in a design conversation.
  - The hazard names are read out of `MigrationSafetyInspector::HAZARDS` by a check rather than typed into it, so adding a hazard without writing its section fails. A document that drifted from the code it describes is worse than none - somebody reads it, writes the migration it blesses, and finds out at the pre-flight, having designed around a rule that does not exist or missed one that does.
  - It also carries the parts the inspector has no opinion about: why a rename is a deploy-coordination problem rather than a schema change, why a backfill belongs outside the migration (one that backfills holds its transaction for the length of it, and a deploy waiting on that is a deploy that times out halfway), and why `down()` in two of this product's migrations deliberately does **less** than `up()` did.
- [x] Every migration declares whether it is safe to run while serving traffic; the pre-flight check from `PF-023` enforces the declaration
  - The pre-flight was built and the DECLARATION was not. Measured: **64 of 137 migrations carried no marker at all**, and nothing said so, because an inspector only flags the hazards it knows - a migration it does not flag is *unexamined*, not safe, and from outside the two look the same.
  - All 137 now declare, with three markers and a reason on each: `@online-safe`, `@online-unsafe` and the existing `@unsafe-migration-reviewed`. A bare marker fails the check, because a marker with nothing after it says the same as none except that it silences the question.
  - Writing the declaration is what made the authors decide, and it turned up nine migrations that add a CHECK constraint to a table that already exists. `ADD CONSTRAINT` without `NOT VALID` scans every row under ACCESS EXCLUSIVE, so on a populated table it blocks reads and writes for a full scan. They are declared `@online-unsafe` rather than rewritten: the marker describes the **statement**, not the install where the table happened to be empty, and a deployment replaying one onto years of rows gets the scan. The pattern to copy is in `a_bill_can_be_cancelled` - NOT VALID, then VALIDATE CONSTRAINT, which takes only SHARE UPDATE EXCLUSIVE.
  - The inspector now flags that shape too. It had to be detected procedurally rather than by one more regex: the constraints arrive comma-separated inside a single `ALTER TABLE`, so a pattern asking whether `NOT VALID` appears before the semicolon is satisfied by a **sibling** constraint that has one. Each clause is cut at the next `ADD CONSTRAINT` and asked on its own.
  - The first version of it flagged every table-creating migration in the product. A constraint on a table the same migration just created is not a hazard - nothing can be reading a table that did not exist a moment ago - and `fallsInside()` only knows the `Schema::create` closure, while these constraints arrive in a raw `ALTER TABLE` after it. So the inspector now reads which table the enclosing `ALTER` is about and compares it against what the file creates.
  - `@online-unsafe` answers this hazard and **only** this one, by an explicit list. A declaration that excused everything would make one annotation silence the inspector for a whole file, and "this does not run against traffic" genuinely answers a hazard whose entire cost is blocking while it runs - it does not answer a rename or a dropped column, which break the running code afterwards and outlive any window.
- [ ] **Feature flags on anything that changes existing behaviour**, with a documented default and a removal task so flags do not accumulate
- [ ] A rollback procedure per release, tested in staging, including the case where the schema moved forward and the code must go back
- [x] A tenant mid-migration returns `503` with `Retry-After` rather than failing, and the operator console shows migration progress per tenant
  - The resolver refused a migrating tenant before this task and nothing proved it. Now driven through `TenantConnectionResolver::resolve()` with the status flipped inside the run: removing the branch makes it answer **402** with no `Retry-After` - "this workspace is not entitled to be served", which tells a client to stop trying rather than to come back.
  - The restore in the `finally` is the status and **not** a re-point. `resolve()` throws for a migrating tenant before it points anything, so the connection never moved; calling `point()` purges it, which rolled back the transaction the suite holds and took its probe entities with it. Every later check then failed on a foreign key to a row that had just vanished.
  - The console shows each tenant's `schema_version` beside its status, marked when it is behind the version the migration files declare. "migrating" on its own says a migration is happening, not whether it is progressing or which tenants it has reached - and `tenants:migrate --unstick` exists precisely because a tenant can sit in that status after the run that set it died.
