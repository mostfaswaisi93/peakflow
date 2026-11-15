# Security hardening

**Security is not a phase.** Every delivery phase carries its own security acceptance criteria; Phase 13 is where those controls are *verified* and the alerting that watches them is built.

Baseline: **OWASP ASVS 5.0**, assessed per release with the result and a diff against the previous pass recorded in the release notes. A requirement assessed as not applicable states why — "not applicable" without a reason is treated as a gap.

## Threat model by trust boundary

| Boundary | Principal threats | Controls |
|----------|-------------------|----------|
| Browser → application | Session theft, XSS, CSRF, clickjacking | CSP with nonces, `SameSite` host-prefixed cookies, framework CSRF, device-bound sessions, step-up auth |
| Application → tenant database | Cross-tenant read, cross-entity read, mutation of posted history | **Physical** database separation, per-tenant role, RLS, revoked `DELETE`, triggers |
| Application → platform database | Tenant code reaching operator data | The application role **cannot connect** to the platform database |
| Application → Exchange | Key extraction, document forgery | Keys in a KMS; the application holds a reference, never a key |
| Exchange → partner | MITM, replay, SSRF | TLS with verification, signed payloads, outbound allowlist refusing private ranges, no redirect following |
| Partner → Exchange | XXE, entity expansion, malicious payload | Validate before parse; XXE, DTD and external entities disabled explicitly; size caps |
| Operator → tenant | Unaccountable access to customer data | Impersonation requires pre-recorded consent, is time-limited, banner-marked, and audited in **both** planes |
| Insider with legitimate access | Self-dealing, beneficiary substitution, hiding a posting | SoD against the audit trail, maker-checker, MFA bound to permissions, append-only history |

## Abuse cases

Not misuse cases. These are what someone with legitimate access would actually try, and each names the control that stops it and the test that proves the control.

| Abuse case | Control | Test |
|------------|---------|------|
| Create a supplier with my own bank account, then release a payment to it | Non-overridable SoD pair; beneficiary change requires re-verification before the next payment | Both directions of the pair; a changed account blocks release |
| Approve my own bill | SoD pair `bill.create` / `bill.approve` | Same-user approval refused |
| Launder a conflict through a delegate | **A delegate cannot receive an approval SoD forbids them** | Delegated conflicting approval refused |
| Edit a bill after approval, then pay the higher amount | **Any edit returns the document to the start of the chain** | Edited-after-approval test |
| Pay the same invoice twice | Hard uniqueness on `(supplier, document number)`, near-match warning, duplicate check **again at release** across all runs and entities | Duplicate constructed across two runs and two entities |
| Reopen a closed period to hide a posting | `period.reopen` + step-up auth + a reason; the reopen appears on the compliance report | Reopen audit and report presence |
| Write off a receivable I invoiced, to erase it | SoD; write-off above threshold needs a second approver | Pair test |
| Close a period over a posting I made | SoD pair `journal.post` / `period.close` | Pair test |
| Post directly into the AR control account to hide a difference | **Control accounts postable only by registered posting rules, enforced in the database** | Direct SQL insert refused |
| Delete a posted entry via a console command | Trigger **plus** revoked `DELETE` for the application role | Eloquent, query builder and raw SQL all refused |
| Export the customer list as exfiltration | Export is permissioned, rate-limited, queued, and **audited as a read of sensitive data** | Audit event on export |
| Read a bank account without changing it | **Reads of sensitive data are audited**, not only writes | Audit event on read |
| Use a webhook URL to reach an internal service | Outbound allowlist refuses private, link-local and loopback; redirects not followed | SSRF test suite |
| Upload an SVG that executes against the app origin | Type sniffed, served from a **separate origin** with `Content-Disposition: attachment` | Stored-XSS test |
| Exfiltrate via a CSV formula | Formula injection neutralized on **the route**, not only in the service | Export test with `=`, `+`, `-`, `@`, tab, CR |
| Escape an entity scope with a raw query | RLS inside the tenant database, independent of the Eloquent scope | Raw query and console access |

## Identity

| Control | Detail |
|---------|--------|
| **Passkeys preferred** (WebAuthn L3) | Phishing-resistant, and they satisfy WCAG 3.3.8 because they need no cognitive test |
| TOTP fallback (RFC 6238) | Recovery codes issued once, stored hashed |
| **SMS deliberately not offered** | SIM swap is the dominant attack on the exact population this product serves |
| **MFA bound to permissions** | Marked permissions cannot be **granted** to a factorless user — refused at grant time, with an explanation, not at use time where it blocks real work |
| Step-up at the act | Payment release and period reopen. A stolen session is not enough |
| Sessions | Fixation protection, idle and absolute timeouts, device binding, concurrent-session visibility and remote revocation |
| Login throttling | Per account **and** per IP, with a response that does not reveal whether an account exists |
| No CAPTCHA, paste never blocked | Both are accessibility failures and neither stops a determined attacker |

## Data protection

| Layer | Control |
|-------|---------|
| At rest, disk | Full-disk and database-level encryption |
| At rest, column | **Bank details and TOTP secrets encrypted separately**, with the key referenced from the KMS |
| In transit | TLS 1.3, HSTS with preload, certificate verification never disabled |
| In logs and traces | **Allowlist**, never a blocklist |
| In webhooks | Identifiers and amounts only; **never bank details**, asserted by test |
| In non-production | Pseudonymized **by the process that creates the environment**, not by a cleanup afterwards |
| Residency | Recorded per tenant; a tenant's database lives in its declared region, provably |

