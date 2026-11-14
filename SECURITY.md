# Security policy

## Reporting a vulnerability

**Do not open a public issue, a pull request or a discussion for a security vulnerability.**

Report it privately through GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) on this repository. If that is unavailable, contact the maintainer directly through the address on their GitHub profile and mark the subject as a security report.

Please include: what you found, how to reproduce it, which component and version, what an attacker could achieve, and anything you are unsure about. A partial report sent early is more useful than a complete one sent late.

### What to expect

| Stage | Target |
|-------|--------|
| Acknowledgement | 2 business days |
| Initial assessment and severity | 5 business days |
| Fix or documented mitigation for a critical finding | 14 days |
| Coordinated disclosure | By agreement, after a fix is available |

We will keep you informed, credit you if you want to be credited, and tell you when the fix ships. We will not take legal action against good-faith research that respects the boundaries below.

### In scope

Anything that breaks one of the guarantees the product is built on:

- **Tenant isolation.** Reading or writing another tenant's data by any route.
- **Entity isolation.** Crossing a legal-entity boundary inside one tenant.
- **Ledger integrity.** Writing an unbalanced entry, mutating a posted entry, posting into a locked period, or producing a balance that cannot be derived from the journal.
- **Document integrity.** Forging, altering or deleting an issued document without breaking its hash chain; creating a gap in a legal numbering sequence.
- **Authorization and segregation of duties.** Performing an act without its permission, defeating an SoD pair, self-approving, or escalating through delegation.
- **Payment path.** Substituting a beneficiary, defeating maker-checker, bypassing step-up authentication, or causing a duplicate payment.
- **Secrets.** Extracting a signing key, a transport credential, a tenant database password or stored bank details.
- **Authentication.** Bypassing MFA, fixating or hijacking a session, or defeating step-up re-authentication.
- **Audit trail.** Suppressing, altering or forging an audit event.
- Injection, SSRF, XXE, deserialization, path traversal, and stored content that executes against the application's origin.

### Out of scope

- Reports produced solely by an automated scanner with no demonstrated impact.
- Missing hardening headers on endpoints that serve no content.
- Rate-limit thresholds without a demonstrated amplification.
- Social engineering of staff or customers, physical attacks, and anything requiring a compromised endpoint the user already controls.
- Vulnerabilities in a third-party dependency that is already patched upstream - report those upstream; tell us if our pinned version is affected.
- Denial of service through sheer volume.

### Boundaries for testing

Test against your own instance. Do not test against a production tenant you do not own, do not access data that is not yours, do not degrade service for others, and do not exfiltrate data - a proof of concept that demonstrates access does not need to retrieve the records. If you access data unintentionally, stop, do not retain it, and say so in your report.

## How security is built in

Security here is not a phase at the end; the controls listed below are structural and are specified task by task in [`docs/tasks/`](docs/tasks/), with the full threat model in [`docs/operations/security-hardening.md`](docs/operations/security-hardening.md).

- **Tenant isolation is physical** - a separate database and a separate database role per tenant, so a missing scope cannot cross a boundary because there is nothing to cross to.
- **The application's database role cannot delete posted financial rows, cannot alter the schema, and cannot reach the platform database.** Migrations run as a different role from a different process, and a drift check verifies the live grants on every deploy.
- **Nothing financial is updated in place.** Posted entries are reversed, issued documents are credited, reconciliations are unreconciled with a reason. Append-only is enforced by triggers and by revoked privileges, not by convention.
- **Secrets never reach the database or a log.** Signing keys live in a KMS; the application holds a reference. Structured logging uses an allowlist rather than a redaction blocklist, because a blocklist fails open the first time a field is added.
- **MFA is mandatory for money-moving permissions**, with step-up re-authentication at the moment of the act, and segregation of duties evaluated against the audit trail rather than against a role list.
- **The audit log is append-only, hash-chained, and records reads of sensitive data** as well as writes.
- **Inbound documents from trading partners are untrusted input**, validated before they are parsed, with XXE and entity-expansion protections configured explicitly on every XML reader.
- **OWASP ASVS 5.0 is the checklist**, verified per release with the result recorded in the release notes rather than asserted once.

## Supported versions

PeakFlow is pre-release. Until `1.0`, only `main` receives security fixes. From `1.0`, the current minor and the one before it are supported, and the policy will be restated here.
