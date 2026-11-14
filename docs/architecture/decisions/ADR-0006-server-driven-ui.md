# ADR-0006 — Server-driven UI rather than a single-page application

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-205, PF-206, PF-209

## Context

The brief asks for a modern, interactive, distinctive interface. The hard parts of a financial UI are specific: a 50,000-row data grid with keyboard editing, a reconciliation split view with drag-to-match, and an invoice builder whose tax recalculates as the user types.

The reflex answer in 2026 is an SPA - React or Vue over an API. The decision is whether that serves these three screens better than a server-driven approach.

## Decision

**Livewire 3 + Alpine.js 3 + Tailwind CSS 4**, server-rendered with targeted client-side interactivity. No SPA framework.

The deciding case is the invoice builder:

> **Tax and totals are computed on the server and only on the server.** A client-side tax engine is a second implementation of place-of-supply rules, per-rate-group accumulation, jurisdiction rounding policy and effective-dated rates - and it will disagree with the first one. When it disagrees, the user sees a total that changes when they press save, which in a financial product is a credibility failure rather than a bug report.

The same logic applies to anything that posts. The grid, the palette and the split view are built with Alpine where client-side state is genuinely local (focus, selection, column widths) and Livewire where the server's answer is the only answer.

## Alternatives considered

**React or Vue over the REST API.** Gives the richest interaction model and a clean client/server split. It loses on duplication of financial logic: either the client recomputes tax (a second implementation) or it round-trips for every keystroke (which is what Livewire already does, with less code). It also doubles the surface that must be internationalized, RTL-tested and accessibility-tested, and the API would be driven by screen needs rather than by integration needs - which tends to produce an API that is awkward for its actual consumers.

**Inertia.js with Vue.** A genuinely strong middle option: server-side routing and authorization, client-side rendering, no separate API. It loses narrowly, on two points. The invoice builder still needs server-authoritative totals, so the Vue component would be a form that waits for the server - which is Livewire with a build step. And a 50,000-row virtualized grid is a hand-written component in either case, so Inertia's advantage does not apply where the work actually is.

**Blade with Alpine only, no Livewire.** What the sibling project does, and it works. It loses on the invoice builder and the reconciliation view, both of which need frequent server round trips with partial re-rendering, and hand-rolling that over `fetch` and `innerHTML` is reinventing Livewire badly.

**Optimistic UI everywhere.** Rejected as a pattern rather than a framework: renaming a saved view is optimistic, because the server cannot meaningfully disagree. **Posting a journal entry is not.** An optimistically "posted" entry that the server then refuses is a lie about money. The rule is written into the design system: optimistic only where the server cannot disagree.

## Consequences

**Easy:** one implementation of every financial rule; one place to internationalize; one accessibility surface; server-side authorization on every interaction without a parallel client-side guard; no build step for most UI work.

**Hard:** latency is visible, so slow server work shows as a slow UI - which pushes query budgets and partition pruning from "good practice" to "required", and they are tested as such; genuinely offline-capable screens are not available, which is accepted because this is a back-office product used at a desk; some interactions need hand-written Alpine components that an SPA ecosystem would have provided.

**Ruled out:** a mobile app sharing the web front end. A mobile client would consume the REST API, which is specified independently and not shaped by screen needs - arguably a benefit of this decision.

## Revisit when

A screen is identified whose interaction genuinely cannot work with a server round trip and whose logic is not financial. None has been found: the grid, the palette, the split view and the builder are all specified and all work. Revisit on a measured interaction-latency failure, not on a preference.
