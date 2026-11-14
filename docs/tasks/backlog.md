# The flat backlog

All **246 tasks**, **1188 points**, in dependency-respecting order. Generated from the phase files, which remain the authoritative text — this view exists for sorting, filtering and sizing, not for reading a task's full acceptance criteria.

| # | Task | Phase | Module | Pts | Depends on | Standards | Labels |
|---|------|-------|--------|-----|------------|-----------|--------|
| `PF-001` | [Laravel 13 skeleton on PHP 8.4, repository hygiene](phase-00-foundations.md#pf-001--laravel-13-skeleton-on-php-8-4-repository-hygiene) | 00 | — | 2 | — | Conventional Commits, SemVer 2.0, Keep a Changelog | — |
| `PF-002` | [Formatting and static analysis at maximum](phase-00-foundations.md#pf-002--formatting-and-static-analysis-at-maximum) | 00 | — | 3 | PF-001 | — | — |
| `PF-003` | [Deptrac module layers](phase-00-foundations.md#pf-003--deptrac-module-layers) | 00 | — | 3 | PF-001 | — | — |
| `PF-004` | [Pest 4 with separated suites](phase-00-foundations.md#pf-004--pest-4-with-separated-suites) | 00 | — | 3 | PF-001 | — | — |
| `PF-005` | [The architecture tests that encode the non-negotiables](phase-00-foundations.md#pf-005--the-architecture-tests-that-encode-the-non-negotiables) | 00 | — | 5 | PF-004 | — | `risk:high` |
| `PF-006` | [The money support layer](phase-00-foundations.md#pf-006--the-money-support-layer) | 00 | Support/Money | 5 | PF-001 | ISO 4217 | `risk:high` |
| `PF-007` | [ISO code registries](phase-00-foundations.md#pf-007--iso-code-registries) | 00 | Support/Iso | 3 | PF-006 | ISO 4217, ISO 3166-1, ISO 639-1, UN/CEFACT Rec 20 & 21 | — |
| `PF-008` | [IBAN and BIC value objects](phase-00-foundations.md#pf-008--iban-and-bic-value-objects) | 00 | Support/Iso | 3 | PF-007 | ISO 13616, ISO/IEC 7064, ISO 9362 | — |
| `PF-009` | [Canonicalization and hash-chain support](phase-00-foundations.md#pf-009--canonicalization-and-hash-chain-support) | 00 | Support/Hashing | 3 | PF-001 | RFC 8785 (JCS), XML C14N 1.1, SHA-256 | — |
| `PF-010` | [PostgreSQL roles and least privilege](phase-00-foundations.md#pf-010--postgresql-roles-and-least-privilege) | 00 | — | 5 | PF-001 | — | `risk:high` `type:security` |
| `PF-011` | [Local development environment](phase-00-foundations.md#pf-011--local-development-environment) | 00 | — | 3 | PF-001 | — | — |
| `PF-012` | [Configuration skeleton and `.env.example`](phase-00-foundations.md#pf-012--configuration-skeleton-and-env-example) | 00 | — | 3 | PF-001 | The Twelve-Factor App | — |
| `PF-013` | [CI pipeline, authored and deliberately disabled](phase-00-foundations.md#pf-013--ci-pipeline-authored-and-deliberately-disabled) | 00 | — | 3 | PF-002, PF-003, PF-004 | — | — |
| `PF-014` | [Structured logging with a field allowlist](phase-00-foundations.md#pf-014--structured-logging-with-a-field-allowlist) | 00 | — | 3 | PF-001 | — | `type:security` |
| `PF-015` | [Error handling and guard-aware responses](phase-00-foundations.md#pf-015--error-handling-and-guard-aware-responses) | 00 | — | 3 | PF-001 | RFC 9457 | — |
| `PF-016` | [Localization skeleton and its coverage test](phase-00-foundations.md#pf-016--localization-skeleton-and-its-coverage-test) | 00 | — | 5 | PF-001 | ICU MessageFormat, CLDR | — |
| `PF-017` | [Vite, Tailwind v4 and the token scaffold](phase-00-foundations.md#pf-017--vite-tailwind-v4-and-the-token-scaffold) | 00 | — | 5 | PF-001 | WCAG 2.2 AA | — |
| `PF-018` | [Platform schema](phase-01-tenancy-identity.md#pf-018--platform-schema) | 01 | Platform | 3 | PF-010 | — | — |
| `PF-019` | [Tenant provisioning, create mode](phase-01-tenancy-identity.md#pf-019--tenant-provisioning-create-mode) | 01 | Platform | 8 | PF-018 | — | `risk:high` |
| `PF-020` | [Tenant provisioning, link mode](phase-01-tenancy-identity.md#pf-020--tenant-provisioning-link-mode) | 01 | Platform | 3 | PF-019 | — | — |
| `PF-021` | [Connection resolution and middleware ordering](phase-01-tenancy-identity.md#pf-021--connection-resolution-and-middleware-ordering) | 01 | Platform | 5 | PF-019 | — | `risk:high` |
| `PF-022` | [Subscription lifecycle and plan gating](phase-01-tenancy-identity.md#pf-022--subscription-lifecycle-and-plan-gating) | 01 | Platform | 3 | PF-021 | — | — |
| `PF-023` | [Tenant migrations at scale](phase-01-tenancy-identity.md#pf-023--tenant-migrations-at-scale) | 01 | Platform | 5 | PF-019 | — | — |
| `PF-024` | [Tenant identity schema](phase-01-tenancy-identity.md#pf-024--tenant-identity-schema) | 01 | Identity | 3 | PF-023 | — | — |
| `PF-025` | [The entity model](phase-01-tenancy-identity.md#pf-025--the-entity-model) | 01 | Tenancy | 5 | PF-024 | IAS 21 | `risk:high` |
| `PF-026` | [Entity scoping and row-level security](phase-01-tenancy-identity.md#pf-026--entity-scoping-and-row-level-security) | 01 | Tenancy | 8 | PF-025 | — | `risk:high` `type:security` |
| `PF-027` | [Entity switching](phase-01-tenancy-identity.md#pf-027--entity-switching) | 01 | Identity | 3 | PF-026 | WCAG 2.2 AA | — |
| `PF-028` | [The permission catalogue](phase-01-tenancy-identity.md#pf-028--the-permission-catalogue) | 01 | Identity | 3 | PF-024 | — | — |
| `PF-029` | [Session and device hardening](phase-01-tenancy-identity.md#pf-029--session-and-device-hardening) | 01 | Identity | 3 | PF-024 | OWASP ASVS 5.0 | `type:security` |
| `PF-030` | [Passkeys and TOTP](phase-01-tenancy-identity.md#pf-030--passkeys-and-totp) | 01 | Identity | 5 | PF-029 | WebAuthn Level 3, RFC 6238 | `type:security` |
| `PF-031` | [Binding MFA to money-moving permissions](phase-01-tenancy-identity.md#pf-031--binding-mfa-to-money-moving-permissions) | 01 | Identity | 3 | PF-030, PF-028 | OWASP ASVS 5.0 | `risk:high` `type:security` |
| `PF-032` | [Step-up re-authentication](phase-01-tenancy-identity.md#pf-032--step-up-re-authentication) | 01 | Identity | 3 | PF-030 | OWASP ASVS 5.0 | `type:security` |
| `PF-033` | [The segregation-of-duties engine](phase-01-tenancy-identity.md#pf-033--the-segregation-of-duties-engine) | 01 | Identity | 8 | PF-028, PF-035 | ISO/IEC 27001 Annex A, SOC 2 | `risk:high` `type:security` |
| `PF-034` | [SoD overrides and the exceptions report](phase-01-tenancy-identity.md#pf-034--sod-overrides-and-the-exceptions-report) | 01 | Identity | 3 | PF-033 | SOC 2 | `type:security` |
| `PF-035` | [The audit log](phase-01-tenancy-identity.md#pf-035--the-audit-log) | 01 | Identity | 5 | PF-024, PF-009 | SOC 2, ISO/IEC 27001 Annex A | `risk:high` `type:security` |
| `PF-036` | [The operator console](phase-01-tenancy-identity.md#pf-036--the-operator-console) | 01 | Platform | 5 | PF-022 | — | `type:security` |
| `PF-037` | [The chart of accounts](phase-02-ledger.md#pf-037--the-chart-of-accounts) | 02 | Ledger | 5 | PF-026 | IAS 1 | `type:schema` |
| `PF-038` | [Control-account roles](phase-02-ledger.md#pf-038--control-account-roles) | 02 | Ledger | 5 | PF-037 | — | `risk:high` `type:posting` |
| `PF-039` | [Chart templates per jurisdiction](phase-02-ledger.md#pf-039--chart-templates-per-jurisdiction) | 02 | Ledger | 3 | PF-038 | — | — |
| `PF-040` | [Journals and their series](phase-02-ledger.md#pf-040--journals-and-their-series) | 02 | Ledger | 3 | PF-037 | — | `type:schema` |
| `PF-041` | [Journal entries and lines](phase-02-ledger.md#pf-041--journal-entries-and-lines) | 02 | Ledger | 5 | PF-040 | — | `risk:high` `type:schema` |
| `PF-042` | [Partition `journal_lines` by period](phase-02-ledger.md#pf-042--partition-journal-lines-by-period) | 02 | Ledger | 5 | PF-041 | — | — |
| `PF-043` | [The balanced-entry constraint](phase-02-ledger.md#pf-043--the-balanced-entry-constraint) | 02 | Ledger | 8 | PF-041 | Double-entry bookkeeping | `risk:high` `type:schema` |
| `PF-044` | [Closed periods are closed in the database](phase-02-ledger.md#pf-044--closed-periods-are-closed-in-the-database) | 02 | Ledger | 3 | PF-043, PF-049 | IAS 10 | `risk:high` |
| `PF-045` | [Posted entries are immutable](phase-02-ledger.md#pf-045--posted-entries-are-immutable) | 02 | Ledger | 5 | PF-043, PF-010 | — | `risk:high` `type:security` |
| `PF-046` | [Dimensions](phase-02-ledger.md#pf-046--dimensions) | 02 | Ledger | 5 | PF-041 | — | `type:schema` |
| `PF-047` | [Posting rules](phase-02-ledger.md#pf-047--posting-rules) | 02 | Ledger | 8 | PF-038, PF-046 | — | `risk:high` `type:posting` |
| `PF-048` | [Domain events and listeners](phase-02-ledger.md#pf-048--domain-events-and-listeners) | 02 | Ledger | 3 | PF-047, PF-003 | — | — |
| `PF-049` | [Fiscal years and periods](phase-02-ledger.md#pf-049--fiscal-years-and-periods) | 02 | Ledger | 5 | PF-025 | IAS 1 | `type:schema` |
| `PF-050` | [The period state machine](phase-02-ledger.md#pf-050--the-period-state-machine) | 02 | Ledger | 5 | PF-049, PF-032 | IAS 10 | `risk:high` |
| `PF-051` | [The adjusting period](phase-02-ledger.md#pf-051--the-adjusting-period) | 02 | Ledger | 2 | PF-049 | IAS 10 | — |
| `PF-052` | [Year-end close](phase-02-ledger.md#pf-052--year-end-close) | 02 | Ledger | 5 | PF-050, PF-047 | IAS 1 | `type:posting` |
| `PF-053` | [Balance read models](phase-02-ledger.md#pf-053--balance-read-models) | 02 | Ledger | 5 | PF-042 | — | `risk:high` |
| `PF-054` | [`ledger:verify`](phase-02-ledger.md#pf-054--ledger-verify) | 02 | Ledger | 5 | PF-053, PF-038 | — | `risk:high` |
| `PF-055` | [Manual journal entry](phase-02-ledger.md#pf-055--manual-journal-entry) | 02 | Ledger | 5 | PF-047, PF-046 | — | — |
| `PF-056` | [Reversals](phase-02-ledger.md#pf-056--reversals) | 02 | Ledger | 3 | PF-055 | IAS 8 | `type:posting` |
| `PF-057` | [Inter-entity postings](phase-02-ledger.md#pf-057--inter-entity-postings) | 02 | Ledger | 8 | PF-052, PF-047 | IAS 1 | `risk:high` `type:posting` |
| `PF-058` | [The trial balance and its budget](phase-02-ledger.md#pf-058--the-trial-balance-and-its-budget) | 02 | Ledger | 3 | PF-053, PF-042 | IAS 1 | — |
| `PF-059` | [The party model](phase-03-masters.md#pf-059--the-party-model) | 03 | Parties | 5 | PF-026 | — | `type:schema` |
| `PF-060` | [Addresses and tax registrations](phase-03-masters.md#pf-060--addresses-and-tax-registrations) | 03 | Parties | 3 | PF-059 | ISO 3166-1, EN 16931, Peppol EAS | — |
| `PF-061` | [Party bank details](phase-03-masters.md#pf-061--party-bank-details) | 03 | Parties | 5 | PF-008, PF-035 | ISO 13616, ISO 9362 | `risk:high` `type:security` |
| `PF-062` | [Credit control](phase-03-masters.md#pf-062--credit-control) | 03 | Parties | 3 | PF-059 | IFRS 9 | — |
| `PF-063` | [Contacts and communication](phase-03-masters.md#pf-063--contacts-and-communication) | 03 | Parties | 2 | PF-059 | — | — |
| `PF-064` | [Billable items](phase-03-masters.md#pf-064--billable-items) | 03 | Catalog | 5 | PF-007, PF-026 | UN/CEFACT Rec 20, UNSPSC, GS1 GPC, UNCL 7143 | `type:schema` |
| `PF-065` | [Price lists](phase-03-masters.md#pf-065--price-lists) | 03 | Catalog | 5 | PF-064 | — | — |
| `PF-066` | [Payment terms and due dates](phase-03-masters.md#pf-066--payment-terms-and-due-dates) | 03 | Catalog | 3 | PF-059 | UNCL 2475, EN 16931 | — |
| `PF-067` | [Currencies per entity](phase-03-masters.md#pf-067--currencies-per-entity) | 03 | Catalog | 2 | PF-007, PF-025 | ISO 4217 | — |
| `PF-068` | [The FX rate model](phase-03-masters.md#pf-068--the-fx-rate-model) | 03 | Catalog | 5 | PF-067 | IAS 21 | `risk:high` `type:schema` |
| `PF-069` | [Rate fetching](phase-03-masters.md#pf-069--rate-fetching) | 03 | Catalog | 3 | PF-068 | — | — |
| `PF-070` | [Period-end revaluation](phase-03-masters.md#pf-070--period-end-revaluation) | 03 | Catalog | 5 | PF-068, PF-050 | IAS 21 | `type:posting` |
| `PF-071` | [Numbering series](phase-03-masters.md#pf-071--numbering-series) | 03 | Catalog | 5 | PF-025 | — | `risk:high` `type:schema` |
| `PF-072` | [Global search](phase-03-masters.md#pf-072--global-search) | 03 | — | 5 | PF-059, PF-064 | WCAG 2.2 AA | — |
| `PF-073` | [Attachments and document storage](phase-03-masters.md#pf-073--attachments-and-document-storage) | 03 | — | 5 | PF-026 | — `type:security` | `type:security` |
| `PF-074` | [Tenant settings with platform ceilings](phase-03-masters.md#pf-074--tenant-settings-with-platform-ceilings) | 03 | Tenancy | 5 | PF-022 | — | — |
| `PF-075` | [Tax categories and codes](phase-04-tax.md#pf-075--tax-categories-and-codes) | 04 | Tax | 5 | PF-026 | UNCL 5305, EN 16931 | `type:schema` `type:compliance` |
| `PF-076` | [Rate versioning](phase-04-tax.md#pf-076--rate-versioning) | 04 | Tax | 5 | PF-075 | — | `risk:high` |
| `PF-077` | [The tax point date](phase-04-tax.md#pf-077--the-tax-point-date) | 04 | Tax | 5 | PF-076 | EN 16931 (`BT-7`) | `risk:high` `type:compliance` |
| `PF-078` | [Place of supply](phase-04-tax.md#pf-078--place-of-supply) | 04 | Tax | 8 | PF-077, PF-060 | — | `risk:high` `type:compliance` |
| `PF-079` | [Reverse charge](phase-04-tax.md#pf-079--reverse-charge) | 04 | Tax | 3 | PF-078 | UNCL 5305 (`AE`), EN 16931 | `type:compliance` |
| `PF-080` | [Zero-rated, exempt and out of scope](phase-04-tax.md#pf-080--zero-rated-exempt-and-out-of-scope) | 04 | Tax | 3 | PF-075 | — | `type:compliance` |
| `PF-081` | [The rounding policy](phase-04-tax.md#pf-081--the-rounding-policy) | 04 | Tax | 5 | PF-006, PF-075 | EN 16931, ISO 4217 | `risk:high` `type:compliance` |
| `PF-082` | [Tax computation](phase-04-tax.md#pf-082--tax-computation) | 04 | Tax | 8 | PF-081, PF-078 | EN 16931 `BG-23`, `BR-CO-14` | `risk:high` `type:compliance` |
| `PF-083` | [Withholding tax](phase-04-tax.md#pf-083--withholding-tax) | 04 | Tax | 5 | PF-082 | — | `type:compliance` |
| `PF-084` | [Tax accounts and posting](phase-04-tax.md#pf-084--tax-accounts-and-posting) | 04 | Tax | 3 | PF-082, PF-047 | — | `type:posting` |
| `PF-085` | [Return periods and box mapping](phase-04-tax.md#pf-085--return-periods-and-box-mapping) | 04 | Tax | 5 | PF-084 | — | `type:compliance` |
| `PF-086` | [Return generation](phase-04-tax.md#pf-086--return-generation) | 04 | Tax | 8 | PF-085 | — | `risk:high` `type:compliance` |
| `PF-087` | [Post-filing adjustments](phase-04-tax.md#pf-087--post-filing-adjustments) | 04 | Tax | 3 | PF-086 | IAS 8 | `type:compliance` |
| `PF-088` | [Tax account reconciliation](phase-04-tax.md#pf-088--tax-account-reconciliation) | 04 | Tax | 5 | PF-086, PF-054 | — | `risk:high` |
| `PF-089` | [Quotes](phase-05-sales.md#pf-089--quotes) | 05 | Sales | 5 | PF-065, PF-082 | — | — |
| `PF-090` | [Sales orders](phase-05-sales.md#pf-090--sales-orders) | 05 | Sales | 5 | PF-089 | — | — |
| `PF-091` | [Invoice schema with a frozen snapshot](phase-05-sales.md#pf-091--invoice-schema-with-a-frozen-snapshot) | 05 | Sales | 8 | PF-082, PF-071 | EN 16931 | `risk:high` `type:schema` |
| `PF-092` | [Draft editing and validation](phase-05-sales.md#pf-092--draft-editing-and-validation) | 05 | Sales | 5 | PF-091 | EN 16931 | — |
| `PF-093` | [Gapless number allocation](phase-05-sales.md#pf-093--gapless-number-allocation) | 05 | Sales | 8 | PF-071 | — | `risk:high` |
| `PF-094` | [The gapless concurrency proof](phase-05-sales.md#pf-094--the-gapless-concurrency-proof) | 05 | Sales | 5 | PF-093 | — | `risk:high` |
| `PF-095` | [The issuing transaction](phase-05-sales.md#pf-095--the-issuing-transaction) | 05 | Sales | 8 | PF-093, PF-047, PF-009 | — | `risk:high` `type:posting` |
| `PF-096` | [The hash chain](phase-05-sales.md#pf-096--the-hash-chain) | 05 | Sales | 5 | PF-095 | SHA-256, XML C14N, RFC 8785 | `risk:high` `type:compliance` |
| `PF-097` | [Credit notes](phase-05-sales.md#pf-097--credit-notes) | 05 | Sales | 5 | PF-095 | UNCL 1001 (`381`) | `type:posting` |
| `PF-098` | [Debit notes and corrected invoices](phase-05-sales.md#pf-098--debit-notes-and-corrected-invoices) | 05 | Sales | 3 | PF-097 | UNCL 1001 (`383`, `384`) | `type:compliance` |
| `PF-099` | [Prepayment invoices](phase-05-sales.md#pf-099--prepayment-invoices) | 05 | Sales | 5 | PF-095 | UNCL 1001 (`386`), IFRS 15 | `type:posting` |
| `PF-100` | [Self-billed invoices](phase-05-sales.md#pf-100--self-billed-invoices) | 05 | Sales | 3 | PF-095 | UNCL 1001 (`389`) | `type:compliance` |
| `PF-101` | [Simplified and B2C invoices](phase-05-sales.md#pf-101--simplified-and-b2c-invoices) | 05 | Sales | 3 | PF-095 | EN 16931, jurisdiction profiles | `type:compliance` |
| `PF-102` | [Invoice PDF and PDF/A](phase-05-sales.md#pf-102--invoice-pdf-and-pdf-a) | 05 | Sales | 8 | PF-095 | ISO 19005-3 (PDF/A-3) | `type:compliance` |
| `PF-103` | [The print stylesheet](phase-05-sales.md#pf-103--the-print-stylesheet) | 05 | Sales | 5 | PF-102 | WCAG 2.2 AA | — |
| `PF-104` | [Delivery](phase-05-sales.md#pf-104--delivery) | 05 | Sales | 5 | PF-102, PF-063 | — | — |
| `PF-105` | [Recurring schedules](phase-05-sales.md#pf-105--recurring-schedules) | 05 | Sales | 5 | PF-095 | RFC 5545 | — |
| `PF-106` | [Recurring generation idempotency](phase-05-sales.md#pf-106--recurring-generation-idempotency) | 05 | Sales | 3 | PF-105 | — | `risk:high` |
| `PF-107` | [Revenue recognition](phase-05-sales.md#pf-107--revenue-recognition) | 05 | Sales | 8 | PF-099, PF-052 | IFRS 15 | `risk:high` `type:posting` |
| `PF-108` | [Dunning policies](phase-05-sales.md#pf-108--dunning-policies) | 05 | Sales | 5 | PF-066, PF-062 | — | — |
| `PF-109` | [Late fees, credit hold and collections](phase-05-sales.md#pf-109--late-fees-credit-hold-and-collections) | 05 | Sales | 3 | PF-108 | — | `type:posting` |
| `PF-110` | [The semantic invoice model](phase-06-einvoicing.md#pf-110--the-semantic-invoice-model) | 06 | Exchange | 8 | PF-091 | EN 16931-1 | `risk:high` `type:compliance` |
| `PF-111` | [The mapping document and its tests](phase-06-einvoicing.md#pf-111--the-mapping-document-and-its-tests) | 06 | Exchange | 5 | PF-110 | EN 16931-1 | `type:compliance` |
| `PF-112` | [The UBL 2.1 serializer](phase-06-einvoicing.md#pf-112--the-ubl-2-1-serializer) | 06 | Exchange | 8 | PF-110 | OASIS UBL 2.1, ISO/IEC 19845 | `risk:high` `type:compliance` |
| `PF-113` | [The CII D16B serializer](phase-06-einvoicing.md#pf-113--the-cii-d16b-serializer) | 06 | Exchange | 5 | PF-112 | UN/CEFACT CII D16B | `type:compliance` |
| `PF-114` | [XSD validation](phase-06-einvoicing.md#pf-114--xsd-validation) | 06 | Exchange | 3 | PF-112 | XML Schema 1.0 | `type:compliance` |
| `PF-115` | [Schematron compilation and vendoring](phase-06-einvoicing.md#pf-115--schematron-compilation-and-vendoring) | 06 | Exchange | 5 | PF-114 | ISO/IEC 19757-3 | `risk:high` `type:compliance` |
| `PF-116` | [EN 16931 semantic validation](phase-06-einvoicing.md#pf-116--en-16931-semantic-validation) | 06 | Exchange | 5 | PF-115 | EN 16931-1 | `risk:high` `type:compliance` |
| `PF-117` | [Business-term-addressed errors](phase-06-einvoicing.md#pf-117--business-term-addressed-errors) | 06 | Exchange | 3 | PF-116 | EN 16931-1, RFC 9457 | — |
| `PF-118` | [The jurisdiction profile interface](phase-06-einvoicing.md#pf-118--the-jurisdiction-profile-interface) | 06 | Exchange | 5 | PF-116 | — | `risk:high` |
| `PF-119` | [The generic EN 16931 profile](phase-06-einvoicing.md#pf-119--the-generic-en-16931-profile) | 06 | Exchange | 3 | PF-118 | EN 16931-1 | `type:compliance` |
| `PF-120` | [Peppol BIS Billing 3.0](phase-06-einvoicing.md#pf-120--peppol-bis-billing-3-0) | 06 | Exchange | 8 | PF-119 | Peppol BIS Billing 3.0 | `type:compliance` |
| `PF-121` | [Peppol participant lookup](phase-06-einvoicing.md#pf-121--peppol-participant-lookup) | 06 | Exchange | 5 | PF-120 | Peppol SML/SMP | — |
| `PF-122` | [Peppol AS4 transport](phase-06-einvoicing.md#pf-122--peppol-as4-transport) | 06 | Exchange | 8 | PF-121 | OASIS AS4, ebMS 3.0 | — |
| `PF-123` | [The ZATCA profile](phase-06-einvoicing.md#pf-123--the-zatca-profile) | 06 | Exchange | 8 | PF-119 | ZATCA e-invoicing | `risk:high` `type:compliance` |
| `PF-124` | [ZATCA sealing and the clearance API](phase-06-einvoicing.md#pf-124--zatca-sealing-and-the-clearance-api) | 06 | Exchange | 8 | PF-123 | ZATCA, eIDAS-equivalent sealing | `risk:high` `type:security` `type:compliance` |
| `PF-125` | [Factur-X / ZUGFeRD 2.3](phase-06-einvoicing.md#pf-125--factur-x-zugferd-2-3) | 06 | Exchange | 5 | PF-113, PF-102 | Factur-X 1.0 / ZUGFeRD 2.3, ISO 19005-3 | `type:compliance` |
| `PF-126` | [The Egypt ETA profile](phase-06-einvoicing.md#pf-126--the-egypt-eta-profile) | 06 | Exchange | 5 | PF-118 | Egypt ETA | `type:compliance` |
| `PF-127` | [The Jordan profile](phase-06-einvoicing.md#pf-127--the-jordan-profile) | 06 | Exchange | 3 | PF-118 | JoFotara | `type:compliance` |
| `PF-128` | [The golden-file corpus](phase-06-einvoicing.md#pf-128--the-golden-file-corpus) | 06 | Exchange | 8 | PF-120, PF-123 | — | `risk:high` `type:compliance` |
| `PF-129` | [Archiving](phase-06-einvoicing.md#pf-129--archiving) | 06 | Exchange | 8 | PF-128, PF-073 | ISO 19005-3 | `risk:high` `type:compliance` |
| `PF-130` | [Purchase requisitions](phase-07-purchasing.md#pf-130--purchase-requisitions) | 07 | Purchasing | 3 | PF-026 | — | — |
| `PF-131` | [Purchase orders](phase-07-purchasing.md#pf-131--purchase-orders) | 07 | Purchasing | 5 | PF-130, PF-064 | — | — |
| `PF-132` | [Goods and service receipts](phase-07-purchasing.md#pf-132--goods-and-service-receipts) | 07 | Purchasing | 3 | PF-131 | — | `type:posting` |
| `PF-133` | [Bills](phase-07-purchasing.md#pf-133--bills) | 07 | Purchasing | 5 | PF-082, PF-047 | EN 16931 | `type:posting` `type:schema` |
| `PF-134` | [Supplier credit notes](phase-07-purchasing.md#pf-134--supplier-credit-notes) | 07 | Purchasing | 3 | PF-133 | UNCL 1001 (`381`) | `type:posting` |
| `PF-135` | [Three-way match](phase-07-purchasing.md#pf-135--three-way-match) | 07 | Purchasing | 8 | PF-133, PF-132 | — | `risk:high` |
| `PF-136` | [The approval engine](phase-07-purchasing.md#pf-136--the-approval-engine) | 07 | Purchasing | 8 | PF-133, PF-033 | SOC 2 | `risk:high` `type:security` |
| `PF-137` | [Delegation and continuity](phase-07-purchasing.md#pf-137--delegation-and-continuity) | 07 | Purchasing | 3 | PF-136 | — | — |
| `PF-138` | [Approval limits across currencies](phase-07-purchasing.md#pf-138--approval-limits-across-currencies) | 07 | Purchasing | 3 | PF-136, PF-068 | IAS 21 | — |
| `PF-139` | [Duplicate bill detection](phase-07-purchasing.md#pf-139--duplicate-bill-detection) | 07 | Purchasing | 5 | PF-133 | — | `risk:high` |
| `PF-140` | [Email intake](phase-07-purchasing.md#pf-140--email-intake) | 07 | Purchasing | 5 | PF-073 | — | `type:security` |
| `PF-141` | [Inbound Peppol bills](phase-07-purchasing.md#pf-141--inbound-peppol-bills) | 07 | Purchasing | 3 | PF-122, PF-133 | Peppol BIS Billing 3.0 | — |
| `PF-142` | [Document extraction](phase-07-purchasing.md#pf-142--document-extraction) | 07 | Purchasing | 5 | PF-140 | — | — |
| `PF-143` | [Expense claims](phase-07-purchasing.md#pf-143--expense-claims) | 07 | Purchasing | 5 | PF-136 | — | `type:posting` |
| `PF-144` | [AP aging and supplier statements](phase-07-purchasing.md#pf-144--ap-aging-and-supplier-statements) | 07 | Purchasing | 3 | PF-133 | — | — |
| `PF-145` | [Bank accounts](phase-08-banking.md#pf-145--bank-accounts) | 08 | Banking | 5 | PF-008, PF-037 | ISO 13616, ISO 9362, ISO 4217 | `type:schema` |
| `PF-146` | [The GL mirror and three balances](phase-08-banking.md#pf-146--the-gl-mirror-and-three-balances) | 08 | Banking | 5 | PF-145, PF-038 | — | `risk:high` |
| `PF-147` | [Statements as continuous objects](phase-08-banking.md#pf-147--statements-as-continuous-objects) | 08 | Banking | 5 | PF-146 | ISO 20022 | `risk:high` `type:schema` |
| `PF-148` | [Bank transactions](phase-08-banking.md#pf-148--bank-transactions) | 08 | Banking | 5 | PF-147 | ISO 20022 | `type:schema` |
| `PF-149` | [Idempotent import](phase-08-banking.md#pf-149--idempotent-import) | 08 | Banking | 5 | PF-148 | — | `risk:high` |
| `PF-150` | [The CAMT.053 parser](phase-08-banking.md#pf-150--the-camt-053-parser) | 08 | Banking | 8 | PF-149 | ISO 20022 CAMT.053.001.08 | `risk:high` |
| `PF-151` | [Intraday reports](phase-08-banking.md#pf-151--intraday-reports) | 08 | Banking | 3 | PF-150 | ISO 20022 CAMT.052, CAMT.054 | — |
| `PF-152` | [MT940 and MT942](phase-08-banking.md#pf-152--mt940-and-mt942) | 08 | Banking | 5 | PF-149 | SWIFT MT940 / MT942 | — |
| `PF-153` | [OFX](phase-08-banking.md#pf-153--ofx) | 08 | Banking | 3 | PF-149 | OFX 2.x | — |
| `PF-154` | [CSV import](phase-08-banking.md#pf-154--csv-import) | 08 | Banking | 5 | PF-149 | — | — |
| `PF-155` | [Transaction codes and strategy routing](phase-08-banking.md#pf-155--transaction-codes-and-strategy-routing) | 08 | Banking | 3 | PF-150 | ISO 20022 External Code Sets | — |
| `PF-156` | [Matching passes 1 and 2](phase-08-banking.md#pf-156--matching-passes-1-and-2) | 08 | Banking | 8 | PF-155, PF-174 | — | `risk:high` |
| `PF-157` | [Matching passes 3 and 4](phase-08-banking.md#pf-157--matching-passes-3-and-4) | 08 | Banking | 5 | PF-156 | ISO 20022 `RmtInf/Strd` | — |
| `PF-158` | [Subset-sum matching](phase-08-banking.md#pf-158--subset-sum-matching) | 08 | Banking | 8 | PF-157 | — | `risk:high` |
| `PF-159` | [The rule engine](phase-08-banking.md#pf-159--the-rule-engine) | 08 | Banking | 5 | PF-157 | — | — |
| `PF-160` | [Fuzzy party matching](phase-08-banking.md#pf-160--fuzzy-party-matching) | 08 | Banking | 3 | PF-157 | — | — |
| `PF-161` | [Confidence and thresholds](phase-08-banking.md#pf-161--confidence-and-thresholds) | 08 | Banking | 5 | PF-160 | — | `risk:high` |
| `PF-162` | [Splits](phase-08-banking.md#pf-162--splits) | 08 | Banking | 5 | PF-158 | — | — |
| `PF-163` | [The suspense account](phase-08-banking.md#pf-163--the-suspense-account) | 08 | Banking | 5 | PF-161, PF-038 | — | `risk:high` `type:posting` |
| `PF-164` | [Difference classification](phase-08-banking.md#pf-164--difference-classification) | 08 | Banking | 5 | PF-163 | — | `risk:high` `type:posting` |
| `PF-165` | [Reconciliation as a document](phase-08-banking.md#pf-165--reconciliation-as-a-document) | 08 | Banking | 8 | PF-164 | — | `risk:high` |
| `PF-166` | [Payments](phase-09-settlement.md#pf-166--payments) | 09 | Settlement | 5 | PF-146, PF-047 | UNCL 4461 | `type:schema` `type:posting` |
| `PF-167` | [Unapplied cash](phase-09-settlement.md#pf-167--unapplied-cash) | 09 | Settlement | 3 | PF-166 | — | `type:posting` |
| `PF-168` | [Allocations](phase-09-settlement.md#pf-168--allocations) | 09 | Settlement | 8 | PF-167 | — | `risk:high` `type:posting` |
| `PF-169` | [Over-payment, credits and refunds](phase-09-settlement.md#pf-169--over-payment-credits-and-refunds) | 09 | Settlement | 5 | PF-168 | — | `type:posting` |
| `PF-170` | [Realized FX](phase-09-settlement.md#pf-170--realized-fx) | 09 | Settlement | 5 | PF-168, PF-068 | IAS 21 | `risk:high` `type:posting` |
| `PF-171` | [Rounding differences](phase-09-settlement.md#pf-171--rounding-differences) | 09 | Settlement | 3 | PF-168 | — | `type:posting` |
| `PF-172` | [Write-offs](phase-09-settlement.md#pf-172--write-offs) | 09 | Settlement | 5 | PF-168, PF-033 | IFRS 9 | `risk:high` `type:posting` `type:security` |
| `PF-173` | [Expected credit loss](phase-09-settlement.md#pf-173--expected-credit-loss) | 09 | Settlement | 5 | PF-172, PF-062 | IFRS 9 | `type:posting` |
| `PF-174` | [The payment run](phase-09-settlement.md#pf-174--the-payment-run) | 09 | Settlement | 8 | PF-144, PF-136 | — | `risk:high` |
| `PF-175` | [Maker-checker and step-up](phase-09-settlement.md#pf-175--maker-checker-and-step-up) | 09 | Settlement | 5 | PF-174, PF-032, PF-033 | OWASP ASVS 5.0, SOC 2 | `risk:high` `type:security` |
| `PF-176` | [PAIN.001 generation](phase-09-settlement.md#pf-176--pain-001-generation) | 09 | Settlement | 8 | PF-175 | ISO 20022 PAIN.001.001.09, EPC SEPA SCT | `risk:high` |
| `PF-177` | [Direct debit](phase-09-settlement.md#pf-177--direct-debit) | 09 | Settlement | 5 | PF-176 | ISO 20022 PAIN.008, EPC SEPA SDD | — |
| `PF-178` | [PAIN.002 status ingestion](phase-09-settlement.md#pf-178--pain-002-status-ingestion) | 09 | Settlement | 5 | PF-176 | ISO 20022 PAIN.002 | — |
| `PF-179` | [In-transit and the three-way tie](phase-09-settlement.md#pf-179--in-transit-and-the-three-way-tie) | 09 | Settlement | 5 | PF-178, PF-163 | — | `risk:high` `type:posting` |
| `PF-180` | [Duplicate payment detection](phase-09-settlement.md#pf-180--duplicate-payment-detection) | 09 | Settlement | 3 | PF-174, PF-139 | — | `risk:high` |
| `PF-181` | [Statements and open items](phase-09-settlement.md#pf-181--statements-and-open-items) | 09 | Settlement | 3 | PF-168 | — | — |
| `PF-182` | [The reporting read-model architecture](phase-10-reporting.md#pf-182--the-reporting-read-model-architecture) | 10 | Reporting | 8 | PF-053, PF-042 | — | `risk:high` |
| `PF-183` | [Trial balance](phase-10-reporting.md#pf-183--trial-balance) | 10 | Reporting | 3 | PF-182, PF-058 | IAS 1 | — |
| `PF-184` | [Statement of Financial Position](phase-10-reporting.md#pf-184--statement-of-financial-position) | 10 | Reporting | 5 | PF-183, PF-037 | IAS 1 | `type:compliance` |
| `PF-185` | [Statement of Profit or Loss and OCI](phase-10-reporting.md#pf-185--statement-of-profit-or-loss-and-oci) | 10 | Reporting | 5 | PF-184 | IAS 1 | `type:compliance` |
| `PF-186` | [Statement of Cash Flows](phase-10-reporting.md#pf-186--statement-of-cash-flows) | 10 | Reporting | 8 | PF-185 | IAS 7 | `risk:high` `type:compliance` |
| `PF-187` | [Statement of Changes in Equity](phase-10-reporting.md#pf-187--statement-of-changes-in-equity) | 10 | Reporting | 3 | PF-185 | IAS 1 | `type:compliance` |
| `PF-188` | [General ledger detail and registers](phase-10-reporting.md#pf-188--general-ledger-detail-and-registers) | 10 | Reporting | 3 | PF-183 | — | — |
| `PF-189` | [Universal drill-down](phase-10-reporting.md#pf-189--universal-drill-down) | 10 | Reporting | 5 | PF-188 | — | `risk:high` |
| `PF-190` | [AR and AP aging](phase-10-reporting.md#pf-190--ar-and-ap-aging) | 10 | Reporting | 5 | PF-168, PF-144 | IFRS 9 | — |
| `PF-191` | [Bank reconciliation reporting](phase-10-reporting.md#pf-191--bank-reconciliation-reporting) | 10 | Reporting | 3 | PF-165 | — | — |
| `PF-192` | [Cash position and forecast](phase-10-reporting.md#pf-192--cash-position-and-forecast) | 10 | Reporting | 8 | PF-190, PF-179 | — | — |
| `PF-193` | [Budgets and variance](phase-10-reporting.md#pf-193--budgets-and-variance) | 10 | Reporting | 5 | PF-046, PF-185 | — | — |
| `PF-194` | [Consolidation](phase-10-reporting.md#pf-194--consolidation) | 10 | Reporting | 8 | PF-057, PF-184 | IAS 21, IAS 1 | `risk:high` `type:compliance` |
| `PF-195` | [Inter-entity elimination](phase-10-reporting.md#pf-195--inter-entity-elimination) | 10 | Reporting | 5 | PF-194, PF-057 | — | `risk:high` |
| `PF-196` | [The period close checklist](phase-10-reporting.md#pf-196--the-period-close-checklist) | 10 | Reporting | 5 | PF-195, PF-050, PF-088 | IAS 10 | `risk:high` |
| `PF-197` | [Export](phase-10-reporting.md#pf-197--export) | 10 | Reporting | 5 | PF-183 | ISO 19005, RFC 4180 | `type:security` |
| `PF-198` | [Reproducibility](phase-10-reporting.md#pf-198--reproducibility) | 10 | Reporting | 3 | PF-197 | — | `risk:high` |
| `PF-199` | [The token architecture](phase-11-design-system.md#pf-199--the-token-architecture) | 11 | — | 8 | PF-017 | WCAG 2.2 AA | `risk:high` |
| `PF-200` | [The contrast matrix](phase-11-design-system.md#pf-200--the-contrast-matrix) | 11 | — | 5 | PF-199 | WCAG 2.2 AA (1.4.3, 1.4.11) | `risk:high` |
| `PF-201` | [Typography](phase-11-design-system.md#pf-201--typography) | 11 | — | 5 | PF-199 | — | `risk:high` |
| `PF-202` | [Digit shaping](phase-11-design-system.md#pf-202--digit-shaping) | 11 | — | 3 | PF-201 | CLDR, ICU | `type:compliance` |
| `PF-203` | [Density](phase-11-design-system.md#pf-203--density) | 11 | — | 5 | PF-199 | WCAG 2.2 AA (2.5.8) | — |
| `PF-204` | [The application shell](phase-11-design-system.md#pf-204--the-application-shell) | 11 | — | 5 | PF-203, PF-027 | WCAG 2.2 AA | — |
| `PF-205` | [The data grid](phase-11-design-system.md#pf-205--the-data-grid) | 11 | — | 8 | PF-204 | — | `risk:high` |
| `PF-206` | [The grid keyboard model](phase-11-design-system.md#pf-206--the-grid-keyboard-model) | 11 | — | 8 | PF-205 | WCAG 2.2 AA | `risk:high` |
| `PF-207` | [The grid's accessibility semantics](phase-11-design-system.md#pf-207--the-grid-s-accessibility-semantics) | 11 | — | 5 | PF-206 | WAI-ARIA 1.2, WCAG 2.2 AA | — |
| `PF-208` | [The command palette](phase-11-design-system.md#pf-208--the-command-palette) | 11 | — | 3 | PF-072, PF-204 | WCAG 2.2 AA | — |
| `PF-209` | [The invoice builder](phase-11-design-system.md#pf-209--the-invoice-builder) | 11 | Sales | 8 | PF-092, PF-206 | — | `risk:high` |
| `PF-210` | [The reconciliation view](phase-11-design-system.md#pf-210--the-reconciliation-view) | 11 | Banking | 8 | PF-165, PF-206 | WCAG 2.2 AA | `risk:high` |
| `PF-211` | [Charts](phase-11-design-system.md#pf-211--charts) | 11 | — | 5 | PF-199 | WCAG 2.2 AA (1.4.1, 1.4.11) | — |
| `PF-212` | [The form system](phase-11-design-system.md#pf-212--the-form-system) | 11 | — | 5 | PF-199 | WCAG 2.2 AA (3.3.1-3.3.8) | — |
| `PF-213` | [State coverage](phase-11-design-system.md#pf-213--state-coverage) | 11 | — | 5 | PF-205 | WCAG 2.2 AA | — |
| `PF-214` | [Motion](phase-11-design-system.md#pf-214--motion) | 11 | — | 3 | PF-199 | WCAG 2.2 AA (2.3.3) | — |
| `PF-215` | [Print stylesheets](phase-11-design-system.md#pf-215--print-stylesheets) | 11 | — | 5 | PF-103 | WCAG 2.2 AA | — |
| `PF-216` | [RTL enforcement](phase-11-design-system.md#pf-216--rtl-enforcement) | 11 | — | 5 | PF-204 | — | `risk:high` |
| `PF-217` | [The OpenAPI contract](phase-12-api-exchange.md#pf-217--the-openapi-contract) | 12 | — | 5 | PF-015 | OpenAPI 3.1 | `risk:high` |
| `PF-218` | [API authentication and scopes](phase-12-api-exchange.md#pf-218--api-authentication-and-scopes) | 12 | — | 5 | PF-028, PF-021 | OWASP API Top 10 | `type:security` |
| `PF-219` | [Problem-details errors](phase-12-api-exchange.md#pf-219--problem-details-errors) | 12 | — | 3 | PF-217, PF-117 | RFC 9457 | — |
| `PF-220` | [Idempotency](phase-12-api-exchange.md#pf-220--idempotency) | 12 | — | 5 | PF-218 | — | `risk:high` |
| `PF-221` | [Pagination](phase-12-api-exchange.md#pf-221--pagination) | 12 | — | 3 | PF-217 | RFC 8288 | — |
| `PF-222` | [Money and dates on the wire](phase-12-api-exchange.md#pf-222--money-and-dates-on-the-wire) | 12 | — | 3 | PF-006 | ISO 4217, ISO 8601, RFC 3339 | `risk:high` |
| `PF-223` | [Optimistic concurrency](phase-12-api-exchange.md#pf-223--optimistic-concurrency) | 12 | — | 3 | PF-217 | RFC 9110 | — |
| `PF-224` | [Filter and sort allowlists](phase-12-api-exchange.md#pf-224--filter-and-sort-allowlists) | 12 | — | 3 | PF-221 | OWASP API Top 10 | `type:security` |
| `PF-225` | [Rate limiting](phase-12-api-exchange.md#pf-225--rate-limiting) | 12 | — | 3 | PF-218 | — | `type:security` |
| `PF-226` | [Webhook events and signing](phase-12-api-exchange.md#pf-226--webhook-events-and-signing) | 12 | — | 5 | PF-048 | — | `risk:high` `type:security` |
| `PF-227` | [Webhook delivery](phase-12-api-exchange.md#pf-227--webhook-delivery) | 12 | — | 5 | PF-226 | — | — |
| `PF-228` | [The Exchange plane's reliability](phase-12-api-exchange.md#pf-228--the-exchange-plane-s-reliability) | 12 | Exchange | 8 | PF-122, PF-124 | — | `risk:high` |
| `PF-229` | [Drivers that work with no credentials](phase-12-api-exchange.md#pf-229--drivers-that-work-with-no-credentials) | 12 | Exchange | 3 | PF-228 | — | — |
| `PF-230` | [Versioning and deprecation](phase-12-api-exchange.md#pf-230--versioning-and-deprecation) | 12 | — | 3 | PF-217 | SemVer 2.0, RFC 9745, RFC 8594 | — |
| `PF-231` | [The ASVS verification pass](phase-13-hardening.md#pf-231--the-asvs-verification-pass) | 13 | — | 8 | PF-230 | OWASP ASVS 5.0 | `risk:high` `type:security` |
| `PF-232` | [Threat model and abuse cases](phase-13-hardening.md#pf-232--threat-model-and-abuse-cases) | 13 | — | 5 | PF-231 | OWASP | `type:security` |
| `PF-233` | [Database privilege verification](phase-13-hardening.md#pf-233--database-privilege-verification) | 13 | — | 5 | PF-010, PF-045 | — | `risk:high` `type:security` |
| `PF-234` | [Secret management](phase-13-hardening.md#pf-234--secret-management) | 13 | — | 5 | PF-124, PF-061 | OWASP ASVS 5.0 | `risk:high` `type:security` |
| `PF-235` | [XML hardening](phase-13-hardening.md#pf-235--xml-hardening) | 13 | Exchange | 3 | PF-114 | OWASP | `risk:high` `type:security` |
| `PF-236` | [Upload hardening](phase-13-hardening.md#pf-236--upload-hardening) | 13 | — | 3 | PF-073 | OWASP | `type:security` |
| `PF-237` | [HTTP security headers](phase-13-hardening.md#pf-237--http-security-headers) | 13 | — | 3 | PF-204 | OWASP, W3C | `type:security` |
| `PF-238` | [Access review and the evidence pack](phase-13-hardening.md#pf-238--access-review-and-the-evidence-pack) | 13 | — | 5 | PF-034, PF-035 | SOC 2, ISO/IEC 27001 Annex A | `type:security` `type:compliance` |
| `PF-239` | [Data protection](phase-13-hardening.md#pf-239--data-protection) | 13 | — | 5 | PF-129 | GDPR and local equivalents | `risk:high` `type:compliance` |
| `PF-240` | [Distributed tracing](phase-13-hardening.md#pf-240--distributed-tracing) | 13 | — | 5 | PF-014 | OpenTelemetry | — |
| `PF-241` | [Business metrics](phase-13-hardening.md#pf-241--business-metrics) | 13 | — | 5 | PF-240 | — | `risk:high` |
| `PF-242` | [Invariant alerting](phase-13-hardening.md#pf-242--invariant-alerting) | 13 | — | 5 | PF-241, PF-054 | — | `risk:high` |
| `PF-243` | [Performance](phase-13-hardening.md#pf-243--performance) | 13 | — | 5 | PF-058, PF-205 | Core Web Vitals | — |
| `PF-244` | [Backup and disaster recovery](phase-13-hardening.md#pf-244--backup-and-disaster-recovery) | 13 | — | 5 | PF-019 | — | `risk:high` |
| `PF-245` | [Release engineering](phase-13-hardening.md#pf-245--release-engineering) | 13 | — | 5 | PF-013 | SemVer 2.0, Conventional Commits, Keep a Changelog, CycloneDX | — |
| `PF-246` | [Zero-downtime migration and rollback](phase-13-hardening.md#pf-246--zero-downtime-migration-and-rollback) | 13 | — | 5 | PF-023, PF-245 | — | `risk:high` |

## Points by phase

| Phase | Theme | Tasks | Points |
|-------|-------|-------|--------|
| [00](phase-00-foundations.md) | Foundations, tooling and guardrails | 17 | 60 |
| [01](phase-01-tenancy-identity.md) | Tenancy, entities, identity and segregation of duties | 19 | 84 |
| [02](phase-02-ledger.md) | The general ledger | 22 | 104 |
| [03](phase-03-masters.md) | Parties, catalog, currencies and FX | 16 | 66 |
| [04](phase-04-tax.md) | The tax engine | 14 | 71 |
| [05](phase-05-sales.md) | Quotes, orders, invoices and credit notes | 21 | 110 |
| [06](phase-06-einvoicing.md) | EN 16931, serializers, validation and jurisdiction profiles | 20 | 116 |
| [07](phase-07-purchasing.md) | Purchase orders, bills, approvals and three-way match | 15 | 67 |
| [08](phase-08-banking.md) | Bank accounts, statements and reconciliation | 21 | 109 |
| [09](phase-09-settlement.md) | Payments, allocation, FX and payment runs | 16 | 81 |
| [10](phase-10-reporting.md) | Reporting, close and consolidation | 17 | 87 |
| [11](phase-11-design-system.md) | Design system, data grid, accessibility and RTL | 18 | 99 |
| [12](phase-12-api-exchange.md) | The REST API, webhooks and the Exchange plane | 14 | 57 |
| [13](phase-13-hardening.md) | Security, observability, performance and release | 16 | 77 |
| | **Total** | **246** | **1188** |

## Points by module

| Module | Tasks | Points |
|--------|-------|--------|
| — | 58 | 259 |
| Exchange | 23 | 130 |
| Sales | 22 | 118 |
| Banking | 22 | 117 |
| Ledger | 22 | 104 |
| Reporting | 17 | 87 |
| Settlement | 16 | 81 |
| Tax | 14 | 71 |
| Purchasing | 15 | 67 |
| Identity | 10 | 39 |
| Catalog | 8 | 33 |
| Platform | 7 | 32 |
| Tenancy | 3 | 18 |
| Parties | 5 | 18 |
| Support/Iso | 2 | 6 |
| Support/Money | 1 | 5 |
| Support/Hashing | 1 | 3 |

## Tasks carrying `risk:high`

Being subtly wrong in one of these costs money or breaks an audit. Each needs a second reviewer, and the reasoning for the riskiest of them is in [the risk register](README.md#risk-register).

| # | Task | Phase | Pts |
|---|------|-------|-----|
| `PF-005` | The architecture tests that encode the non-negotiables | 00 | 5 |
| `PF-006` | The money support layer | 00 | 5 |
| `PF-010` | PostgreSQL roles and least privilege | 00 | 5 |
| `PF-019` | Tenant provisioning, create mode | 01 | 8 |
| `PF-021` | Connection resolution and middleware ordering | 01 | 5 |
| `PF-025` | The entity model | 01 | 5 |
| `PF-026` | Entity scoping and row-level security | 01 | 8 |
| `PF-031` | Binding MFA to money-moving permissions | 01 | 3 |
| `PF-033` | The segregation-of-duties engine | 01 | 8 |
| `PF-035` | The audit log | 01 | 5 |
| `PF-038` | Control-account roles | 02 | 5 |
| `PF-041` | Journal entries and lines | 02 | 5 |
| `PF-043` | The balanced-entry constraint | 02 | 8 |
| `PF-044` | Closed periods are closed in the database | 02 | 3 |
| `PF-045` | Posted entries are immutable | 02 | 5 |
| `PF-047` | Posting rules | 02 | 8 |
| `PF-050` | The period state machine | 02 | 5 |
| `PF-053` | Balance read models | 02 | 5 |
| `PF-054` | `ledger:verify` | 02 | 5 |
| `PF-057` | Inter-entity postings | 02 | 8 |
| `PF-061` | Party bank details | 03 | 5 |
| `PF-068` | The FX rate model | 03 | 5 |
| `PF-071` | Numbering series | 03 | 5 |
| `PF-076` | Rate versioning | 04 | 5 |
| `PF-077` | The tax point date | 04 | 5 |
| `PF-078` | Place of supply | 04 | 8 |
| `PF-081` | The rounding policy | 04 | 5 |
| `PF-082` | Tax computation | 04 | 8 |
| `PF-086` | Return generation | 04 | 8 |
| `PF-088` | Tax account reconciliation | 04 | 5 |
| `PF-091` | Invoice schema with a frozen snapshot | 05 | 8 |
| `PF-093` | Gapless number allocation | 05 | 8 |
| `PF-094` | The gapless concurrency proof | 05 | 5 |
| `PF-095` | The issuing transaction | 05 | 8 |
| `PF-096` | The hash chain | 05 | 5 |
| `PF-106` | Recurring generation idempotency | 05 | 3 |
| `PF-107` | Revenue recognition | 05 | 8 |
| `PF-110` | The semantic invoice model | 06 | 8 |
| `PF-112` | The UBL 2.1 serializer | 06 | 8 |
| `PF-115` | Schematron compilation and vendoring | 06 | 5 |
| `PF-116` | EN 16931 semantic validation | 06 | 5 |
| `PF-118` | The jurisdiction profile interface | 06 | 5 |
| `PF-123` | The ZATCA profile | 06 | 8 |
| `PF-124` | ZATCA sealing and the clearance API | 06 | 8 |
| `PF-128` | The golden-file corpus | 06 | 8 |
| `PF-129` | Archiving | 06 | 8 |
| `PF-135` | Three-way match | 07 | 8 |
| `PF-136` | The approval engine | 07 | 8 |
| `PF-139` | Duplicate bill detection | 07 | 5 |
| `PF-146` | The GL mirror and three balances | 08 | 5 |
| `PF-147` | Statements as continuous objects | 08 | 5 |
| `PF-149` | Idempotent import | 08 | 5 |
| `PF-150` | The CAMT.053 parser | 08 | 8 |
| `PF-156` | Matching passes 1 and 2 | 08 | 8 |
| `PF-158` | Subset-sum matching | 08 | 8 |
| `PF-161` | Confidence and thresholds | 08 | 5 |
| `PF-163` | The suspense account | 08 | 5 |
| `PF-164` | Difference classification | 08 | 5 |
| `PF-165` | Reconciliation as a document | 08 | 8 |
| `PF-168` | Allocations | 09 | 8 |
| `PF-170` | Realized FX | 09 | 5 |
| `PF-172` | Write-offs | 09 | 5 |
| `PF-174` | The payment run | 09 | 8 |
| `PF-175` | Maker-checker and step-up | 09 | 5 |
| `PF-176` | PAIN.001 generation | 09 | 8 |
| `PF-179` | In-transit and the three-way tie | 09 | 5 |
| `PF-180` | Duplicate payment detection | 09 | 3 |
| `PF-182` | The reporting read-model architecture | 10 | 8 |
| `PF-186` | Statement of Cash Flows | 10 | 8 |
| `PF-189` | Universal drill-down | 10 | 5 |
| `PF-194` | Consolidation | 10 | 8 |
| `PF-195` | Inter-entity elimination | 10 | 5 |
| `PF-196` | The period close checklist | 10 | 5 |
| `PF-198` | Reproducibility | 10 | 3 |
| `PF-199` | The token architecture | 11 | 8 |
| `PF-200` | The contrast matrix | 11 | 5 |
| `PF-201` | Typography | 11 | 5 |
| `PF-205` | The data grid | 11 | 8 |
| `PF-206` | The grid keyboard model | 11 | 8 |
| `PF-209` | The invoice builder | 11 | 8 |
| `PF-210` | The reconciliation view | 11 | 8 |
| `PF-216` | RTL enforcement | 11 | 5 |
| `PF-217` | The OpenAPI contract | 12 | 5 |
| `PF-220` | Idempotency | 12 | 5 |
| `PF-222` | Money and dates on the wire | 12 | 3 |
| `PF-226` | Webhook events and signing | 12 | 5 |
| `PF-228` | The Exchange plane's reliability | 12 | 8 |
| `PF-231` | The ASVS verification pass | 13 | 8 |
| `PF-233` | Database privilege verification | 13 | 5 |
| `PF-234` | Secret management | 13 | 5 |
| `PF-235` | XML hardening | 13 | 3 |
| `PF-239` | Data protection | 13 | 5 |
| `PF-241` | Business metrics | 13 | 5 |
| `PF-242` | Invariant alerting | 13 | 5 |
| `PF-244` | Backup and disaster recovery | 13 | 5 |
| `PF-246` | Zero-downtime migration and rollback | 13 | 5 |
