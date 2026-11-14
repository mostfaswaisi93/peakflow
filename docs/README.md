# PeakFlow documentation

This directory is the specification. It was written before the code, and it is the contract the code is written against.

Start with the [root README](../README.md) for the whole system in one pass. Come here for the parts that need more than a section.

## Architecture

| Document | Answers |
|----------|---------|
| [`architecture/overview.md`](architecture/overview.md) | The three planes, the twelve modules, how they communicate, and what is deliberately not allowed |
| [`architecture/multi-tenancy.md`](architecture/multi-tenancy.md) | Provisioning, connection resolution, entity scoping, migrating thousands of databases |
| [`architecture/money-and-precision.md`](architecture/money-and-precision.md) | The three decimal scales, where rounding happens, and the currency cases that break naive implementations |
| [`architecture/decisions/`](architecture/decisions/) | Architecture decision records - the choices, their alternatives, and why the alternatives lost |

## Domain

| Document | Answers |
|----------|---------|
| [`domain/general-ledger.md`](domain/general-ledger.md) | Accounts, journals, every posting rule written as debits and credits, periods and the close |
| [`domain/invoicing.md`](domain/invoicing.md) | The document lifecycle, gapless numbering, the hash chain, recognition, dunning |
| [`domain/banking.md`](domain/banking.md) | Statement formats, the matching engine pass by pass, payment initiation |
| [`domain/tax-engine.md`](domain/tax-engine.md) | Tax point, place of supply, reverse charge, withholding, returns |
| [`domain/schema.md`](domain/schema.md) | Every table, its columns, its constraints and its indexes |
| [`domain/glossary.md`](domain/glossary.md) | The bilingual domain vocabulary, AR/EN, with the standard each term comes from |

## Standards

| Document | Answers |
|----------|---------|
| [`standards/README.md`](standards/README.md) | The register: every standard, its version, and what conformance means here |
| [`standards/en16931-mapping.md`](standards/en16931-mapping.md) | Business term by business term, what PeakFlow populates it from |
| [`standards/iso20022-mapping.md`](standards/iso20022-mapping.md) | CAMT and PAIN element mapping |
| [`standards/compliance-matrix.md`](standards/compliance-matrix.md) | Requirement → control → test → evidence |

## Design

| Document | Answers |
|----------|---------|
| [`design/design-system.md`](design/design-system.md) | Tokens, colour, typography, density, components |
| [`design/interaction-and-motion.md`](design/interaction-and-motion.md) | The data grid, the keyboard model, state coverage, motion |
| [`design/rtl-and-i18n.md`](design/rtl-and-i18n.md) | Arabic-first rules, bidi isolation, ICU, digit shaping |
| [`design/accessibility.md`](design/accessibility.md) | The WCAG 2.2 AA conformance plan and how it is tested |

## API

| Document | Answers |
|----------|---------|
| [`api/conventions.md`](api/conventions.md) | Errors, idempotency, pagination, money on the wire, versioning |
| [`api/webhooks.md`](api/webhooks.md) | The event catalogue, signing, retries, replay |
| [`api/openapi.yaml`](api/openapi.yaml) | The machine-readable contract |

## Operations

| Document | Answers |
|----------|---------|
| [`operations/testing-strategy.md`](operations/testing-strategy.md) | Every test layer, what it is for, and what it cannot catch |
| [`operations/security-hardening.md`](operations/security-hardening.md) | Threat model, abuse cases, controls, ASVS mapping |
| [`operations/observability.md`](operations/observability.md) | Traces, business metrics, and the invariant alerts |
| [`operations/backup-and-dr.md`](operations/backup-and-dr.md) | Per-tenant point-in-time recovery, RPO/RTO, restore drills |
| [`operations/ci-cd.md`](operations/ci-cd.md) | The pipeline, why it is currently disabled, and how to enable it |

## Delivery

| Document | Answers |
|----------|---------|
| [`tasks/README.md`](tasks/README.md) | Task format, Definition of Ready and Done, labels, the risk register |
| [`tasks/backlog.md`](tasks/backlog.md) | All 246 tasks in one sortable table |
| [`tasks/phase-00-foundations.md`](tasks/phase-00-foundations.md) … [`phase-13`](tasks/phase-13-hardening.md) | The phases, with full acceptance criteria |
| [`roadmap.md`](roadmap.md) | Sequencing, milestones and what each one makes possible |

## How to read a document here

Three conventions run through all of them, and they are what makes this a specification rather than a wiki:

1. **Every claim names its reason.** A rule without a reason is a preference, and the next person will change it. Where a design is non-obvious, the document says what breaks under the alternative.
2. **Every standard is cited precisely.** Not "per EN 16931" but `BR-CO-14`, with its text. A requirement you cannot check against a published clause is a requirement you will argue about.
3. **Known traps are written down where they bite, not in a lessons-learned file.** Several of them are inherited from a sibling project where they shipped: a Tailwind v4 `dark:` variant that silently did nothing, a `<main>` that could not take focus, a build that dropped 18 kB of utilities when run after a cache clear. They are recorded next to the thing they affect, because that is the only place anyone reads them.
