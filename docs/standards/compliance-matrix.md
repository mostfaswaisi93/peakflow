# Compliance matrix

**Requirement → control → test → evidence.** A control that is not tested is an assumption, and a control that produces no evidence cannot be shown to an auditor. Every row names all four.

This file is the index an auditor is given. It is regenerated as part of `compliance:report` so it reflects the state of the system rather than someone's recollection of it.

## Ledger integrity

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| Every entry balances | Deferred constraint trigger in PostgreSQL (`PF-043`) | Property-based: any zero-sum line set commits, any other is refused — including by raw SQL | `ledger:verify`, nightly, exits non-zero on failure |
| Subledgers equal their control accounts | Control accounts postable only by registered posting rules, enforced in the database (`PF-038`) | Feature tests assert AR, AP and tax ties after every money path | `ledger:verify` per entity per period |
| Posted history is immutable | Trigger **plus** `REVOKE DELETE` for the application role (`PF-045`, `PF-233`) | Mutation attempted via Eloquent, query builder and raw SQL; all three must fail | Privilege drift check on every deploy |
| Nothing posts into a closed period | Trigger on the entry header (`PF-044`) | Direct SQL insert into a closed period is refused | Period state-change audit log |
| Read models never become a second source of truth | Rebuildable from the journal by definition; nightly `--check` reports drift (`PF-053`, `PF-182`) | Rebuild-and-diff test | Nightly drift report; alert on non-zero |
| Reporting cannot write | Architecture test plus Deptrac (`PF-005`, `PF-003`) | Any write call in `Reporting` fails the build | CI run record |

## Document integrity

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| Invoice numbers are gapless | Row-lock allocation, allocate-last, no I/O under the lock (`PF-093`) | **`PF-094`**: 200 invoices, 20 parallel workers, numbers exactly `1..200`; variants for failures, crashes and two series | Per-series gap report — what an auditor actually asks for |
| Issued documents cannot be altered undetectably | SHA-256 hash chain over canonicalized legally significant fields (`PF-096`) | Canonicalization stability across PHP version, key order and locale | `invoices:verify-chain`, nightly, reports the **first** divergence |
| An issued document is a historical statement | Party, registration, rate, FX and terms **frozen** at issue (`PF-091`) | Change a party name, a tax rate and a payment term after issue; assert the document is unchanged | Document snapshot fields |
| The original electronic document is retained unaltered | The exact transmitted bytes in WORM object storage with a checksum manifest (`PF-129`) | Checksum verification; PDF/A conformance of the restored copy | `archive:verify` weekly; scheduled restore drill |
| Archival PDFs are genuinely PDF/A | Ghostscript conversion then **veraPDF verification**, failing the job on non-conformance (`PF-102`) | Golden-file render plus veraPDF in the pipeline | veraPDF report per release |

## Tax and e-invoicing

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| EN 16931 semantic conformance | Four-stage validation: internal, XSD, EN 16931 Schematron, profile Schematron (`PF-114`–`PF-116`) | Official EN 16931 and Peppol corpora as golden files, plus a negative fixture per major `BR-CO-*` rule | Validation report per document, retained |
| Tax total equals the breakdown (`BR-CO-14`) | Per-rate-group accumulation, rounded once per group (`PF-082`) | Property-based across generated documents; worked fixtures to the cent | Document tax breakdown |
| Historical rates are preserved | Effective-dated rate versions; resolution by **tax point**; overlap refused by an exclusion constraint (`PF-076`) | Rate change mid-month; documents either side resolve differently | Rate version history |
| Jurisdiction rules cannot change under us | Schematron **vendored and version-pinned**, with a documented update procedure (`PF-115`) | Corpus re-run on every artefact bump; the diff is reviewed | Pinned artefact versions in the repository |
| Filed returns are immutable | Frozen snapshot with figures, supporting line ids and a hash (`PF-086`) | A later adjustment does not alter the filed snapshot | Byte-identical re-print of a filed return |
| The return ties to the ledger | Tax account reconciliation; an unexplained difference **blocks the close** (`PF-088`) | Reconciliation asserted per period | Close checklist record, including any override and its reason |

## Access control and segregation of duties

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| Only authorized users act | Verb-shaped permissions per entity; every write route checks one (`PF-028`) | A test enumerates write routes and asserts a permission check on each | Access review in `compliance:report` |
| Incompatible duties are separated | SoD pairs evaluated at the second act **against the audit trail** (`PF-033`) | Both directions of every pair, plus the different-users case | SoD refusal log |
| Exceptions are visible, not invisible | Documented override with reason, named approver and expiry; **cannot be self-approved**; the beneficiary pair cannot be overridden at all (`PF-034`) | Self-approval refused; the non-overridable pair refused | Standing exceptions report |
| Money-moving actions need a second factor | MFA required at **grant** time for marked permissions (`PF-031`) | Granting to a factorless user is refused | Permission-holder and factor-status report |
| A stolen session is not enough | Step-up re-authentication at release and at period reopen (`PF-032`) | Stale step-up is refused | Step-up audit events naming what was authorized |
| Beneficiary substitution is prevented | Column encryption, change audit, re-verification before the next payment, non-overridable SoD pair (`PF-061`, `PF-175`) | A changed account blocks release until re-verified | Bank-detail change audit trail |
| Outgoing funds need two people | Preparer ≠ releaser against the audit trail; any post-preparation change invalidates the preparation (`PF-175`) | Same-user release refused; edited run returns to prepare | Payment run release record with the file hash |
| Operator access is attributable | Impersonation requires pre-recorded tenant consent, is time-limited, banner-marked, and audited in **both** planes (`PF-036`) | Impersonation without consent refused | Impersonation grant and audit records |

