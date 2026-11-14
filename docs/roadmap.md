# Roadmap

The [backlog](tasks/) is the plan at task resolution. This is the plan at the resolution of *what becomes possible when*.

Phase ordering is driven by dependency and by **cost of being wrong late**, not by what demos well. The guiding question at every step is: what will be impossible to change once there is customer data behind it?

## Milestones

### M1 — The ledger stands on its own
**Phases 00-02 · 58 tasks · 248 points**

A tenant can be provisioned, users can sign in with passkeys into a specific legal entity, and a balanced journal entry can be posted and proven. There is no invoice, no bank, no customer - and that is the point. At the end of M1, `ledger:verify` passes against a seeded entity, a period can be closed and reopened with a full audit trail, and an unbalanced entry cannot reach the database by any route including raw SQL.

**Why this first:** everything after M1 posts into it. A system where invoices exist and the books do not reconcile is fixed by a backfill against data a customer has already filed tax on.

**Proves:** the deferred constraint trigger, entity isolation under row-level security, segregation of duties against the audit trail, and the posting-rule abstraction that lets `Ledger` know nothing about invoices.

### M2 — The first legally valid invoice
**Phases 03-05 · 51 tasks · 247 points**

Master data exists, the tax engine computes a per-rate-group breakdown that satisfies `BR-CO-14`, and an invoice can be issued: numbered gaplessly, with its party, rates and FX frozen, hash-chained, posted, rendered as a verified PDF/A, and delivered.

**Why tax before sales:** the tax point date, the rate version and the breakdown are *inside* the issued document and frozen there. Retrofitting them means migrating immutable rows, which is to say it is not possible.

**Proves:** `PF-094`, the concurrency test issuing 200 invoices from 20 parallel workers and asserting the numbers are exactly `1..200`. This is the single most important test in the suite.

### M3 — Compliant in a real jurisdiction
**Phase 06 · 20 tasks · 116 points**

The semantic model serializes to UBL 2.1 and CII, passes the four-stage validation chain against the official corpora, and clears through a real authority. Peppol BIS and ZATCA both work, which is the proof that the semantic layer was worth building.

**Why the semantic model before any one country:** writing one country's XML first and generalizing later is how a product acquires a second invoice system. Adding a country must be a profile class plus fixtures, and that claim is tested by measuring the diff surface of adding one.

**Proves:** two profiles that agree about meaning and disagree about almost everything else, over one model.

### M4 — The money closes the loop
**Phases 07-09 · 52 tasks · 257 points**

Bills arrive, get approved through maker-checker, and get paid. Statements import, reconcile through the matching engine, and tie to the ledger. Payments allocate, realize FX, and settle. At the end of M4, the three-way tie between accounts payable, the bank and the general ledger holds and is asserted by `ledger:verify`.

**Why banking after sales and purchasing:** reconciliation needs something to reconcile *to*. The matching engine's whole value is how it behaves against real receivables, not against fixtures.

**Proves:** maker-checker with step-up authentication on release, beneficiary-change blocking, duplicate-payment detection across runs and entities, and statement continuity refusing to import a hole.

### M5 — A closeable, reportable, auditable period
**Phase 10 · 17 tasks · 87 points**

IFRS statements, universal drill-down to the journal line and the source document, consolidation with an elimination proof, and a close checklist that reports every blocking item at once.

**Why reporting last among the domains:** a report is a query over the journal, and reports built early become a second source of truth that then has to be unbuilt.

**Proves:** cash flows computed both indirectly and directly, agreeing - the independent check that the ledger is complete.

### M6 — A product people can work in all day
**Phase 11 · 18 tasks · 99 points**

The systematic design pass: the full token architecture with a contrast matrix, three density modes, the keyboard-first virtualized data grid, the invoice builder with server-authoritative tax, the reconciliation split view with keyboard parity for every drag, and print stylesheets for everything.

**Why the design system is split rather than first or last:** the shell, tokens and dark-mode variant land in Phase 00 (`PF-017`), because eleven phases of screens built before a design system produces eleven design systems. The systematic pass lands here because it can only audit screens that exist.

**Proves:** a full multi-row grid edit performed with the keyboard alone, and a reconciliation completed without a mouse.

### M7 — Integrable and operable
**Phases 12-13 · 30 tasks · 134 points**

A spec-first API with a two-way contract test, mandatory idempotency on ledger writes, signed webhooks, and an `Exchange` plane where a government outage is a queue depth. Then the verification gate: the ASVS pass, invariant alerting, a measured restore drill, and the release machinery.

**Why hardening is a gate and not an afterthought:** every preceding phase carries its own security acceptance criteria. What happens here is that they are *proven*, and that the alerting which watches them is built - because alerting on invariants cannot be retrofitted cheaply.

**Proves:** a game-day exercise that injects ledger drift, a hash-chain divergence and a subledger mismatch in staging, and verifies each alert fires and each runbook works.

## The sequencing rules, stated once

| Rule | Consequence of breaking it |
|------|----------------------------|
| Guardrails before features | An architecture test is worth almost nothing applied to 200 existing tables |
| The ledger before anything that posts | Invoices exist, books do not reconcile, fix is a backfill over filed data |
| Tax before sales | The tax treatment is frozen inside issued documents; retrofitting is impossible |
| The semantic model before any jurisdiction | A second country becomes a second invoice system |
| Banking after what it reconciles to | A matching engine tuned on fixtures behaves differently on real data |
| Reporting after the journal is trustworthy | Reports become a competing source of truth |
| A shell early, the design system systematically later | Eleven phases of screens, eleven design systems |
| Security in every phase, proven in one | Controls that were never verified are assumptions |

## What is deliberately not on this roadmap

Saying so now is what keeps the scope honest. Each of these is a reasonable product; none of them is this one.

- **Point of sale.** No cash drawer, no shift, no till. PeakFlow consumes POS output as a sales journal.
- **Inventory and costing.** Items are billable lines with a tax treatment. No stock ledger, no valuation, no batches. Cost of sales is imported, not derived.
- **Payroll calculation.** Salaries arrive as a journal import or a bill. Payroll tax tables are a jurisdiction minefield.
- **Holding or moving funds.** PeakFlow reads statements and emits instruction files. A human or a bank's own channel moves money, and the UI says so.
- **Fixed-asset registers, leases (IFRS 16) and consolidation of non-controlled entities.** All plausible later; none load-bearing for the loop above.
- **A single-page front end.** Considered and rejected in [`ADR-0006`](architecture/decisions/ADR-0006-server-driven-ui.md): a client-side tax engine is a second implementation of the rules, and it will disagree.
