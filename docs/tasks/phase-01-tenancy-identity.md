# Phase 01 — Tenancy, entities, identity and segregation of duties

**19 tasks · 84 points**

Two structures are built here and both are load-bearing for everything after. **Tenancy** is physical isolation: a database per customer. **Entity** is legal isolation inside a customer: a company that files its own return. Confusing the two, or adding either one late, is a migration across every financial table in the system.

The identity work in this phase is not generic authentication. It is the part of authentication that finance needs and most applications do not: mandatory second factors bound to specific permissions, re-authentication at the moment of an act, and segregation of duties evaluated against the audit trail rather than against a role list.

---

### PF-018 · Platform schema
`Platform` · **3 pts** · depends: PF-010 · standards: —

- [x] `operators`, `tenants`, `plans`, `subscriptions`, `database_connections`, `platform_settings`, `activity_logs`
- [x] ULID primary keys throughout
- [x] `database_connections.password` encrypted at the column level with the application key, and excluded from every model's serialization
- [x] `activity_logs` indexed on `created_at` and on `(operator_id, created_at)` - a composite leading with the filter and trailing with the sort, because PostgreSQL uses one index per table reference and a lone `operator_id` index lets it filter or sort, never both

### PF-019 · Tenant provisioning, create mode
`Platform` · **8 pts** · depends: PF-018 · standards: — · `risk:high`

- [x] `tenants:provision` issues `CREATE DATABASE`, `CREATE ROLE`, `GRANT`, runs the tenant migrations and seeds the baseline
- [x] Each tenant gets its own database **role** with a generated password, not a shared one
- [x] The whole provision is idempotent and transactional at the step level: a failure half way leaves no orphan database and no half-registered tenant
- [x] A rollback path drops what it created, and is tested by injecting a failure at each step
- [x] Provisioning runs as `peakflow_migrator`, never as the application role

### PF-020 · Tenant provisioning, link mode
`Platform` · **3 pts** · depends: PF-019 · standards: —

For managed hosting where `CREATE DATABASE` is not available.

- [x] A pre-created database and credentials can be attached to a new tenant
- [x] The connection is verified, the schema version checked, and migrations applied before the tenant is marked usable
- [x] The mode is recorded on the tenant, because the backup and restore procedure differs between them

### PF-021 · Connection resolution and middleware ordering
`Platform` · **5 pts** · depends: PF-019 · standards: — · `risk:high`

- [x] `TenantConnectionResolver` rewrites `database.connections.tenant` and purges the connection
- [x] `tenant.session` resolves from the session; `tenant.header` resolves from `X-Tenant`
- [x] Both are pinned **ahead of** the auth middleware via `prependToPriorityList`, and a test asserts the resolved order - because the user table lives in the tenant database and a guard that ran first would query the wrong one
- [x] The resolved tenant is bound into the container as `currentTenant`
- [x] A subscription that became invalid between page loads tears down the session and redirects to a dedicated page rather than failing the next query
- [x] A tenant mid-migration returns `503` with `Retry-After`

### PF-022 · Subscription lifecycle and plan gating
`Platform` · **3 pts** · depends: PF-021 · standards: —

- [x] States: `trial`, `active`, `past_due`, `suspended`, `expired`, `cancelled`
  - **Amended as built.** `migrating` is not a subscription state - it is a *tenant* state, and it lives on `TenantStatus`. Conflating them would mean a tenant mid-migration had to have its billing touched, and a restore would have to guess which of the two concerns a `migrating` row described. A tenant is either servable or not; a subscription either entitles access or does not. The resolver checks both independently and `cancelled` takes the slot, because a subscription that was deliberately ended is a different fact from one that lapsed.
- [x] A subscription is valid **through** its end date, so the expiry comparison is strictly `<`; the boundary has its own test
- [x] `subscriptions:expire` is idempotent and writes one activity log row per transition
- [x] A `plan` middleware gates features by plan, with a clear upgrade message rather than a 403

### PF-023 · Tenant migrations at scale
`Platform` · **5 pts** · depends: PF-019 · standards: —

- [x] `tenants:migrate {tenant}` and `--all`, with batching, concurrency limits and per-tenant failure isolation
- [x] A dry-run mode reporting what would change per tenant
- [x] A pre-flight check refusing a migration that is not safe to run while the application serves traffic - adding a non-nullable column without a default, rewriting a large table, taking an `ACCESS EXCLUSIVE` lock on a partitioned table
- [x] A schema-version row per tenant, so a request can detect a tenant that is behind and respond rather than fail
- [x] A documented note that the plain `migrate` command applies only the platform schema