## Data protection

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| Tenant data cannot cross tenants | **Physical** database separation plus a per-tenant database role (`PF-019`) | A tenant connection cannot reach another database | Connection configuration; privilege check |
| Entity data cannot cross entities | Application scope **plus** row-level security (`PF-026`) | Raw query and console access still return nothing for a foreign entity | RLS policy presence check per table |
| Secrets are not recoverable from the system | KMS-held keys; the application holds a reference; log **allowlist** rather than a redaction blocklist (`PF-234`) | A payload with an IBAN, a token and a password produces none of them in logs, errors or traces | Secret-scanning results; log audit |
| A tenant can take its data | `tenants:dump` exports everything it owns and nothing else, attachments included (`PF-239`) | Export completeness and isolation | Export manifest |
| Erasure is honest | Reconciled against statutory retention, which wins, **and the UI says so** (`PF-239`) | A deletion request against a retained invoice is refused with an explanation | Retention policy per data category |
| Non-production data is not personal | Pseudonymized **by the process that creates the environment**, not by a cleanup afterwards | Seeded environments contain no production personal data | Environment provisioning record |

## Accessibility

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| WCAG 2.2 AA contrast (1.4.3, 1.4.11) | Two-layer OKLCH tokens with declared legitimate pairs (`PF-199`) | **Programmatic contrast check over every pair in both themes**; a palette change that regresses any pair fails the build | Contrast matrix report per release |
| Keyboard operability (2.1.1) | A full keyboard model on the grid, the builder and the reconciliation view (`PF-206`, `PF-210`) | Browser tests perform a multi-row edit and a reconciliation with the keyboard alone | Browser test recordings |
| Name, role, value (4.1.2) | Every control labelled; ids derived per instance (`PF-212`) | Architecture test over templates: every `<label>` names a control, every `for` matches one `id`, no duplicate ids | CI run record |
| Virtualized content is announced correctly | `aria-rowcount` reflects the **total**, not the rendered window (`PF-207`) | Grid ARIA test | Screen-reader verification once per release, recorded |
| Bypass blocks (2.4.1) | Skip link first in tab order; **`<main tabindex="-1">`** (`PF-204`) | Focus lands in `main`, and the next `Tab` does not return to the navigation | Browser test |
| Information is not colour alone (1.4.1) | Negative amounts carry parentheses, a sign and a colour; states carry an icon and a word (`PF-211`) | Visual regression in both themes; template audit | Design-system conformance record |
| Motion can be reduced (2.3.3) | Every transition wrapped in `prefers-reduced-motion` (`PF-214`) | The reduced path is tested, not assumed | CI run record |

## Operational resilience

| Requirement | Control | Test | Evidence |
|-------------|---------|------|----------|
| A tenant can be restored to a point in time | Per-tenant WAL archiving — **the requirement that chose this tenancy model** (`PF-244`) | **Scheduled restore drill** into an isolated environment, then `ledger:verify` against it | Drill report with measured RTO |
| An outage does not become a user error | `Exchange` plane with retry, circuit breaker and dead letter (`PF-228`) | A transport outage leaves the document issued and the transmission pending | Queue depth metrics; dead-letter report |
| Duplicate submission is impossible | Idempotency on ledger writes, on transmissions, and on recurring generation (`PF-220`, `PF-106`) | Same request fired twice concurrently yields one document and two identical responses | Idempotency replay log |
| Invariant breaches are noticed | Alerts on ledger drift, chain divergence, subledger mismatch, suspense threshold, privilege drift, archive mismatch (`PF-242`) | **Game day**: each condition injected in staging; the alert must fire and the runbook must work | Game-day report per release |
| Migrations do not break tenants | Online-safety declaration and pre-flight check; expand-contract; feature flags (`PF-023`, `PF-246`) | An unsafe migration is refused by the check | Migration records per tenant with schema version |

## ASVS 5.0 verification

The full requirement-by-requirement assessment is produced per release by `PF-231` and attached to the release notes, with:

- The verdict per applicable requirement, the evidence, and the responsible control
- **A diff against the previous pass**, so a regression is visible rather than buried
- Gaps recorded as tasks with owners, not as notes in a document

A requirement assessed as not applicable states why. "Not applicable" without a reason is treated as a gap.
