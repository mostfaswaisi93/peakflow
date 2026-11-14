# Standards register

Every standard PeakFlow is built against, its version, what conformance means here, and where it is implemented. A requirement you cannot check against a published clause is a requirement you will argue about.

**Conformance levels used below:**

| Level | Meaning |
|-------|---------|
| **Conformant** | The full standard as it applies to this product, verified by test |
| **Subset** | A declared subset, with the omissions listed and reasoned |
| **Aligned** | The standard's model and vocabulary are followed; formal certification is not claimed |
| **Informs** | Used as design guidance rather than as a conformance target |

## Accounting and financial reporting

| Standard | Version | Level | Applied to | Tasks |
|----------|---------|-------|------------|-------|
| IAS 1 Presentation of Financial Statements | 2024 | Aligned | Chart classification, SFP, P&L and OCI, changes in equity | PF-037, PF-184, PF-185, PF-187 |
| IAS 7 Statement of Cash Flows | 2024 | Aligned | Indirect **and** direct, which must agree | PF-186 |
| IAS 8 Accounting Policies and Errors | 2024 | Aligned | Reversals, prior-period adjustments | PF-056, PF-087 |
| IAS 10 Events After the Reporting Period | 2024 | Aligned | The close/lock boundary, adjusting period 13 | PF-050, PF-051 |
| IAS 21 Foreign Currency | 2024 | Aligned | Functional vs presentation currency, revaluation, translation to OCI | PF-068, PF-070, PF-170, PF-194 |
| IFRS 9 Financial Instruments | 2024 | **Subset** | Expected credit loss on receivables only. Hedge accounting, classification and measurement of other instruments are out of scope | PF-173 |
| IFRS 15 Revenue from Contracts | 2024 | **Subset** | Performance obligations and deferred revenue. Variable consideration and contract-cost capitalization are out of scope | PF-107 |
| Double-entry bookkeeping | — | Conformant | The zero-sum invariant, enforced in the database | PF-043 |

**Not implemented, deliberately:** IFRS 16 leases, IAS 2 inventory, IAS 16 property plant and equipment, IAS 12 deferred tax, and consolidation of non-controlled entities. Each is a real product area and none is load-bearing for the invoicing-banking-ledger loop.

## Invoicing and document exchange

| Standard | Version | Level | Applied to | Tasks |
|----------|---------|-------|------------|-------|
| EN 16931-1 semantic model | 2017 + A1 | **Conformant** | The internal invoice model; all `BR-*` and `BR-CO-*` rules | PF-110, PF-116 |
| OASIS UBL / ISO/IEC 19845 | 2.1 | Conformant | Primary XML serialization | PF-112 |
| UN/CEFACT CII | D16B | Conformant | Alternate serialization, basis of Factur-X | PF-113 |
| Peppol BIS Billing | 3.0 | Conformant | CIUS for Peppol delivery, `PEPPOL-EN16931-*` rules | PF-120 |
| Peppol transport | AS4 / ebMS 3.0 | Aligned | Via a certified access point, not self-certified | PF-122 |
| Factur-X / ZUGFeRD | 1.0 / 2.3 | Conformant | Hybrid PDF/A-3 with embedded CII | PF-125 |
| PDF/A-3 / ISO 19005-3 | 2012 | **Conformant, verified** | Archival PDF — veraPDF verifies, and a failure fails the job | PF-102, PF-125 |
| ZATCA e-invoicing | Current | Conformant | KSA: UBL subset, PIH chain, TLV QR, stamp, clearance and reporting | PF-123, PF-124 |
| Egypt ETA | Current | Conformant | JSON, signing, EGS/GS1 item coding | PF-126 |
| JoFotara (Jordan) | Current | Conformant | National UBL subset; three-decimal `JOD` | PF-127 |
| ISO/IEC 19757-3 Schematron | 2020 | Conformant | Compiled to XSLT 2.0 and vendored, not fetched at runtime | PF-115 |
| UNCL 1001 document types | D.23A | Conformant | `380`, `381`, `383`, `384`, `386`, `389` | PF-091 |
| UNCL 5305 tax categories | D.23A | Conformant | `S`, `Z`, `E`, `AE`, `O`, `K`, `G` | PF-075 |
| UNCL 4461 payment means | D.23A | Conformant | Payment method coding | PF-166 |
| UNCL 2475 payment terms | D.23A | Aligned | Terms coding | PF-066 |
| UNSPSC / GS1 GPC / UNCL 7143 | Current | Aligned | Item classification, always with its scheme id | PF-064 |
| UN/CEFACT Rec 20 + Rec 21 | Current | Conformant | Units of measure | PF-007 |

## Banking and payments

| Standard | Version | Level | Applied to | Tasks |
|----------|---------|-------|------------|-------|
| ISO 20022 CAMT.053 | .001.08 | Conformant | Statement ingestion, including structured remittance | PF-150 |
| ISO 20022 CAMT.052 / .054 | .001.08 / .001.08 | Conformant | Intraday report and notification — provisional, never posted | PF-151 |
| ISO 20022 PAIN.001 | .001.09 | Conformant | Credit transfer initiation | PF-176 |
| ISO 20022 PAIN.002 | .001.10 | Conformant | Status report, applied per instruction | PF-178 |
| ISO 20022 PAIN.008 | .001.08 | Conformant | Direct debit collection | PF-177 |
| ISO 20022 External Code Sets | Current | Conformant | Bank transaction codes drive matching strategy | PF-155 |
| EPC SEPA SCT / SCT Inst / SDD | Current rulebooks | Conformant | Character set, limits, lead times, mandate sequence | PF-176, PF-177 |
| SWIFT MT940 / MT942 | Current | **Subset** | Legacy. `:86:` parsing is bank-specific and documented as a degraded path | PF-152 |
| OFX | 2.x | Subset | Retail and North American banks | PF-153 |
| ISO 13616 IBAN | 2020 | Conformant | Structure per country | PF-008 |
| ISO/IEC 7064 | 2003 | Conformant | MOD-97-10 check digits | PF-008 |
| ISO 9362 BIC | 2022 | Conformant | Structural validation | PF-008 |
| ISO 4217 Currency | Current | Conformant | Codes **and minor units** — the exponent is not always 2 | PF-007 |