### PF-024 · Tenant identity schema
`Identity` · **3 pts** · depends: PF-023 · standards: —

- [x] `users`, `roles`, `permissions`, `role_permissions`, `user_entity_roles`, `sessions`, `authenticators`, `audit_log`
- [x] A user's roles are held **per entity**, not globally - a group accountant holds roles in four companies, a subsidiary clerk in one
- [x] Soft deletes on `users`, so a document created three years ago still resolves its author

### PF-025 · The entity model
`Tenancy` · **5 pts** · depends: PF-024 · standards: IAS 21 · `risk:high`

- [x] `entities` with legal name, registration numbers, country, **functional currency**, fiscal calendar reference and presentation-currency default
- [x] One or more `tax_registrations` per entity, each with a jurisdiction, a scheme and an identifier
- [x] The functional currency is immutable once the entity has posted anything - changing it is an IAS 21 re-presentation, not an edit, and the UI explains that
- [x] Documented: what an entity owns exclusively (chart, currency, calendar, registrations, numbering, postings) and what is shared across the tenant (users, parties, items, currencies, rates, documents)

### PF-026 · Entity scoping and row-level security
`Tenancy` · **8 pts** · depends: PF-025 · standards: — · `risk:high` `type:security`

Two independent mechanisms, because one of them will eventually have a hole.

- [x] `entity.scope` middleware binds `currentEntity` and refuses an entity the user holds no role in
- [x] A global Eloquent scope applies `entity_id` to every financial model
- [x] PostgreSQL **row-level security** policies on every financial table inside the tenant database, keyed on a session variable set per request
- [x] A test proves that bypassing the Eloquent scope - raw query, console command - still returns nothing for a foreign entity
- [x] The architecture test from `PF-005` requiring `entity_id` is extended to require an RLS policy for each such table

### PF-027 · Entity switching
`Identity` · **3 pts** · depends: PF-026 · standards: WCAG 2.2 AA · `type:a11y`

- [x] An entity switcher in the shell, listing only entities the user holds a role in
- [x] Switching re-evaluates permissions and re-scopes the session; the scope is re-checked per request rather than trusted from the cookie
- [x] The active entity is visible on every screen and on every print output - a user posting into the wrong company is the failure this prevents
- [x] Keyboard operable, announced on change through a live region

### PF-028 · The permission catalogue
`Identity` · **3 pts** · depends: PF-024 · standards: —

- [x] Verb-shaped permissions, grouped by module: `invoice.draft`, `invoice.issue`, `credit_note.issue`, `bill.create`, `bill.approve`, `payment.prepare`, `payment.release`, `journal.post`, `journal.reverse`, `period.close`, `period.reopen`, `bank.import`, `bank.reconcile`, `reconciliation.write_off`, `supplier.bank_details.update`, `report.view`, `tax_return.file`, `settings.manage`, `user.manage`
- [x] Each carries a plain-language bilingual description, because a role screen listing keys is unusable by the person who should be configuring it
- [x] A test asserts every route that writes checks a permission - `auth` only proves someone is signed in, and a hidden navigation item is not access control
- [x] Seeded roles: `owner`, `controller`, `accountant`, `ap_clerk`, `ar_clerk`, `auditor` (read-only), each a defensible real-world bundle

### PF-029 · Session and device hardening
`Identity` · **3 pts** · depends: PF-024 · standards: OWASP ASVS 5.0 · `type:security`

- [ ] Fortify for the session guard; separate guards for the operator and tenant planes
  - The two guards are done: `web` authenticates a customer's user from the tenant database, `ops` authenticates an operator from the platform database. Fortify itself is not installed (same registry limitation as above), so the scaffolding it would provide is still to write by hand.
- [x] Session fixation protection, idle and absolute timeouts, concurrent-session visibility and remote revocation
  - Fixation, idle and absolute timeouts are enforced. The absolute timeout is NOT refreshed by activity - without that, a session kept warm by a polling dashboard never expires, and a session that never expires is a credential in a cookie with no rotation policy. Listing and revoking a user's other sessions needs the account screen, which arrives with the shell (PF-204); the `sessions` table already carries what it needs.
- [x] Sessions bound to a device fingerprint; a binding change forces re-authentication
- [x] Login throttling per account and per IP, with a response that does not reveal whether the account exists

### PF-030 · Passkeys and TOTP
`Identity` · **5 pts** · depends: PF-029 · standards: WebAuthn Level 3, RFC 6238 · `type:security`

