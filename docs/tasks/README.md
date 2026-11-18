# The PeakFlow delivery backlog

**267 tasks across 16 phases.** This directory is the authoritative backlog. GitHub Issues mirror it - each issue carries the same `PF-###` id and links back to its phase file - but the file is the source of truth, because an issue tracker is a queue and a specification is a document.

## Contents

| Phase | Theme | Tasks | Points |
|-------|-------|-------|--------|
| [00](phase-00-foundations.md) | Foundations, tooling and guardrails | 17 | 60 |
| [01](phase-01-tenancy-identity.md) | Tenancy, entities, identity, segregation of duties | 19 | 84 |
| [02](phase-02-ledger.md) | The general ledger | 22 | 104 |
| [03](phase-03-masters.md) | Parties, catalog, currencies, FX | 16 | 66 |
| [04](phase-04-tax.md) | The tax engine | 14 | 71 |
| [05](phase-05-sales.md) | Quotes, orders, invoices, credit notes | 21 | 110 |
| [06](phase-06-einvoicing.md) | EN 16931, serializers, validation, jurisdiction profiles | 20 | 116 |
| [07](phase-07-purchasing.md) | Purchase orders, bills, approvals, three-way match | 15 | 67 |
| [08](phase-08-banking.md) | Bank accounts, statements, reconciliation | 21 | 109 |
| [09](phase-09-settlement.md) | Payment runs, files, direct debit, in-transit | 16 | 81 |
| [10](phase-10-reporting.md) | Reporting, close, consolidation | 17 | 87 |
| [11](phase-11-design-system.md) | Design system, data grid, accessibility, RTL | 20 | 107 |
| [12](phase-12-api-exchange.md) | REST API, webhooks, the Exchange plane | 14 | 57 |
| [13](phase-13-hardening.md) | Security, observability, performance, release | 16 | 77 |
| [14](phase-14-operations.md) | Stock, serials, returns, sync, payroll | 11 | 58 |
| [15](phase-15-verticals.md) | The industry verticals - **a scope decision** | 8 | 47 |
| | **Total** | **267** | **1,301** |

A flat, sortable view of every task is in [`backlog.md`](backlog.md).

## Progress

Counted from the checkboxes in the phase files. `php bin/verify repo` asserts that this table still agrees with them, because a progress table that has drifted is worse than no progress table: it is read instead of the files.

A criterion is ticked only when something in `bin/verify` fails if the behaviour is removed.

| Phase | Tasks complete | Criteria met | Criteria open |
|---|---|---|---|
| [00](phase-00-foundations.md) | 15 / 17 | 175 | 5 |
| [01](phase-01-tenancy-identity.md) | 15 / 19 | 84 | 4 |
| [02](phase-02-ledger.md) | 19 / 22 | 143 | 3 |
| [03](phase-03-masters.md) | 14 / 16 | 77 | 5 |
| [04](phase-04-tax.md) | 8 / 14 | 109 | 8 |
| [05](phase-05-sales.md) | 17 / 21 | 159 | 17 |
| [06](phase-06-einvoicing.md) | 6 / 20 | 101 | 43 |
| [07](phase-07-purchasing.md) | 8 / 15 | 152 | 7 |
| [08](phase-08-banking.md) | 21 / 21 | 124 | 0 |
| [09](phase-09-settlement.md) | 14 / 16 | 72 | 8 |
| [10](phase-10-reporting.md) | 0 / 17 | 0 | 76 |
| [11](phase-11-design-system.md) | 1 / 20 | 5 | 97 |
| [12](phase-12-api-exchange.md) | 0 / 14 | 0 | 64 |
| [13](phase-13-hardening.md) | 0 / 16 | 0 | 73 |
| [14](phase-14-operations.md) | 9 / 11 | 48 | 6 |
| [15](phase-15-verticals.md) | 0 / 8 | 0 | 36 |
| **all** | **147 / 267** | **1249** | **452** |

Phases 00 to 09 are the ones that have been worked, and Phase 08 is closed. An open criterion in a worked phase is one that depends on a later phase and says so in its own task file, with the task that brings it - never one that was skipped.