## Security, privacy and accessibility

| Standard | Version | Level | Applied to | Tasks |
|----------|---------|-------|------------|-------|
| OWASP ASVS | 5.0 | **Conformant, verified per release** | The security requirement baseline; result recorded in release notes | PF-231 |
| OWASP Top 10 / API Top 10 | 2021 / 2023 | Informs | Threat model and review checklist | PF-232 |
| ISO/IEC 27001 Annex A | 2022 | Aligned | Control mapping for the operator plane | PF-238 |
| SOC 2 Trust Services Criteria | 2017 (rev.) | Aligned | Evidence-generating controls: audit log, access review, change management | PF-238 |
| GDPR and local equivalents | — | Aligned | Lawful basis, retention, erasure vs statutory retention, residency | PF-239 |
| eIDAS | 910/2014 | Aligned | Qualified seal where a jurisdiction requires one | PF-124 |
| WCAG | **2.2 level AA** | **Conformant, tested** | The accessibility floor, not an aspiration | PF-200, PF-207, PF-212 |
| WAI-ARIA | 1.2 | Conformant | Grid, dialog, live region semantics | PF-207 |
| EN 301 549 | 3.2 | Aligned | European accessibility requirement, satisfied via WCAG 2.2 AA | PF-207 |
| WebAuthn | Level 3 | Conformant | Passkeys as the preferred factor | PF-030 |
| RFC 6238 TOTP | 2011 | Conformant | Fallback factor. SMS deliberately not offered | PF-030 |

## Engineering

| Standard | Version | Level | Applied to | Tasks |
|----------|---------|-------|------------|-------|
| OpenAPI | 3.1 | Conformant | The API contract, **written before the route** | PF-217 |
| RFC 9457 Problem Details | 2023 | Conformant | Every API error, addressed by field or business term | PF-219 |
| RFC 9110 / 9111 HTTP | 2022 | Conformant | Conditional requests, ETags, caching | PF-223 |
| RFC 8288 Web Linking | 2017 | Conformant | Pagination `Link` headers | PF-221 |
| RFC 9745 / 8594 | 2025 / 2019 | Conformant | `Deprecation` and `Sunset` headers | PF-230 |
| RFC 8785 JSON Canonicalization | 2020 | Conformant | Canonical form before hashing | PF-009 |
| XML C14N | 1.1 | Conformant | Canonical form before hashing and sealing | PF-009 |
| RFC 5545 iCalendar | 2009 | **Subset** | `RRULE` for recurrence only | PF-105 |
| ISO 8601 / RFC 3339 | — | Conformant | Timestamps. **Accounting dates are `date`, not timestamps** | PF-222 |
| ISO 3166-1 / ISO 639-1 | Current | Conformant | Country and language codes | PF-007 |
| CLDR / ICU MessageFormat | Current | Conformant | Number, date and plural formatting; Arabic's six plural categories | PF-016, PF-202 |
| Semantic Versioning | 2.0.0 | Conformant | With a written definition of "breaking" for this product | PF-245 |
| Conventional Commits | 1.0.0 | Conformant | Enforced by hook | PF-001 |
| Keep a Changelog | 1.1.0 | Conformant | Generated, then **edited for humans** | PF-245 |
| CycloneDX | 1.6 | Conformant | SBOM published per release | PF-245 |
| The Twelve-Factor App | — | Informs | Configuration and deployment discipline | PF-012 |
| RFC 4180 CSV | 2005 | Conformant | With formula-injection neutralization | PF-197 |

## How conformance is kept honest

| Mechanism | Covers |
|-----------|--------|
| **Golden-file corpora** from official test suites, byte-compared | EN 16931, Peppol, ZATCA, Factur-X |
| **Vendored, version-pinned Schematron** with a documented update procedure | A government updating its rules on a Tuesday cannot silently change what we accept on Wednesday |
| **veraPDF in the pipeline** | PDF/A-3 conformance is verified, not claimed |
| **An ASVS pass per release**, diffed against the previous one | Security regressions are visible |
| **Programmatic contrast checks** over every token pair in both themes | WCAG 1.4.3 and 1.4.11 cannot regress silently |
| **A two-way OpenAPI contract test** | The spec and the implementation cannot drift |
| **The compliance matrix** | Requirement → control → test → evidence, in [`compliance-matrix.md`](compliance-matrix.md) |

## What is not claimed

Stated plainly, because overclaiming conformance is itself a compliance risk:

- **No formal certification** is claimed for Peppol (transport goes through a certified access point), eIDAS, SOC 2, ISO/IEC 27001 or PCI DSS. Where a standard is listed as "Aligned", the design follows it; an audit has not been performed.
- **No card data is handled.** PCI DSS scope is avoided by design rather than satisfied — card payments, if ever added, would be tokenized by a provider.
- **Tax advice is not provided.** The engine applies rules a tenant or its adviser configures, and the place-of-supply fallback **refuses rather than guesses**.
- **No audit opinion.** PeakFlow produces the records an auditor reads. It does not sign them.