- [ ] WebAuthn passkey enrolment and authentication, multiple credentials per user, with a readable credential manager
  - **Blocked, not skipped.** The schema is in place (`authenticators.credential_id`, `public_key`, `sign_count`, which already carries the monotonic-counter invariant both factor types share). Attestation parsing and COSE key handling are not things to hand-write in the authentication path, and the library cannot be installed in this environment - the build reaches `api.github.com`, which the network policy refuses. See `docs/architecture/authentication.md`.
- [x] TOTP as the fallback, with recovery codes issued once and stored hashed
- [x] SMS is deliberately **not** offered, and the reason is documented
- [x] Enrolment and removal are audited; removing the last factor from a user holding a money-moving permission is refused

### PF-031 · Binding MFA to money-moving permissions
`Identity` · **3 pts** · depends: PF-030, PF-028 · standards: OWASP ASVS 5.0 · `risk:high` `type:security`

- [x] A permission can be marked as requiring an enrolled second factor
- [x] Granting such a permission to a user with no factor is refused at the point of granting, with a message explaining what to do - not at the point of use, where it blocks real work
- [x] `payment.release`, `journal.post`, `period.close`, `period.reopen`, `supplier.bank_details.update`, `reconciliation.write_off` and `tax_return.file` are marked
- [x] A report lists users holding marked permissions and their factor status

### PF-032 · Step-up re-authentication
`Identity` · **3 pts** · depends: PF-030 · standards: OWASP ASVS 5.0 · `type:security`

- [x] A `step-up` middleware requiring a fresh factor assertion within a short window, independent of session age
- [x] Applied to **reopening a closed period** - a stolen session is not enough for it. Asserted inside `PeriodStateMachine::transition()` rather than on a route, because the same method is reached from a console command, a queued job and a future API endpoint, each of which is a route that does not exist yet; a middleware protects only the one that does
  - Closing a period needs no factor and the check asserts that too: closing stops postings, which is the safe direction, and demanding a factor for it trains people to produce one on request
  - The assertion is **object-scoped**: one recorded to reopen March does not authorise reopening December in the same five minutes. That is the half that gets dropped, because an unscoped assertion makes every test pass - so the check records one for March and asserts December is still refused
  - Reached through a new `Support` contract, `RequiresStepUp`, because the acts that need step-up belong to the modules that own them and none of them may depend on `Identity`
  - Releasing a payment run is PF-09x and lands with the payment run itself, at the same call site inside the service
- [x] The step-up event is audited with what it authorized

### PF-033 · The segregation-of-duties engine
`Identity` · **8 pts** · depends: PF-028, PF-035 · standards: ISO/IEC 27001 Annex A, SOC 2 · `risk:high` `type:security`

A permission answers *may this user do X*. An SoD rule answers *may the same user do X and then Y to the same object*. Conflating them is the classic hole: a clerk who may both create a supplier and release a payment can pay themselves.

- [x] Incompatible duty pairs declared as data, evaluated at the moment of the second act against the audit trail of the first
- [x] The shipped pairs: supplier create or bank-detail change against payment release; bill create against bill approve; payment prepare against payment release; journal post against period close; credit note against the invoice's own issuer; reconciliation match against write-off
- [x] A conflict is **refused, logged and explained** - the message names the conflicting act, who performed it and when, so the user knows who to ask
- [x] Scope is per object where that is the meaningful unit, and per period where it is not
- [x] Tests cover both directions of every pair and the case where the two acts are performed by different users

### PF-034 · SoD overrides and the exceptions report
`Identity` · **3 pts** · depends: PF-033 · standards: SOC 2 · `type:security`

A tenant with three staff may genuinely be unable to separate two duties. Silently allowing it and silently blocking it are both wrong.

- [x] A documented override requires a reason, a specified approver and an expiry
- [x] The override is itself an audit event and appears on a standing exceptions report
- [x] Overrides cannot be self-approved, and cannot be granted for the beneficiary-change-against-payment-release pair at all
- [ ] The report is part of the `compliance:report` evidence pack
  - The command itself is PF-13x (`compliance:report {tenant}`), which assembles the whole pack - access review, overrides in force, period changes, chain verification, privilege drift. `sod:exceptions` already produces this part of it and will be called from there; building a second, partial `compliance:report` here would mean one of them is wrong the day the real one lands.

### PF-035 · The audit log
`Identity` · **5 pts** · depends: PF-024, PF-009 · standards: SOC 2, ISO/IEC 27001 Annex A · `risk:high` `type:security`