**Phases 14 and 15 came later than the rest.** They are the result of a gap analysis against [hawalaty](https://github.com/mostfaswaisi93/hawalaty-laravel), which runs real businesses and therefore knows what an invoicing core alone cannot do. Phase 14 is the part that belongs in a product whose subject is invoices and banks. Phase 15 is **four industry verticals**, and its own file opens by saying that starting it changes what the product is - recorded as a decision to take deliberately rather than a gap to close by default.

## Task format

```
### PF-042 · Partition journal_lines by period
`Ledger` · **5 pts** · depends: PF-041 · standards: —

One or two sentences of intent - what this is for, and the constraint that
makes it non-obvious.

- [ ] An acceptance criterion, written so it can only be true or false
- [ ] Another one
```

- **Module** is the bounded context that owns the work, and it is also the Deptrac layer the code must land in. `—` means the task is cross-cutting or infrastructural.
- **Estimate** is in points on a modified Fibonacci scale (1, 2, 3, 5, 8, 13). A point is roughly half a focused day. Anything estimated above 13 is not a task yet and must be split before it is started - the estimate is a signal about understanding, not a schedule.
- **depends** lists the tasks that must be *merged*, not merely started. Dependencies are the real plan; the phase numbers are a readable grouping on top of them.
- **standards** names what the task answers to, so the acceptance criteria can be checked against a published document rather than against an opinion.

## Definition of Ready

A task may be started only when all of the following are true. This list exists because the most expensive thing in financial software is building the right feature against the wrong rule:

- [ ] The acceptance criteria are each independently verifiable - no criterion reads "works correctly"
- [ ] Every dependency is merged
- [ ] Where a standard is named, the relevant clause has been read, not assumed, and the specific rule is quoted in the task or in the linked doc
- [ ] The data model change, if any, is reflected in [`../domain/schema.md`](../domain/schema.md) **before** the migration is written
- [ ] The failure mode is named: what goes wrong in production if this is subtly wrong, and which test layer would catch it
- [ ] If it touches money, the rounding behaviour is specified, not inferred
- [ ] If it touches the ledger, the posting rule is written out as debits and credits

## Definition of Done

- [ ] Code merged to `main` behind a feature flag where it changes existing behaviour
- [ ] Every acceptance criterion demonstrably met
- [ ] Tests at the layers the [testing strategy](../operations/testing-strategy.md) prescribes for that kind of change - and for anything in `Ledger`, `Tax` or `Settlement`, the mutation-score gate passes
- [ ] `ledger:verify` passes at the end of any feature test that posts
- [ ] PHPStan at `max`, Pint clean, Deptrac clean, no new baseline entries
- [ ] Accessibility: automated checks pass, and new interactive UI is operated once by keyboard only
- [ ] Both `ar` and `en` catalogs complete; no key missing in either
- [ ] Documentation updated **in the same pull request** - schema, ADR, standards mapping or design doc as applicable
- [ ] An ADR exists for any decision that was not obvious, including the alternatives and why they lost
- [ ] Observability: anything that can fail silently emits a metric or an alert

## How the phases are ordered

Ordering is by dependency and by **cost of being wrong late**, not by what demos well.

1. **Guardrails before features** (Phase 00). The architecture tests that forbid a `float` in the money path and require `entity_id` on a financial table are worth almost nothing applied to 200 existing tables and almost everything applied to zero.
2. **The ledger before anything that posts** (Phase 02). An invoice is a document plus a posting. Building the document first produces a system where invoices exist and the books do not reconcile, and the fix is a backfill against data a customer has already filed tax on.
3. **Tax before sales** (Phase 04 before 05). The tax point date, the rounding policy and the per-rate-group breakdown are *inside* an issued document and frozen there. Retrofitting them is a migration over immutable rows, which is to say it is not possible.
4. **The semantic invoice model before any jurisdiction** (Phase 06). Writing one country's XML first and generalizing later is how a product acquires a second invoice system.
5. **Banking after sales and purchasing** (Phase 08). Reconciliation needs something to reconcile *to*. Building the matching engine first means testing it against fixtures rather than against real receivables.
6. **Reporting last among the domains** (Phase 10), because a report is a query over the journal and the journal must be complete and trustworthy first. Reports built early become a second source of truth that then has to be unbuilt.
7. **The design system is split** (Phase 11), and this is the one deliberate exception to dependency ordering. The shell, the token architecture and the data grid are scheduled in Phase 00 and Phase 11 respectively: a usable shell exists immediately, because eleven phases of screens built before a design system produces eleven design systems - but the full systematic pass, the density modes, the contrast matrix and the print stylesheets land once the screens they govern exist and can be audited together.
8. **Hardening is continuous, and its phase is a verification gate, not the first time security is considered** (Phase 13). Every phase carries its own security acceptance criteria; Phase 13 is where the ASVS pass, the invariant alerting and the restore drill are *proven*.

## The GitHub mirror

All 246 tasks exist as issues, each attached as a **sub-issue** of its phase's tracking issue, so the hierarchy is navigable in GitHub's own UI.

| | |
|---|---|
| **Phase epics** | Issues [#1](https://github.com/mostfaswaisi93/peakflow/issues/1)–[#14](https://github.com/mostfaswaisi93/peakflow/issues/14), one per phase, each labelled `type:epic` |
| **Tasks** | Issues #15–#260 — `PF-NNN` maps to issue **NNN + 14**, so `PF-043` is [#57](https://github.com/mostfaswaisi93/peakflow/issues/57) and `PF-246` is [#260](https://github.com/mostfaswaisi93/peakflow/issues/260) |
| **Grouping** | The `phase:NN` label, plus the parent/sub-issue relationship |

An issue carries the task's module, estimate, dependencies, standards and its intent, and links back to its phase file. **The phase file remains the authoritative text** — an issue tracker is a queue and a specification is a document, and when they disagree the file is right.

### On GitHub Milestones

The seven **delivery milestones** (M1–M7) are defined in [`../roadmap.md`](../roadmap.md) and named on each phase epic. They are not yet GitHub Milestone objects: creating those is a one-minute action in the repository's Issues → Milestones tab, after which every task can be assigned to one in bulk.

The phase labels and the epic hierarchy already provide the grouping that matters day to day, so this is a convenience rather than a gap.

## Labels used on GitHub

| Label | Meaning |
|-------|---------|
| `phase:00` … `phase:13` | The phase, for grouping and for the milestone mirror |
| `module:ledger`, `module:sales`, … | The owning bounded context |
| `type:schema` | Changes the database schema; requires a `schema.md` update in the same PR |
| `type:posting` | Changes or adds a posting rule; requires debits and credits in the description |
| `type:compliance` | Answers to a published standard; requires the clause to be quoted |
| `type:security` | Touches authentication, authorization, SoD, secrets or crypto |
| `type:a11y` | Has an accessibility acceptance criterion |
| `risk:high` | Being subtly wrong here costs money or breaks an audit. Needs a second reviewer |
| `needs:adr` | Cannot be closed without an architecture decision record |
| `blocked` | A dependency is not merged, or an external answer is pending |

> **96 of the 246 tasks carry `risk:high`**, which is 39% and is not label inflation - it is a description of the product. In a system whose job is to be correct about money, a majority of the core domain work is work where a subtle error is expensive and silent. The label earns its keep by naming which tasks need a second reviewer; the ten tasks below are the ones where being wrong is worst, and they are called out separately because a list of 96 is not a priority.

## Risk register

The tasks that carry `risk:high` are collected here because they deserve attention before they are reached, not when they are started:

| Task | Risk if wrong | Mitigation in the plan |
|------|---------------|------------------------|
| `PF-043` Balanced-entry constraint trigger | An unbalanced entry reaches the database and every report is wrong from that date | Deferred constraint trigger plus a property-based test plus `ledger:verify` in every posting test |
| `PF-093` Gapless numbering | A gap in a legal sequence is a finding in a tax audit | Dedicated concurrency suite, `PF-094`, running 20 parallel issuers |
| `PF-081` Rounding policy | Per-document tax differs from the filed return by cents, across thousands of documents | Policy is data, not code; golden files from official corpora; `BR-CO-14` asserted |
| `PF-124` ZATCA cryptographic stamp | A rejected or invalid stamp means invoices cannot legally be issued | Key in KMS, never in the database; staging against the sandbox before any tenant is enabled |
| `PF-061` Party bank details | Beneficiary substitution is the highest-value fraud in the product | Column encryption, change audit, re-verification before next payment, SoD pair with `payment.release` |
| `PF-175` Maker-checker on release | One compromised account can empty an operating bank account | SoD enforced against the audit trail, plus step-up authentication at the act |
| `PF-026` Entity scoping + RLS | Cross-company data leakage inside one tenant | Application scope *and* row-level security, plus an architecture test requiring `entity_id` |
| `PF-164` Difference write-off tolerances | A generous tolerance quietly absorbs real losses | Tenant setting with a platform-enforced ceiling, every write-off posted and classified |
| `PF-182` Reporting read models | A read model that drifts from the journal becomes a second source of truth | Rebuildable from the journal by definition, nightly drift check that reports rather than repairs |
| `PF-233` Database role privileges | An ORM bug or a console session mutates posted history | Privileges revoked at the role level; the app does not connect as the migrator |
