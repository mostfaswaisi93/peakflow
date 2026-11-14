# ADR-0001 — A modular monolith with compiled boundaries

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-003, PF-005, PF-048

## Context

PeakFlow has twelve clearly separable concerns and will be worked on by a small team for a long time. The two obvious shapes are a set of services with network boundaries, and a single application with module boundaries. The choice determines what kind of bug is possible.

The decisive constraint is transactional: **issuing an invoice must allocate a gapless number, freeze its totals, write a hash-chain link and post a balanced journal entry, atomically.** If any of those can succeed while another fails, the system has a state nobody can describe - an invoice with no posting, a consumed number with no invoice, a chain with a hole.

## Decision

One deployable. Twelve modules under `app/Tenant`, `app/Platform`, `app/Exchange` and `app/Support`, with the dependency graph declared in `deptrac.yaml` and **enforced in the pipeline**.

Modules communicate by domain events and application services. A module never touches another module's Eloquent models - enforced by Deptrac and by an architecture test on namespace references.

Posting listeners run **synchronously, inside the originating transaction**. Everything else that reacts to the same event is queued, and a failure there can never roll back the posting.

## Alternatives considered

**Microservices, with a ledger service.** Would give independent scaling and independent deploys. It loses on the transactional constraint above: the invoice and its posting would be in different databases, requiring a saga or an outbox with compensating actions. A compensating action for "the posting failed after the number was consumed" is a gap in a legal sequence. A ledger that is eventually consistent with its source documents is not a ledger - and the operational cost of distributed tracing, versioned contracts and partial-failure handling would be paid on every feature.

**A single application with no enforced boundaries.** The default, and cheapest on day one. It loses because `Ledger` must not know what an invoice is, and nothing except a build failure keeps that true. Every codebase that relies on discipline for layering has the same archaeology: one import that seemed harmless, then forty.

**Separating only `Exchange` into a service.** Genuinely tempting - its traffic is asynchronous, slow and failure-prone. Rejected for now because it shares the semantic invoice model and the archive with `Sales`, and a version skew between them would produce documents that validate in one place and not the other. It remains the most likely first extraction if it is ever needed, which is why it already has its own plane and its own queues.

## Consequences

**Easy:** atomic writes across modules; one test suite; one deploy; refactoring across a boundary is a compiler-checked operation rather than a migration.

**Hard:** the whole application scales as one unit; a slow report competes with a user's save until read replicas are introduced; module boundaries must be maintained deliberately, because the language will not stop you.

**Ruled out:** independent deployment of a module, and per-module technology choices.

## Revisit when

A single module's resource profile diverges enough to matter - most likely `Exchange` under heavy clearance volume, or `Reporting` under consolidation load. The boundary is already drawn, so extraction would be a deployment change rather than a redesign. Do not revisit because of team size; revisit because of a measured resource conflict.
