# Phase 01 — Tenancy, entities, identity and segregation of duties

**19 tasks · 84 points**

Two structures are built here and both are load-bearing for everything after. **Tenancy** is physical isolation: a database per customer. **Entity** is legal isolation inside a customer: a company that files its own return. Confusing the two, or adding either one late, is a migration across every financial table in the system.

The identity work in this phase is not generic authentication. It is the part of authentication that finance needs and most applications do not: mandatory second factors bound to specific permissions, re-authentication at the moment of an act, and segregation of duties evaluated against the audit trail rather than against a role list.

---

### PF-018 · Platform schema
`Platform` · **3 pts** · depends: PF-010 · standards: —

- [ ] `operators`, `tenants`, `plans`, `subscriptions`, `database_connections`, `platform_settings`, `activity_logs`
- [ ] ULID primary keys throughout
- [ ] `database_connections.password` encrypted at the column level with the application key, and excluded from every model's serialization
- [ ] `activity_logs` indexed on `created_at` and on `(operator_id, created_at)` - a composite leading with the filter and trailing with the sort, because PostgreSQL uses one index per table reference and a lone `operator_id` index lets it filter or sort, never both

### PF-019 · Tenant provisioning, create mode
`Platform` · **8 pts** · depends: PF-018 · standards: — · `risk:high`

- [ ] `tenants:provision` issues `CREATE DATABASE`, `CREATE ROLE`, `GRANT`, runs the tenant migrations and seeds the baseline
- [ ] Each tenant gets its own database **role** with a generated password, not a shared one
- [ ] The whole provision is idempotent and transactional at the step level: a failure half way leaves no orphan database and no half-registered tenant
- [ ] A rollback path drops what it created, and is tested by injecting a failure at each step
- [ ] Provisioning runs as `peakflow_migrator`, never as the application role

### PF-020 · Tenant provisioning, link mode
`Platform` · **3 pts** · depends: PF-019 · standards: —

For managed hosting where `CREATE DATABASE` is not available.

- [ ] A pre-created database and credentials can be attached to a new tenant
- [ ] The connection is verified, the schema version checked, and migrations applied before the tenant is marked usable
- [ ] The mode is recorded on the tenant, because the backup and restore procedure differs between them

### PF-021 · Connection resolution and middleware ordering
`Platform` · **5 pts** · depends: PF-019 · standards: — · `risk:high`

- [ ] `TenantConnectionResolver` rewrites `database.connections.tenant` and purges the connection
- [ ] `tenant.session` resolves from the session; `tenant.header` resolves from `X-Tenant`
- [ ] Both are pinned **ahead of** the auth middleware via `prependToPriorityList`, and a test asserts the resolved order - because the user table lives in the tenant database and a guard that ran first would query the wrong one
- [ ] The resolved tenant is bound into the container as `currentTenant`
- [ ] A subscription that became invalid between page loads tears down the session and redirects to a dedicated page rather than failing the next query
- [ ] A tenant mid-migration returns `503` with `Retry-After`

### PF-022 · Subscription lifecycle and plan gating
`Platform` · **3 pts** · depends: PF-021 · standards: —

- [ ] States: `trial`, `active`, `past_due`, `suspended`, `expired`, `migrating`
- [ ] A subscription is valid **through** its end date, so the expiry comparison is strictly `<`; the boundary has its own test
- [ ] `subscriptions:expire` is idempotent and writes one activity log row per transition
- [ ] A `plan` middleware gates features by plan, with a clear upgrade message rather than a 403

### PF-023 · Tenant migrations at scale
`Platform` · **5 pts** · depends: PF-019 · standards: —

- [ ] `tenants:migrate {tenant}` and `--all`, with batching, concurrency limits and per-tenant failure isolation
- [ ] A dry-run mode reporting what would change per tenant
- [ ] A pre-flight check refusing a migration that is not safe to run while the application serves traffic - adding a non-nullable column without a default, rewriting a large table, taking an `ACCESS EXCLUSIVE` lock on a partitioned table
- [ ] A schema-version row per tenant, so a request can detect a tenant that is behind and respond rather than fail
- [ ] A documented note that the plain `migrate` command applies only the platform schema

