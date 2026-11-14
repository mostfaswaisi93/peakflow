# Architecture decision records

An ADR records a decision that was not obvious, **the alternatives that were considered, and why they lost**. The last part is the point. A document that lists only the chosen option explains nothing a reader could not infer from the code, and in two years the person asking "why didn't they just…" is usually the person who wrote it.

## Format

```markdown
# ADR-NNNN — Title

- **Status:** Proposed | Accepted | Superseded by ADR-NNNN
- **Date:** YYYY-MM-DD
- **Deciders:** who
- **Tasks:** PF-###

## Context
The forces at play. What makes this a decision rather than a default.

## Decision
What was chosen, stated so it can be checked against the code.

## Alternatives considered
Each one, with what it would have given us and the specific reason it lost.

## Consequences
What this makes easy, what it makes hard, and what it rules out.

## Revisit when
The condition that should make someone reopen this.
```

## When an ADR is required

- Any task labelled `needs:adr`.
- Any choice between two defensible technologies or patterns.
- Any deliberate departure from a framework convention.
- Any decision whose cost is paid later - a schema shape, a boundary, an invariant's enforcement point.
- Any time a reviewer asks "why this way" and the answer takes more than two sentences.

## The records

| # | Decision | Status |
|---|----------|--------|
| [0001](ADR-0001-modular-monolith.md) | A modular monolith with compiled boundaries | Accepted |
| [0002](ADR-0002-postgresql.md) | PostgreSQL rather than MySQL | Accepted |
| [0003](ADR-0003-tenancy-model.md) | Database per tenant, entity scoping inside it | Accepted |
| [0004](ADR-0004-money-representation.md) | `numeric` with three scales, not minor-unit integers | Accepted |
| [0005](ADR-0005-ledger-invariants-in-database.md) | Ledger invariants enforced in PostgreSQL | Accepted |
| [0006](ADR-0006-server-driven-ui.md) | Server-driven UI rather than a single-page application | Accepted |
| [0007](ADR-0007-semantic-first-einvoicing.md) | One semantic model, many syntaxes | Accepted |
| [0008](ADR-0008-signed-amount.md) | A single signed amount, not debit and credit columns | Accepted |
| [0009](ADR-0009-gapless-numbering.md) | Row-lock allocation, not a database sequence | Accepted |
| [0010](ADR-0010-no-ci-during-specification.md) | No CI workflow during the specification phase | Accepted |
