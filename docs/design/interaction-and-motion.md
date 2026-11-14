# Interaction and motion

The three screens that decide whether this product is usable: the **data grid**, the **invoice builder**, and the **reconciliation split view**. Everything else is forms and tables.

## The principle that governs all of it

> **Optimistic UI only where the server cannot disagree.**
>
> Renaming a saved view is optimistic — the server will accept it. **Posting a journal entry is not.** An optimistically "posted" entry that the server then refuses is a lie about money, and in a financial product that is a credibility failure rather than a bug report.

This is why the stack is server-driven (see [`ADR-0006`](../architecture/decisions/ADR-0006-server-driven-ui.md)): a client-side tax engine is a second implementation of place-of-supply rules, per-rate-group accumulation and jurisdiction rounding, **and it will disagree**.

| Interaction | Optimistic? |
|-------------|-------------|
| Column resize, reorder, pin, density, saved-view rename | Yes — local state |
| Filter, sort, page | No — the server owns the result set |
| Cell edit on a draft | Yes, with rollback on refusal |
| Totals and tax on a draft | **No** — pending state, then the server's answer |
| Posting, issuing, releasing, reconciling | **No** — never |

## The data grid

### Keyboard model

Finance users touch-type numbers. **A mouse round trip per cell is the difference between usable and abandoned.**

| Key | Action |
|-----|--------|
| Arrows | Move one cell |
| `Home` / `End` | First / last cell in the row |
| `Ctrl` + arrows | To the bound in that direction |
| `PageUp` / `PageDown` | One viewport |
| `Shift` + arrows | Extend the selection range |
| `Ctrl` + `C` | Copy the range **as TSV**, so it pastes into a spreadsheet correctly |
| `Ctrl` + `V` | Paste into a range — **with a preview of what will change and a count, before it commits** |
| *any character* | **Type-to-edit** — begins editing the focused cell |
| `Enter` | Commit and move **down** |
| `Tab` | Commit and move **right** |
| `Esc` | Revert the cell |
| `Ctrl` + `Z` | Revert the edit |
| `Space` | Toggle row selection |

**Every mouse-only affordance has a keyboard equivalent**, and a browser test performs a full multi-row edit with the keyboard alone.

### Performance and state

- Row virtualization at 50,000 rows without degrading scroll
- Sticky headers; frozen leading columns; pinning, resizing, reordering, visibility
- **Server-side** sort, filter and cursor pagination — the grid never holds a full dataset
- Grouping with subtotals; a footer with aggregates **from the server**, not from the loaded page
- **Saved views** per user — columns, widths, sort, filters, density — shareable within the tenant
- A query-budget test: the grid costs the **same number of queries at 5 rows as at 500**

### Accessibility semantics

| Requirement | Detail |
|-------------|--------|
| `role="grid"` | With correct `gridcell`, `row`, `columnheader` structure |
| **`aria-rowcount`** | **The total, not the rendered window.** A virtualized grid reporting 50 rows when there are 12,480 tells a screen-reader user something false |
| `aria-sort` | On sorted headers |
| Live region | "Showing 1–50 of 12,480", and the result of a filter change |
| Cell accessible name | **Includes its column** — a bare "1,234.56" read alone is meaningless |
| Verification | An actual screen reader once per release, with the result recorded |

## The invoice builder

- A line grid with the full keyboard model, item autocomplete, per-line tax, discount and dimension entry
- **Totals recalculate on the server and only on the server**, with a visible pending state. Never optimistically rendered
- A **live validation panel** showing exactly what prevents issuing, addressed by field — not a failure at the end
- The tax panel shows the **per-rate-group breakdown and the place-of-supply decision with its reasoning**, so an accountant can check it
- Draft autosave with conflict detection
- **The issue action is never reachable by `Enter` in a text field.** Issuing is irreversible; it requires a deliberate act

## The reconciliation split view

- Two panes — bank transactions, open documents — with a **running difference that is always visible**
- Drag to match, drag to split
- **A keyboard equivalent for every drag**: select, mark, apply. A drag-only interface excludes keyboard and screen-reader users from the product's core workflow, which is not an accessibility nicety but an exclusion from the job
- Proposed matches shown with **confidence and a readable reason**, acceptable or rejectable in bulk
- A match applies atomically, with an undo that **reverses the posting properly** rather than deleting it
- The running difference and each applied match are announced through a live region

## The command palette

`Ctrl` / `⌘` + `K` over documents, parties, accounts, reports and actions.

- Scoped results with section headings, recent items, **permission filtering applied in the query** rather than after
- Arrow navigation, `Enter` to activate, `Esc` to dismiss **with focus restored to where it was**
- Debounced, cancellable requests — a slow search never blocks typing
- Searching by phone number, tax registration, invoice number or amount all work, because those are what people have to hand

## State coverage

Designed states for every screen, not left to the framework.

| State | Requirement |
|-------|-------------|
| Empty | **Three distinguishable cases**: there is no data, the filter excluded everything, or the request failed. A spinner-then-blank cannot tell them apart, and each needs a different action |
| Loading | Skeletons that **match the final layout**, so nothing reflows on arrival |
| Error | Names what failed and offers a retry **that does not lose entered data** |
| Partial failure | Shows what loaded and what did not, rather than failing the whole page |
| Offline | A banner, with queued local state where it is safe and a clear statement where it is not |
| Permission denied | Says the record exists and access is lacking — not a blank, which reads as a bug |
| Stale | After a conflict: what changed, who changed it, and the choice to reload or override |

A test enumerates the application's routes and asserts each has a declared empty and error state.

## Motion

| Property | Value |
|----------|-------|
| Duration | 120–240ms, as tokens |
| Easing | `cubic-bezier(0.2, 0, 0, 1)` |
| Maximum | Nothing over 500ms; nothing that loops |
| Reduced motion | **Every transition wrapped in `@media (prefers-reduced-motion: reduce)`, and the reduced path is tested rather than assumed** |
| View transitions | List→detail where supported, degrading to no animation |
| In the grid | **Nothing animates during scroll or edit.** Motion in a table being read is noise |

Motion earns its place by explaining a relationship — where a panel came from, what a row became. A transition that only decorates is removed.