- [x] Append-only, hash-chained, declaratively partitioned by month
- [x] `DELETE` and `UPDATE` revoked at the role level, not merely unused
- [x] Captures actor, entity, action, object, before and after where applicable, correlation id, IP and user agent
- [x] **Reads of sensitive data are audited too** - who looked at a bank account is an auditable event, not only who changed it. `BankDetailDisclosure` records the read under the `read` category with the actor, the party, which field and **why**, and an architecture rule confines the raw `revealIban()` accessor to that one caller - so the payment-file builder arriving in PF-08x cannot read a supplier's account without a trace, which is the natural one-line thing to do otherwise
  - The fraud this guards against needs **no write**: somebody who can read a supplier's IBAN can use it anywhere, and the change log would show nothing because nothing changed. "Who had this number" is answerable only if every disclosure was recorded, and a log of writes alone answers it with silence - which reads as "nobody"
  - The number itself is **not** in the entry, asserted across every column of it rather than the ones it was expected in. A log that copies the secret it is protecting has doubled the places it can leak from, in the one place nobody can delete from. The last four digits are there, so a reader can tell which account was disclosed
  - A read with no reason of at least ten characters is refused, and there is deliberately no weaker "may view" permission: it would be handed out widely for convenience, which is precisely how the number escapes
  - The reads are readable from the party screen. A read log nobody can see deters nobody, and deterrence is most of what it is for
- [x] Indexed on `created_at`, `(category, created_at)` and `(action, created_at)`, for the same one-index-per-reference reason as the platform log
- [x] A verification command re-walks the chain and reports the first divergence

### PF-036 · The operator console
`Platform` · **5 pts** · depends: PF-022 · standards: — · `type:security`

- [x] Tenant lifecycle: **provision, suspend, resume and expire** are in the console, each requiring a written reason of at least eight characters and a confirmation that states the consequence rather than asking "are you sure?" - a dialog people click through without reading, because it carries no information: it asks a question whose answer is already yes
  - **Provision** is on screen as well as in `tenants:provision`. The command needs a shell on the production box, which makes onboarding a customer something one person can do from a machine nobody audits with no row anywhere saying it happened. Synchronous, and that is measured: a full provision - role, database, 131 tables of migrations, baseline seed - takes **1.00 s** locally. If it ever takes long enough to matter the fix is a queued job and a `provisioning` status, not a longer timeout. A failure is not half a tenant: the provisioner is a reversible sequence, which PF-024's rollback checks prove by injecting a failure at each step
  - **Expire** is the deliberate single act, distinct from the nightly `subscriptions:expire` sweep. It is **commercial** where suspension is **operational**, and conflating them is how a payment dispute ends with a workspace suspended for "non-payment" and nobody able to say whether the contract actually ended. It sets `ends_on` as well as the status, or the sweep sees a row with no end date and leaves it alone - and "when did this contract end" has no answer in the data that answers every other such question
  - **Nothing is deleted** by expiry, asserted by checking the tenant's database still exists afterwards. Export and delete are deliberately **not** built: deleting a tenant is irreversible and deserves the two-person flow the SoD engine already provides. A check asserts no delete control and no DELETE route exists, so the deferral is a decision the codebase holds rather than a note here - the quiet addition of a delete button is exactly what it prevents
  - The identifier field is `dir="ltr"`, because it becomes a database name and a role name on the cluster: an identifier rendered right-to-left reorders around its hyphens, so what the operator reads back is not what will be created
  - Each confirmation is asserted to be a translated key present in **both** catalogs and at least 60 characters, so an operator working in English does not get Arabic on the dialog that is supposed to make them stop and read
- [x] Found on the way: the catalog rule scanned `app/` only - where the fewest user-facing strings are. Extending it to the templates found **19** missing keys, five of them in the error views committed earlier the same day, which means the English error pages were showing Arabic
- [x] Found on the way: the checks posted without a CSRF token and every request came back 419 - the middleware doing its job, and a check that cannot tell a refusal from a token mismatch reports whichever it expected. They now take the token and cookie from a GET of the page the form lives on, which exercises the protection rather than bypassing it
- [x] Found on the way: a validation failure on a form request is a 302 back with a flashed error bag, and so is a success - so the reason requirement is asserted by the **absence of the side effect**, not by a status code
- [x] The two new write routes carry the same `@no-permission-required:` annotation as `setStatus`, naming the same known gap: the operator plane has no role model, so every operator is a full administrator and any of them can provision or expire. The wiring rule caught their absence - it refuses a write route that performs no authorization and has no stated reason
- [x] Plan and subscription management; platform-wide settings including the risk ceilings tenants may not exceed
- [x] Impersonation requires explicit tenant consent recorded in advance, is time-limited, is visibly banner-marked in the impersonated session, and writes an audit event in **both** planes
- [x] An operator can never read a tenant's document contents without impersonation, so the access is always attributable
- [x] Every operator page is guarded and has a test proving it