### PF-024 · Tenant identity schema
`Identity` · **3 pts** · depends: PF-023 · standards: —

- [ ] `users`, `roles`, `permissions`, `role_permissions`, `user_entity_roles`, `sessions`, `authenticators`, `audit_log`
- [ ] A user's roles are held **per entity**, not globally - a group accountant holds roles in four companies, a subsidiary clerk in one
- [ ] Soft deletes on `users`, so a document created three years ago still resolves its author

### PF-025 · The entity model
`Tenancy` · **5 pts** · depends: PF-024 · standards: IAS 21 · `risk:high`

- [ ] `entities` with legal name, registration numbers, country, **functional currency**, fiscal calendar reference and presentation-currency default
- [ ] One or more `tax_registrations` per entity, each with a jurisdiction, a scheme and an identifier
- [ ] The functional currency is immutable once the entity has posted anything - changing it is an IAS 21 re-presentation, not an edit, and the UI explains that
- [ ] Documented: what an entity owns exclusively (chart, currency, calendar, registrations, numbering, postings) and what is shared across the tenant (users, parties, items, currencies, rates, documents)

### PF-026 · Entity scoping and row-level security
`Tenancy` · **8 pts** · depends: PF-025 · standards: — · `risk:high` `type:security`

Two independent mechanisms, because one of them will eventually have a hole.

- [ ] `entity.scope` middleware binds `currentEntity` and refuses an entity the user holds no role in
- [ ] A global Eloquent scope applies `entity_id` to every financial model
- [ ] PostgreSQL **row-level security** policies on every financial table inside the tenant database, keyed on a session variable set per request
- [ ] A test proves that bypassing the Eloquent scope - raw query, console command - still returns nothing for a foreign entity
- [ ] The architecture test from `PF-005` requiring `entity_id` is extended to require an RLS policy for each such table

### PF-027 · Entity switching
`Identity` · **3 pts** · depends: PF-026 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] An entity switcher in the shell, listing only entities the user holds a role in
- [ ] Switching re-evaluates permissions and re-scopes the session; the scope is re-checked per request rather than trusted from the cookie
- [ ] The active entity is visible on every screen and on every print output - a user posting into the wrong company is the failure this prevents
- [ ] Keyboard operable, announced on change through a live region

### PF-028 · The permission catalogue
`Identity` · **3 pts** · depends: PF-024 · standards: —

- [ ] Verb-shaped permissions, grouped by module: `invoice.draft`, `invoice.issue`, `credit_note.issue`, `bill.create`, `bill.approve`, `payment.prepare`, `payment.release`, `journal.post`, `journal.reverse`, `period.close`, `period.reopen`, `bank.import`, `bank.reconcile`, `reconciliation.write_off`, `supplier.bank_details.update`, `report.view`, `tax_return.file`, `settings.manage`, `user.manage`
- [ ] Each carries a plain-language bilingual description, because a role screen listing keys is unusable by the person who should be configuring it
- [ ] A test asserts every route that writes checks a permission - `auth` only proves someone is signed in, and a hidden navigation item is not access control
- [ ] Seeded roles: `owner`, `controller`, `accountant`, `ap_clerk`, `ar_clerk`, `auditor` (read-only), each a defensible real-world bundle

### PF-029 · Session and device hardening
`Identity` · **3 pts** · depends: PF-024 · standards: OWASP ASVS 5.0 · `type:security`

- [ ] Fortify for the session guard; separate guards for the operator and tenant planes
- [ ] Session fixation protection, idle and absolute timeouts, concurrent-session visibility and remote revocation
- [ ] Sessions bound to a device fingerprint; a binding change forces re-authentication
- [ ] Login throttling per account and per IP, with a response that does not reveal whether the account exists

### PF-030 · Passkeys and TOTP
`Identity` · **5 pts** · depends: PF-029 · standards: WebAuthn Level 3, RFC 6238 · `type:security`

- [ ] WebAuthn passkey enrolment and authentication, multiple credentials per user, with a readable credential manager
- [ ] TOTP as the fallback, with recovery codes issued once and stored hashed
- [ ] SMS is deliberately **not** offered, and the reason is documented
- [ ] Enrolment and removal are audited; removing the last factor from a user holding a money-moving permission is refused

