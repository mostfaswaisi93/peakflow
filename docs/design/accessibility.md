# Accessibility

**WCAG 2.2 level AA is the floor, and it is tested rather than asserted.**

The reason it is tested is specific: a control with no accessible name **renders perfectly**. It passes every functional test, every visual review and every demo. It is noticed only by someone using a screen reader, and by then it is in production. The same is true of a 1.62:1 contrast ratio, a `<main>` that cannot take focus, and a virtualized grid that reports the wrong row count.

So the guards below are build failures, not review checklist items.

## Conformance target

| Standard | Level | Note |
|----------|-------|------|
| WCAG 2.2 | **AA** | Including the 2.2 additions: 2.4.11 focus not obscured, 2.5.7 dragging movements, 2.5.8 target size, 3.3.7 redundant entry, 3.3.8 accessible authentication |
| WAI-ARIA | 1.2 | Grid, dialog, live region, combobox patterns |
| EN 301 549 | 3.2 | Satisfied via WCAG 2.2 AA |

AAA is not claimed. Where AAA is cheap it is taken — 7:1 contrast on body text, for instance — but it is not a target, and claiming it without testing it would be worse than not claiming it.

## The template rules, enforced by an architecture test

| Rule | Why |
|------|-----|
| **A `<label>` must name its control** — via `for`/`id`, by wrapping it, or through `aria-labelledby` | A bare `<label>` beside an input names nothing. It looks identical and announces nothing |
| **A placeholder is not a name** | Filter inputs and selects carry `aria-label`. A placeholder disappears on focus, is not announced by every assistive technology, and fails 1.4.3 contrast at typical styling |
| **Every `for` matches exactly one `id`** | A `for` pointing at nothing is a label that names nothing |
| **No `id` is duplicated per render** | A component used twice on one page derives its ids from an instance key. **A duplicate id is worse than none**: the label points at whichever copy the browser saw first, so one control is mislabelled and the other is unlabelled |
| **No `id` written outside a tag** | Catches a malformed attribute that silently does nothing |
| **Every shell starts with a skip link** | All three shells put navigation ahead of content; reaching it by keyboard otherwise means tabbing past ~30 controls on every load |
| **`<main>` carries `tabindex="-1"`** | See below |
| **A dialog has `aria-labelledby`** and moves focus to its first field | |
| **No colour-only state** | A status pill carries colour, an icon and a word |

> ### `<main tabindex="-1">` is not decoration
>
> **A `<main>` element is not focusable by default.** Without `tabindex="-1"`, a skip link scrolls the viewport to the target and **leaves focus where it was** — so the next `Tab` returns to the navigation the user just skipped.
>
> A skip link that only moves the viewport is **worse than none**, because it looks like it worked. This is inherited as a known trap from a sibling project.

## Keyboard

The product's core workflows must be operable without a mouse — not as a concession, but because a drag-only reconciliation excludes keyboard and screen-reader users from the job the product exists to do.

| Requirement | Detail |
|-------------|--------|
| **Keyboard parity for every drag** (2.5.7) | The reconciliation view's drag-to-match has a select-mark-apply equivalent. A browser test completes a reconciliation with the keyboard alone |
| **The data grid is fully operable** | Arrows, range selection, type-to-edit, `Enter`/`Tab` to commit, `Esc` to revert. A browser test performs a multi-row edit with the keyboard alone |
| **Focus is never lost** | A dialog traps focus and restores it on close. **A row deleted from a grid moves focus to its neighbour**, not to `<body>` |
| **Focus is never obscured** (2.4.11) | Sticky headers and footers are accounted for; a focused cell is scrolled into a clear region, not under a frozen header |
| **Focus is always visible** | A 3:1 indicator against both the component and the adjacent background, tested as its own token pair |
| **No keyboard trap** | Every container can be left by keyboard |
| **Shortcuts are discoverable and remappable** | A shortcut list, and no single-character shortcut without a modifier that cannot be disabled (2.1.4) |

## The data grid, specifically

A virtualized grid is the easiest place to lie to assistive technology.

| Requirement | Detail |
|-------------|--------|
| `role="grid"` with correct structure | `row`, `columnheader`, `gridcell` |
| **`aria-rowcount` is the total** | Not the rendered window. Reporting 50 when there are 12,480 is a false statement, and it makes the grid unnavigable |
| `aria-rowindex` on each row | The **absolute** index, so position is meaningful |
| `aria-sort` on sorted headers | |
| Live region | "Showing 1–50 of 12,480", and the outcome of a filter change |
| **Cell accessible name includes its column** | "1,234.56" read alone is meaningless. "Net amount, 1,234.56" is not |
| Editable cells | `aria-readonly` where not editable; edit mode announced |

## Charts and data visualization

**Every chart has an accessible table behind a disclosure and a text summary of the trend.**

A dashboard that exists only as pixels excludes an auditor using a screen reader from the data they are there to read — and an `aria-label` reading "revenue chart" conveys nothing.

- Categorical palettes validated for contrast in both themes **and** distinguishable without colour, by pattern, shape or direct label (1.4.1)
- Tabular numerals in every axis label, tooltip and data label
- `prefers-reduced-motion` respected — charts render without animation when asked
- Keyboard-navigable data points where a chart is interactive

## Forms

| Requirement | Detail |
|-------------|--------|
| Error identification (3.3.1) | **Summarized at the top with links to each field, and inline at the field**, announced through a live region, never by colour alone |
| Labels and instructions (3.3.2) | Format requirements stated before the error, not after |
| Error suggestion (3.3.3) | "Enter a date in the open period 2026-11" beats "invalid date" |
| **Redundant entry** (3.3.7) | Information already provided is pre-filled or selectable, not re-requested |
| **Accessible authentication** (3.3.8) | Passkeys are the preferred factor precisely because they require no cognitive test. **No CAPTCHA, no puzzle, and paste is never blocked** in a credential field |
| Autocomplete and input modes | Correct `autocomplete` tokens, `inputmode="decimal"` on figures |
| Target size (2.5.8) | 24×24 minimum in **every** density mode, including `dense` |

## Verification

| Method | Cadence | Covers |
|--------|---------|--------|
| **Contrast matrix test** | Every build | Every legitimate token pair in both themes, 4.5:1 text and 3:1 UI |
| **Template architecture test** | Every build | The label, id, skip-link and `<main>` rules above |
| **axe automated scan** | Every build, every route | Catches roughly 40% of real issues — useful, not sufficient |
| **Browser keyboard tests** | Every build | Grid multi-row edit, reconciliation, dialog focus management |
| **Visual regression** | Every build | LTR and RTL, light and dark, three densities |
| **Screen reader walkthrough** | Once per release, recorded | The 60% automation misses: announcement quality, reading order, whether a workflow is actually completable |

> **An automated scan is a floor, not a conformance claim.** It cannot tell you that a label is accurate, that a reading order makes sense, or that a workflow can be completed. The per-release manual pass is where those are found, and its result is recorded in the release notes alongside the ASVS result.

## What a failure costs

Stated plainly, because accessibility work is often treated as optional until it is not:

- **EN 301 549 applies to public-sector procurement across the EU.** A product that cannot evidence WCAG 2.2 AA is excluded from those tenders.
- **An auditor using a screen reader** is a realistic and important user of this product specifically. A dashboard they cannot read is a product they cannot approve.
- **Keyboard-only operation is not a minority requirement in finance.** The fastest users of a data grid never touch the mouse, and the accessibility work and the velocity work are the same work.