**Erasure is reconciled against statutory retention, which wins** — and the product says so in the UI rather than pretending to delete. A tax authority requiring seven years of invoice retention overrides a deletion request, and a system that silently ignores the request while appearing to honour it is the worse outcome.

## Application hardening

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{random}';
  style-src 'self' 'nonce-{random}'; img-src 'self' data:; connect-src 'self';
  frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Referrer-Policy: strict-origin-when-cross-origin
X-Content-Type-Options: nosniff
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
```

**No `unsafe-inline`, no `unsafe-eval`.** Report-only first, then enforcing. A test asserts the headers on a real response, because a header set in a config file nobody loads is not a header.

| Input | Control |
|-------|---------|
| Filters, sorts, includes, exports | **Allowlisted** per resource; anything else is a 400 naming what is permitted |
| `LIKE` patterns | `%`, `_` and the escape character escaped, with a test proving each is searchable for itself |
| XML | XXE, DTD, external entities and network access **disabled explicitly on every reader**; expansion and depth limits; size cap before parsing |
| Uploads | Type sniffed, extension allowlisted, size capped, malware scanned before readable, filenames normalized, traversal and unicode-confusable names rejected |
| CSV export | Formula injection neutralized on the route |
| Serialization | No user-controlled deserialization anywhere |

## Database privileges

Three roles, and the application is not the migrator. Inside a tenant database the **migrator owns the schema** and is the only role that can create, alter or drop anything; the **tenant role** - what the application connects as - gets `USAGE` plus default DML and no DDL whatsoever. Database ownership and schema ownership are separate, so a per-tenant `pg_dump` stays straightforward while the tenant role owns nothing inside its own database.

### Append-only tables declare themselves

Default privileges give the tenant role `SELECT, INSERT, UPDATE, DELETE` on every table the migrator creates. That is right for almost all of them and wrong for the ledger and the audit log, so those tables opt out with a table comment:

```sql
COMMENT ON TABLE audit_log IS 'peakflow:append-only - ...';
```

`TenantProvisioner::harden()` runs as part of every migration, reads the marker, and revokes `UPDATE, DELETE` from the tenant role — **on the parent and on every partition individually**, because a privilege granted on a partitioned table does not describe the partitions underneath it.

The marker is read rather than listed deliberately. A migration that adds an append-only table is hardened by the comment it already wants for its own documentation; there is no register to update and nothing to forget.

> **This was a real defect, found by a behavioural check and worth keeping as a warning.**
>
> An earlier version of the audit-log migration did the revoke itself:
>
> ```sql
> REVOKE UPDATE, DELETE ON audit_log FROM peakflow_app;  -- wrong
> ```
>
> The role a tenant connects as is generated per tenant (`pf_t_<ulid>`), so `peakflow_app` is not it. The statement was a well-formed no-op against a role that exists in the cluster but never connects to a tenant database. The migration's own docblock claimed three independent layers of protection; two of them were real and the privilege revoke was decoration.
>
> Nothing in the code could have found this. The migration ran without error, a tampering attempt was still refused — by the trigger — and a test that only checked *that* a mutation fails would have passed for years. What found it was a check that asked the catalogue `has_table_privilege(current_user, 'audit_log', 'UPDATE')` and got back `true`.
>
> The lesson is in `TenantEnforcementRules`: **check each layer of a defence separately, against the thing that enforces it.** A check satisfied by any refusal cannot tell you how many layers you have left. The trigger is now proven by running as a role that *does* hold the privilege, so the two layers can never mask one another again.

**A drift check verifies the live grants on every deploy**, because a manual grant made during an incident is exactly how this regresses, and nobody remembers to revert it.

## Secrets

- **Signing keys live in a KMS or HSM.** The application holds a reference, not a key, and the `Exchange` plane never reads a key from config at runtime.
- Rotation procedures per key type, **exercised rather than documented**.
- Secret scanning on the repository, with a documented response procedure for an exposure.
- A log audit asserting no secret, token, IBAN or personal datum reaches a log line, an error report or a trace attribute — tested with a payload containing all of them.

## Dependencies and supply chain

| Control | Cadence |
|---------|---------|
| `composer audit`, npm audit | Every build |
| Dependabot | Continuous, with a documented triage window |
| Trivy on container images | Every build |
| CodeQL | Every build |
| **CycloneDX SBOM** | Published per release |
| Lockfiles committed, exact versions | Always |
| Vendored Schematron and schemas, version-pinned | Updated by pull request with a corpus diff |

> **Nothing is fetched at runtime that affects behaviour.** Fonts are vendored. Validation artefacts are vendored. A government updating its Schematron on a Tuesday cannot silently change what PeakFlow accepts on Wednesday.

## Evidence

`compliance:report {tenant}` produces a reproducible pack for a given date:

- Access review: who holds which permission in which entity, and their MFA status
- SoD overrides in force, with reason, approver and expiry
- Period state changes and every reopening, with reasons
- Hash-chain verification results
- Close-checklist overrides
- Failed scheduled runs
- Privilege drift results
- Dormant accounts and unused tokens

An auditor asks for a point in time, so the pack is a reproducible artefact rather than a live dashboard.