### PF-031 · Binding MFA to money-moving permissions
`Identity` · **3 pts** · depends: PF-030, PF-028 · standards: OWASP ASVS 5.0 · `risk:high` `type:security`

- [ ] A permission can be marked as requiring an enrolled second factor
- [ ] Granting such a permission to a user with no factor is refused at the point of granting, with a message explaining what to do - not at the point of use, where it blocks real work
- [ ] `payment.release`, `journal.post`, `period.close`, `period.reopen`, `supplier.bank_details.update`, `reconciliation.write_off` and `tax_return.file` are marked
- [ ] A report lists users holding marked permissions and their factor status

### PF-032 · Step-up re-authentication
`Identity` · **3 pts** · depends: PF-030 · standards: OWASP ASVS 5.0 · `type:security`

- [ ] A `step-up` middleware requiring a fresh factor assertion within a short window, independent of session age
- [ ] Applied to releasing a payment run and to reopening a closed period - a stolen session must not be enough for either
- [ ] The step-up event is audited with what it authorized

### PF-033 · The segregation-of-duties engine
`Identity` · **8 pts** · depends: PF-028, PF-035 · standards: ISO/IEC 27001 Annex A, SOC 2 · `risk:high` `type:security`

A permission answers *may this user do X*. An SoD rule answers *may the same user do X and then Y to the same object*. Conflating them is the classic hole: a clerk who may both create a supplier and release a payment can pay themselves.

- [ ] Incompatible duty pairs declared as data, evaluated at the moment of the second act against the audit trail of the first
- [ ] The shipped pairs: supplier create or bank-detail change against payment release; bill create against bill approve; payment prepare against payment release; journal post against period close; credit note against the invoice's own issuer; reconciliation match against write-off
- [ ] A conflict is **refused, logged and explained** - the message names the conflicting act, who performed it and when, so the user knows who to ask
- [ ] Scope is per object where that is the meaningful unit, and per period where it is not
- [ ] Tests cover both directions of every pair and the case where the two acts are performed by different users

### PF-034 · SoD overrides and the exceptions report
`Identity` · **3 pts** · depends: PF-033 · standards: SOC 2 · `type:security`

A tenant with three staff may genuinely be unable to separate two duties. Silently allowing it and silently blocking it are both wrong.

- [ ] A documented override requires a reason, a specified approver and an expiry
- [ ] The override is itself an audit event and appears on a standing exceptions report
- [ ] Overrides cannot be self-approved, and cannot be granted for the beneficiary-change-against-payment-release pair at all
- [ ] The report is part of the `compliance:report` evidence pack

### PF-035 · The audit log
`Identity` · **5 pts** · depends: PF-024, PF-009 · standards: SOC 2, ISO/IEC 27001 Annex A · `risk:high` `type:security`

- [ ] Append-only, hash-chained, declaratively partitioned by month
- [ ] `DELETE` and `UPDATE` revoked at the role level, not merely unused
- [ ] Captures actor, entity, action, object, before and after where applicable, correlation id, IP and user agent
- [ ] **Reads of sensitive data are audited too** - who looked at a bank account is an auditable event, not only who changed it
- [ ] Indexed on `created_at`, `(category, created_at)` and `(action, created_at)`, for the same one-index-per-reference reason as the platform log
- [ ] A verification command re-walks the chain and reports the first divergence

### PF-036 · The operator console
`Platform` · **5 pts** · depends: PF-022 · standards: — · `type:security`

- [ ] Tenant lifecycle: provision, suspend, resume, expire, export, delete - each with a confirmation that names the consequence
- [ ] Plan and subscription management; platform-wide settings including the risk ceilings tenants may not exceed
- [ ] Impersonation requires explicit tenant consent recorded in advance, is time-limited, is visibly banner-marked in the impersonated session, and writes an audit event in **both** planes
- [ ] An operator can never read a tenant's document contents without impersonation, so the access is always attributable
- [ ] Every operator page is guarded and has a test proving it
