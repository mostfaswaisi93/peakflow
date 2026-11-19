# Phase 11 — Design system, data grid, accessibility and RTL

**18 tasks · 99 points**

The design brief for a financial application is **legibility and velocity, not delight**. Finance users live in tables for eight hours; they type numbers faster than they can point at them, and they need to trust what they read at a glance.

This phase is the systematic pass, not the first design work. The shell, the token scaffold and the dark-mode variant land in Phase 00 (`PF-017`), because eleven phases of screens built before a design system produces eleven design systems. What lands here is everything that can only be done once the screens it governs exist and can be audited together: the full token architecture, the contrast matrix, density, the production data grid, and the print stylesheets.

---

### PF-200 · The contrast matrix
`—` · **5 pts** · depends: PF-199 · standards: WCAG 2.2 AA (1.4.3, 1.4.11) · `risk:high` `type:a11y`

- [x] A test computes the contrast ratio for **every legitimate foreground-background token pair in every theme** and asserts 4.5:1 for text and 3:1 for UI components and graphical objects. With five themes that is a few hundred measurements, which is the point: a pair nobody measured is a pair that is wrong in one theme
- [x] The permitted pairs are declared, so the test knows which combinations are intended rather than testing a cartesian product
  - Five pairs the product actually renders were **not** declared, including `on-surface-muted` on `surface-sunken` in sixteen places and in the grid's own header. All measure fine, worst 6.04:1 - but fine was luck, and this matrix exists because a pair nobody declared is a pair nobody measured. A check now harvests every foreground/background written into the same class list in every template and requires it to be in the matrix, so the declaration cannot fall behind the markup again.
- [x] A palette change that regresses any pair fails the build
- [x] Focus indicators are tested separately against both the component and the adjacent background, per 2.4.11
  - **This is the criterion that found the worst defect in the design system.** `:focus-visible` drew one colour and the matrix only ever measured it against `surface` and `background` - the two things a focused control is rarely sitting on. Measured against every fill a focused control can have: 9 of 27 fills fail 3:1 in light, 9 in Graphite, 9 in Midnight, 12 in hc-light, 7 in hc-dark. In Graphite and Midnight the primary button scores **1.00:1** - the ring and the button are both Glacier Teal, so a keyboard user focusing the main action in dark mode sees nothing at all.
  - No palette change fixes it. In the high-contrast themes, "4.5:1 from a near-white surface" and "4.5:1 from a Glacier Teal fill" have an **empty intersection**: one colour cannot do both. So the indicator is two - the ring, plus `--c-focus-halo` filling the 2px offset gap, which is what 2.4.11 contemplates. Zero failures in all five themes afterwards, the two colours 4.83-11.49:1 apart, verified in Chromium.
  - A separate check asserts nothing eats the halo: the halo rule is unlayered, and the case that justifies that is measured - the sign-in CTA carries `shadow-e2`, and while focused its computed `box-shadow` is the halo alone in all five themes.
- [ ] This exists because a sibling project shipped a status pill at **1.62:1** in dark mode, which measured 11.75:1 after the fix - the kind of defect that passes every functional test
  - **Left open deliberately: this is a reason, not a condition.** It states why the task exists and there is nothing in it that can be true or false, so there is nothing to tick and nothing to bite. Its testable content is criterion 2's completeness, and the pair-harvest check added there is precisely the check that would have caught that pill - a pair rendered and not declared now fails the build. Ticking a criterion whose text is an anecdote would make the progress table count a sentence.

### PF-201 · Typography
`—` · **5 pts** · depends: PF-199 · standards: — · `risk:high`

- [ ] Variable fonts, self-hosted and vendored through the asset pipeline - nothing fetched from a third party at runtime, so an offline load keeps its typography
  - **The runtime half is closed and bitten**: an `@import url(fonts.googleapis.com…)` fails the build, and an `@font-face` whose `src` is an absolute URL is refused - so the shape is held for the day the files land.
  - **The vendoring half is not.** The faces are fetched at *build* time from `fonts.bunny.net` and only when `PEAKFLOW_FETCH_FONTS=1`, so the default build ships no font file at all and the interface renders in system fallbacks; and that fetch takes static weights, not variable faces. The host is refused by this environment's egress proxy (403 on CONNECT), so the files cannot be committed from here. The honest fix is the `woff2` files under `resources/fonts` with `@font-face` in the stylesheet, done on a machine that can reach the host.
- [ ] An Arabic face for the UI and a Latin face carrying the numerals, with matched x-height and vertical metrics so a mixed line does not jump
  - **The two faces are closed**: two stacks, the figure face is one with true `tnum`, and the fallbacks are named Arabic faces rather than a generic sans - which an earlier commit fixed for exactly this reason (`1e57db0`). Dropping the Arabic fallbacks fails the build.
  - **The matched metrics are not closable here**, and depend on the criterion above: with no font files in the repository there is nothing to measure and no `size-adjust`/`ascent-override` to write.
- [x] **`font-variant-numeric: tabular-nums` on every figure**, so digits align in columns and the eye can scan a thousands place. A proportional `1` in a currency column is the single most common reason a financial UI feels amateur
  - Measured in Chromium rather than asserted from the CSS: inside `.figure`, `1111111` and `8888888` both measure **72.58px**; the same two strings outside it measure **46.48px** and **70.50px**. A 24px drift over seven digits, which is this criterion's complaint, quantified.
  - And every figure on a rendered page is marked as one - bitten by removing `data-figure` from the bills list's total cell, which is the omission that actually happens.
- [x] A fluid type scale with explicit steps, not arbitrary sizes, and a line-height that accounts for Arabic's taller ascenders and descenders
  - Three bites: a line height tightened below Arabic's headroom, a step with no line height at all, and the display steps de-fluidised from `clamp()` to `rem`. A fourth asserts a display size is only ever used on a figure, because a display step on a page heading is how a scale stops being a scale.
- [x] Icon fonts, if used at all, are hidden until loaded so the page never shows the literal ligature word
  - There is no icon font, which is the stronger version of this, and a check refuses one - bitten by adding a `material-icons` class to `<x-icon>`. The second bite removes `aria-hidden` from the icon, because a decorative mark that reaches a screen reader is the same failure read aloud.

### PF-203 · Density
`—` · **5 pts** · depends: PF-199 · standards: WCAG 2.2 AA (2.5.8) · `type:a11y`

- [ ] Three modes - `comfortable`, `compact`, `dense` - changing row height, font step and padding through tokens alone
  - **The three modes are real and the grid honours them**; 331 `<td>`/`<th>` cells across 17 other templates do not. They use literal padding utilities (`px-4`, `py-2`…) - 42 in `app/ledger/profit-or-loss`, 40 in `app/settlement/statements`, 36 in `app/purchasing/bills/show`, 34 in `app/sales/invoices/show`, 28 in `app/masters/currencies` - so on those screens the setting changes the font step and not the padding. Only `<x-grid>` reads `--row-padding-*`.
  - Left open rather than ticked on the grid alone: 330 mechanical cell edits is a screen-by-screen job, and the criterion says "through tokens alone", which is false while seventeen templates set their own padding. A check holds the grid, which is what the task names, and bites a cell whose padding stops coming from the tokens.
- [x] A **user setting, not a breakpoint**: an accountant on a 27-inch monitor wants 40 rows, and a default that serves one user serves neither
  - Density was **dead end to end**. The stylesheet carried three complete `[data-density]` blocks, and `grep -rn data-density` over `app/`, `resources/`, `routes/`, `config/` and `database/` returned the stylesheet and nothing else: no column, no preference, no attribute on `<html>`, no picker, no way to select any mode. Every user got `:root`.
  - Bitten by setting a density token inside a `@media (min-width: 1280px)` block, which is the shape this criterion forbids.
- [x] Persisted per user and applied before first paint, so the layout does not reflow on load
  - Server-rendered onto `<html>`, not applied by script, so there is no frame in the wrong density. The check **renders the page and reads the attribute out of the DOM**, because a source-level check would pass a layout that writes an attribute from a resolver that always answers the same thing - which is exactly the state this task was found in.
  - The picker works with scripting off (a `<details>` and submit buttons) and each option says how many rows it gives, because "dense" means nothing until you know what it costs you.
- [x] Target sizes stay within the 24×24 minimum in every mode, including `dense`, which is the constraint that bounds how dense `dense` can be
  - Measured in the browser: rows 48/40/32px and controls 40/36/28px for comfortable/compact/dense, so the floor holds with 4px to spare at the tightest. Bitten by taking `dense`'s control height to 20px.

### PF-204 · The application shell — mostly done
`—` · **5 pts** · depends: PF-203, PF-027 · standards: WCAG 2.2 AA · `type:a11y`

- [x] Sidebar navigation gated by permission, the entity switcher, the user menu
- [x] **A skip link as the first thing in the tab order**, hidden until focused, because all three shells put navigation ahead of content and reaching it by keyboard otherwise means tabbing past thirty controls on every load
- [x] **`<main>` carries `tabindex="-1"`**: a `<main>` is not focusable by default, so without it the browser scrolls to the target but leaves focus where it was, and the next `Tab` returns to the navigation just skipped. A skip link that only moves the viewport is worse than none, because it looks like it worked
- [x] The active entity and the current period state are visible on every screen - a user posting into the wrong company or a closed period is what this prevents
- [x] Breadcrumbs, derived from the navigation declaration rather than written per page
- [x] A consistent page header, adopted by every screen — `<x-page-header>`, with a check that no page writes its own `<h1>`
- [ ] Global search and notifications
- [ ] Plan gating in the menu: a page the tenant's plan does not include is listed with its upgrade path, not hidden

> **The navigation is data, not markup** — `App\Support\Ui\Navigation`. A
> sidebar written as markup is a second list of what exists, and it goes out
> of date the first time somebody adds a page and forgets it; the symptom is
> a feature nobody can find, which is the same as a feature that was never
> built. `bin/verify shell` renders the shell and reads the structure out of
> the HTML, because every claim above is structural. Items with no route yet
> are **listed and not linked**, deliberately: a menu showing only what is
> finished tells a new user nothing about what the product is for.
>
> **The `<noscript>` path** is the whole shell, not a fallback for it. The
> narrow-screen menu is a `<details>` rather than a scripted drawer, and the
> entity switcher submits a real form, so navigation and company switching
> both work with scripting off.
>
> The one this found: the first version gave the landmark to **both** copies
> of the menu — the wide-screen column and the narrow-screen disclosure — so
> a screen reader's landmark list offered two identical "Main navigation"
> entries with no way to tell which was the copy hidden at that width.
>
> **The menu and the pages are made to agree, in both directions.** Holding
> the permission a menu entry names must be enough to open the page, or the
> product offers a link that 403s and the user's only reading of that is
> that it is broken. And a page a reader can open must be in that reader's
> menu — which is how the second bug here was found: the invoice list was
> offered under `invoice.draft`, a *write* permission, so an auditor whose
> whole job is to read it was never shown it. Both directions are checked by
> building a role that holds exactly one permission and opening the page
> with it; asking whether "the accountant can open it" would prove nothing,
> because the accountant holds nineteen and any one of them could be the one
> the page is really reading.
>
> **Every page the menu links to is rendered on every run**, against a real
> PostgreSQL, with documents on the books rather than on an empty entity. An
> empty state renders happily while the row loop beside it reads a column
> that does not exist — and that is the failure the person clicking the menu
> item finds. The detail pages are rendered too, each paired with a fixture
> that makes a row it can show; a parameterised page with no pairing fails
> the build rather than going unrendered, which is the only way a list like
> that stays true.
>
> **The header is a component because thirteen screens written in parallel
> produced thirteen copies of it.** Most agreed, which was luck. The
> difference nobody would have caught is the heading *level*: a screen-reader
> user navigates by headings, so a page whose title is an `<h2>` because
> somebody copied a card has no top level at all — and it looks identical.
>
> One thing this suite taught about itself: it passed alone and failed inside
> `bin/verify all`. The operator-console and error-response suites run first
> and dispatch real requests through `auth:ops` and `auth:sanctum`, and
> Laravel's Authenticate middleware calls `shouldUse()` — so the process's
> default guard was whichever one ran last, and `Gate` resolves its user from
> the default guard. Every authorization check here was asking about an
> operator. The suite now pins the web guard, which is the shape of fix a
> check that depends on state it did not set always needs.

### PF-205 · The data grid — mostly done
`—` · **8 pts** · depends: PF-204 · standards: — · `risk:high`

- [ ] Row virtualization handling 50,000 rows without degrading scroll — **deliberately not done**, see below
- [x] Sticky headers, frozen leading columns, column pinning, resizing, reordering and visibility
- [x] Server-side sort, filter and cursor pagination; the grid never holds a full dataset
- [ ] **Saved views per user**: column set, widths, sort, filters, density - shareable within the tenant — the grid renders them; nothing persists them yet
- [x] Grouping with subtotals, and a footer with aggregate totals that come from the server rather than from the loaded page
- [x] A query-budget test: the grid costs the same number of queries at 5 rows as at 500

### PF-206 · The grid keyboard model — mostly done
`—` · **8 pts** · depends: PF-205 · standards: WCAG 2.2 AA · `risk:high` `type:a11y`

Finance users touch-type numbers, and a mouse round trip per cell is the difference between usable and abandoned.

- [x] Arrow navigation, `Home`/`End`, `PageUp`/`PageDown`, `Ctrl`+arrow to bounds — *handled; no browser has pressed a key*
- [x] Range selection with `Shift`+arrows, and copy to clipboard as TSV so it pastes into a spreadsheet correctly
- [x] **Type-to-edit**: typing in a focused cell begins editing. `Enter` commits and moves down, `Tab` commits and moves right, `Esc` reverts the cell, `Ctrl`+`Z` reverts the edit
- [x] Paste from a spreadsheet into a range, with a preview of what will change and a count, before it commits
- [ ] Every mouse-only affordance has a keyboard equivalent, and a browser test performs a full multi-row edit with the keyboard alone

### PF-207 · The grid's accessibility semantics — mostly done
`—` · **5 pts** · depends: PF-206 · standards: WAI-ARIA 1.2, WCAG 2.2 AA · `type:a11y`

- [x] `role="grid"` with correct `gridcell`, `row` and `columnheader` structure
- [x] **`aria-rowcount` reflects the total, not the rendered window** - a virtualized grid that reports 50 rows when there are 12,480 tells a screen-reader user something false
- [x] `aria-sort` on sorted headers; `aria-selected` on selected rows
- [x] A live region announcing "showing 1-50 of 12,480" and announcing the result of a filter change
- [x] A cell's accessible name includes its column, because a bare "1,234.56" is meaningless read alone
- [ ] Verified with an actual screen reader once per release, and the result recorded

`resources/views/components/{grid,grid/*,form/*}.blade.php` ·
`resources/js/enhancements.js` · `bin/verify grid`

**No row virtualization, and that is the decision rather than the gap.** A
virtualizer is a scroll-driven renderer, so with scripting off it shows
nothing — worse than slow for a financial list somebody is reconciling. The
other half of the criterion is what was built instead: the window is bounded
on the server, the grid **refuses** more than 500 rows while naming the
paginator, and `aria-rowcount` still tells the truth about all 50,000. If
virtualization is wanted later it goes on top of a grid that already works
without it.

**Column resize and reorder are saved-view data, not drag handles** — a
per-column width and the declaration order. Widths are validated as plain CSS
lengths, because a saved view is user data going into a `style` attribute.
There is no drag UI, so there is no keyboard-equivalent gap to close.

**What the suite does not do, plainly: it never presses a key.** The keyboard
model is checked at its two edges — every `data-pf-*` hook the script reads is
rendered and every hook rendered is read, compared both ways; and the one
piece of script that transforms a value is executed in `node`. That
`ArrowDown` really moves down still needs the browser test PF-206 asks for.

Three of the forty-five bites found a check that could not fail rather than a
defect, and each was fixed rather than excused. Rounding introduced in the
*paste* path was caught by nothing, because the node harness has no DOM — it
is now a source-level prohibition on `toFixed`, `Math.round` and `parseFloat`,
and the check says it is a prohibition and not a measurement. A check that
scanned the whole script was satisfied by the script's own *prose* explaining
why the inline direction is read rather than assumed, so replacing that read
with `true` — which mirrors every arrow key in Arabic — left it green; it now
strips comments first. And the check on inlining the behaviour once per page
could not fail in the arrangement it shipped in, which the agent said rather
than pretended: the arrangement that *did* duplicate it — an `@once` at each
call site — inlined 31 kB twice under a duplicate element id, and that is
caught by two checks.

### PF-208 · The command palette
`—` · **3 pts** · depends: PF-072, PF-204 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] `Ctrl`/`⌘`+`K` over documents, parties, accounts, reports and actions
- [ ] Scoped results with section headings, recent items, and permission filtering applied in the query
- [ ] Arrow navigation, `Enter` to activate, `Esc` to dismiss with focus restored to where it was
- [ ] Debounced, cancellable requests; a slow search never blocks typing

### PF-209 · The invoice builder
`Sales` · **8 pts** · depends: PF-092, PF-206 · standards: — · `risk:high` `type:a11y`

- [ ] A line grid with the full keyboard model, item autocomplete, and per-line tax, discount and dimension entry
- [ ] **Tax and totals recalculate on the server and only on the server.** A client-side tax engine is a second implementation of the rules and it will disagree - which is why Livewire was chosen over an SPA for this screen specifically
- [ ] A visible pending state while a recalculation is in flight; the totals are never optimistically rendered, because an optimistically wrong total on an invoice is a lie about money
- [ ] A live validation panel showing exactly what prevents issuing, addressed by field
- [ ] The tax panel shows the per-rate-group breakdown and the place-of-supply decision with its reasoning, so an accountant can check it
- [ ] Draft autosave with conflict detection, and an explicit issue action that is never reachable by `Enter` in a text field

### PF-210 · The reconciliation view
`Banking` · **8 pts** · depends: PF-165, PF-206 · standards: WCAG 2.2 AA · `risk:high` `type:a11y`

- [ ] A two-pane split: bank transactions on one side, open documents on the other, with a **running difference that is always visible**
- [ ] Drag to match, drag to split, with a **keyboard equivalent for every drag** - select, mark, apply - because a drag-only interface excludes keyboard and screen-reader users from the product's core workflow
- [ ] Proposed matches shown with their confidence and their reason, acceptable or rejectable in bulk
- [ ] A match is applied atomically with an undo that reverses the posting properly rather than deleting it
- [ ] The running difference and each applied match are announced through a live region

### PF-211 · Charts
`—` · **5 pts** · depends: PF-199 · standards: WCAG 2.2 AA (1.4.1, 1.4.11) · `type:a11y`

- [ ] An ECharts wrapper taking tokens from the design system, so a chart cannot introduce its own palette
- [ ] Categorical, sequential and diverging palettes each validated for contrast against every theme and distinguishable without colour - pattern, shape or direct label
- [ ] **Every chart has an accessible table behind a disclosure and a text summary of the trend.** A dashboard that only exists as pixels excludes an auditor using a screen reader
- [ ] Tabular numerals in every axis label, tooltip and data label; currency formatted through ICU
- [ ] Charts respect `prefers-reduced-motion` and render without animation when asked

### PF-212 · The form system — mostly done
`—` · **5 pts** · depends: PF-199 · standards: WCAG 2.2 AA (3.3.1-3.3.8) · `type:a11y`

- [ ] Every control has a real `<label>` associated by `for`/`id` - a placeholder is not a name, and a bare `<label>` beside an input names nothing
- [ ] Ids derived from an instance key, because a component used twice on one page with duplicate ids points its label at whichever copy the browser saw first
- [ ] Errors summarized at the top with links to each field, **and** inline at the field, announced through a live region, never conveyed by colour alone
- [ ] A **currency input** that masks as you type, accepts Arabic and Western digits, accepts a pasted value with separators, respects the currency's exponent, and never silently truncates a third decimal
- [ ] A **date-range picker that understands fiscal periods** - "last period", "this quarter", "year to date" resolve against the entity's own calendar, not the Gregorian one
- [ ] Autocomplete attributes, correct input types and input modes, and no field that rejects a paste

### PF-213 · State coverage
`—` · **5 pts** · depends: PF-205 · standards: WCAG 2.2 AA · `type:a11y`

Designed states for every screen, not left to the framework.

- [ ] Empty, loading, error, partial-failure, offline, permission-denied and no-results-for-this-filter
- [ ] **A table with no rows must distinguish three cases**: there is no data, the filter excluded everything, or the request failed. A spinner-then-blank cannot tell them apart, and each needs a different action
- [ ] Skeletons that match the final layout, so nothing reflows on arrival
- [ ] An error state names what failed and offers a retry that does not lose entered data
- [ ] A test enumerates the application's routes and asserts each has a declared empty and error state

### PF-214 · Motion
`—` · **3 pts** · depends: PF-199 · standards: WCAG 2.2 AA (2.3.3) · `type:a11y`

- [ ] 120-240ms, `cubic-bezier(0.2, 0, 0, 1)`, with the durations as tokens
- [ ] **Every transition wrapped in `@media (prefers-reduced-motion: reduce)`**, and the reduced path is tested rather than assumed
- [ ] The View Transitions API for list-to-detail where supported, degrading to no animation
- [ ] Nothing animates in a data grid during scroll or edit - motion in a table being read is noise
- [ ] No animation longer than 500ms, and nothing that loops

### PF-215 · Print stylesheets
`—` · **5 pts** · depends: PF-103 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] Invoices, credit notes, statements, aging, trial balance and the financial statements each have a print layout
- [ ] A4 and Letter, correct page breaks that never split a row or orphan a total, repeated table headers, `page x of y`
- [ ] Entity identity, report parameters and the as-of basis on every page, because a printed report without its parameters is unusable
- [ ] No interactive chrome, no colour-only meaning, and a draft watermark that is also a word
- [ ] Tested by rendering and diffing the PDF, not by eyeballing a browser preview

### PF-216 · RTL enforcement
`—` · **5 pts** · depends: PF-204 · standards: — · `risk:high`

- [ ] **Logical CSS properties only** - `margin-inline-start`, `padding-inline-end`, `inset-inline-start`, `border-start-start-radius`. A single `left:` in a component is an RTL bug waiting for a customer to find it
- [ ] A stylelint rule fails the build on any physical property, and an architecture test fails on one in a template
- [ ] Native `<select>` arrows restyled with an inline SVG positioned on the inline-end side, because the browser default renders on the wrong side under RTL and ignores the theme
- [ ] **Bidi isolation on every money value, IBAN, invoice number and identifier** embedded in Arabic text: `JO94-CBJO-0010` rendered in logical order is a different string from the one the user must verify, and they will not notice
- [ ] Icons that imply direction are mirrored; icons that do not (a clock, a checkmark) are not
- [ ] Visual regression runs every screen in LTR and RTL, in light and dark, at all three densities

### PF-267 · The tenant's own brand colour
`—` · **5 pts** · depends: PF-199, PF-200 · standards: WCAG 2.2 AA · `risk:high`

- [ ] A tenant may set one brand colour, and the product **derives a ramp from it** rather than painting it on buttons - Material You's useful idea: one input, a generated set of roles
- [ ] Every derived pair is measured, and a brand colour that cannot meet 4.5:1 for text is **used for graphics only**, with the product saying so rather than shipping unreadable buttons
- [ ] The derivation runs at save time and is stored, not computed per request, because a colour ramp recomputed on every page is a colour ramp that shows up in the flame graph
- [ ] The five themes each derive their own ramp from the same input: a brand colour that works on white rarely works on near-black, and picking one and lightening it is how a tenant's product looks broken in dark mode
- [ ] A tenant that sets nothing gets the PeakFlow kit, which is the case to optimise - most tenants will never open this screen

### PF-276 · Responsive, measured at the widths people actually use
`—` · **8 pts** · depends: PF-204, PF-205 · standards: WCAG 2.2 AA (1.4.10 reflow) · `risk:high`

**Commits to:** the product being usable on a phone and a tablet, not merely rendering on one.

A ledger is used on a laptop and read on a phone - an approver clearing a payment run in a taxi, an owner checking a balance at a supplier's counter. The failure is not that the page breaks; it is that it *works* while costing four horizontal scrolls and a pinch, and the person stops using it rather than complaining.

- [x] A **width budget per screen**, asserted: no page scrolls horizontally at 320 CSS pixels, which is WCAG 1.4.10's reflow threshold and the narrowest phone still in use
  - `bin/verify viewport` drives the real application - `php artisan serve` on a port of its own, signed in as the showcase owner - and visits four pages at 320, 768, 1024 and 1440, in Arabic and in English. No page scrolls sideways at any of them.
  - It reports **which element overflows**, not just that the page does, because "this page scrolls" is a bug report and "`div.flex.flex-wrap` is 924px in a 320px viewport" is a fix. The bite puts a `min-width` on the page header and gets exactly that sentence.
  - A table inside its own `overflow-x: auto` scroller is **not** a failure and the check does not report one: a grid that scrolls within its box is the intended behaviour, and flagging it would be the check teaching people to ignore it. Found by biting the wrong thing first.
- [ ] The data grid has a declared behaviour at each breakpoint - which columns collapse, which become a second line, which are dropped - and the rule is in the component rather than in each page's classes
- [ ] Every page is rendered at 320, 768, 1024 and 1440 in a real browser and the screenshots are compared against the previous run, so a layout that regresses is a diff rather than a bug report
- [x] Touch targets are at least 44×44 CSS pixels where a finger is the pointer, and the check reads the computed box rather than the class
  - **It found real ones.** Every control in the header was 40×40 or less - the navigation toggle, the density and theme switchers, the company switcher, the user menu - and the grid's own controls were 34 to 36 pixels tall: the column toggle, the copy button and both paginations. All now clear 44, and the check measures the rendered rectangle, so a target sized by a class its parent shrinks is still caught.
  - Getting it to say only true things took two passes. The first run reported the skip link at 1×1 - which is correct, it lives at -9999px until focused - and every link in a collapsed `<details>`, which lays its contents out without showing them. A check that reports what cannot be touched is a check people scroll past.
  - Breadcrumb links are exempt under **WCAG 2.5.8's own "inline" exception**: a link in a line of text takes its size from the text, and enlarging it breaks the line. Matched on the computed `display` rather than on the tag, so a link made into a block or a flex item is still measured.
  - Only measured at 320 and 768, because the criterion is about where a finger is the pointer.
- [x] RTL is measured at every width too, because a layout that only breaks in Arabic at 320 is a layout nobody on the team sees break
  - Both directions, every page, every width - the run signs in twice, once with an Arabic locale and once with English, and the failures are labelled with the direction they appeared in.
  - It is the cheaper half of the pair to get right and the one that silently rots, because the team reads the product in Arabic and the breakage is in the other one as often as not.

### PF-277 · One spacing scale, applied everywhere
`—` · **5 pts** · depends: PF-199 · standards: — · `type:design`

**Commits to:** the product looking like one product.

Inconsistent spacing is the single most visible sign of software assembled by several people on several days. It is also the cheapest thing to get right and the easiest to lose: one `mt-7` among `mt-6`s is invisible to its author and obvious on the page beside it.

- [x] A spacing scale is declared as tokens, and a check refuses an arbitrary value - a page may use `space-6`, not `margin-top: 26px`
  - **The product was already on the scale; nothing was holding it there.** Every template was audited: zero arbitrary values, and the only literal `margin` in the stylesheet is `@page { margin: 16mm 14mm }`, which is paper and is measured in millimetres for a reason. So the compliance was real and accidental, which is a state that lasts until the next person in a hurry.
  - The scale is drawn from what was already used - 0.5, 1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10, 12 - rather than invented and imposed. A scale designed in the abstract generates a hundred exceptions on its first run, and a hundred exceptions is no scale.
  - The list matters more than the unit. Tailwind v4 computes `mt-7` from `--spacing * 7` without being asked, so every integer is available and nothing says which ones the product uses. **That is exactly how one `mt-7` lands among the `mt-6`s** - invisible to its author, obvious on the page beside it, and impossible to find afterwards because it is not a mistake in any mechanical sense.
  - Three bites: a step off the scale, an arbitrary value, and an exception whose subject no longer exists.
- [ ] Section rhythm is a component, not a convention: the vertical gap between a page header and its first section, and between sections, is set in one place
  - **Confirmed as a convention, not a component.** `<x-page-header>` carries no bottom margin and `<main>` carries no `space-y`, so every page sets its own gap and the rhythm is whatever each page remembered. The scale above means those gaps are at least all on the scale; it does not mean they agree.
  - Fixing it is moving the rhythm into one place and then taking the manual gap off every page, which changes the look of every screen at once - so it wants the before-and-after comparison the criterion below asks for, and is held until that exists.
- [x] Every page is audited against the scale and the exceptions are **named with a reason**, because a design system with silent exceptions is a design system nobody trusts
  - The audit found three steps outside the scale in the whole product, and they turned out to be three different things, which is the argument for naming exceptions rather than counting them.
  - `px-3.5` on the sign-in fields was the only real slip - four uses, all the same control, consistent with itself and with nothing else. Moved to `px-3`.
  - `ms-9` in the trial balance is **one rung of a ladder**: `ms-0, ms-3, ms-6, ms-9, ms-12`, regular at a step of 3, and the indent is what says which account is inside which. Breaking the ladder to fit the scale would break the report.
  - `py-16` in the plain layout is the vertical breathing room of a page with one card centred in it, where the space *is* the layout rather than a gap between two things.
  - The exceptions are keyed by `file:class`, so each is granted to one use rather than to a step everywhere - and the check reports an exception whose subject has gone, because an exception nobody revoked is a licence for the next person who needs one.
- [x] The check reads computed styles from a rendered page, not class names - a page that reaches the right spacing by three overrides is a page that breaks on the next change
  - Built as `bin/verify viewport`, shared with PF-276. It reads the computed `margin-block-start` of every direct child of `<main>` on four pages at four widths in both directions, and asserts each is a step of the declared scale. Every gap in the product is.
  - The template check remains and is not redundant: it reads **every** template including ones no route renders, where this reads only what a browser can reach. The two measure different halves, and the one that can be run without a server is the one people will actually run.

### PF-278 · Tables that read like a financial product
`—` · **8 pts** · depends: PF-205, PF-277 · standards: WCAG 2.2 AA · `risk:high`

**Commits to:** the grid being the thing people point at when they say the product looks professional.

Accounting software is tables. Everything else is navigation around them. A table that aligns its figures, holds its header, groups its totals and says what it is sorted by is the difference between a product that feels authoritative and one that feels like a spreadsheet somebody exported.

- [x] **Figures are tabular-lined and right-aligned**, negatives are accounting-style, and the decimal points line up down the column - a column whose decimals wander is a column nobody can scan
  - Already built, by PF-201 and PF-205 rather than here: `.figure` sets `tabular-nums` with `tnum`/`lnum`, and `Money::toAccounting()` wraps a negative in parentheses - *"parentheses, a sign AND a colour... the parentheses, not the hue"*, which is WCAG 1.4.1 rather than a style preference.
  - The right-alignment carries its own reasoning and an `@arch-allow`: a figure column stays **right**-aligned in Arabic, not `end`-aligned, because digits read left to right in both directions and `end` would left-align every figure in RTL and destroy the thousands-place scan. That is the one place in the stylesheet where a physical direction is correct.
- [x] A sticky header that survives a scrolled body, and a sticky first column where the row's identity is in it
  - Also already built. Worth repeating why it is the `<thead>` that is sticky and not each `<th>`: one positioned ancestor, so the header paints over ordinary rows - a sticky `<th>` with no z-index of its own loses to any positioned cell beneath it. And the scroller carries a maximum block size on purpose, because a sticky header with nothing to stick inside is the most common way one ships as a non-sticky one.
- [x] Zebra striping, row hover and the selected row are **three distinct states** that remain distinguishable in both high-contrast themes and in print
  - **None of the three existed.** The row carried `aria-selected` and announced itself correctly to a screen reader, and showed a sighted person nothing at all; there was no zebra and no row hover (the `hover:bg-surface-hover` in the component was on the copy button).
  - Built from tokens that were already declared and already contrast-measured - `surface-sunken`, `surface-hover`, `surface-selected` over a row of `surface` - so no new role, and the three pairs with `on-surface` were already in `contrast-pairs.json`.
  - The check asserts all four values **differ in every one of the five themes**, which is an equality test rather than a contrast one on purpose: a tint one step from its neighbour is a legitimate design choice at 1.05:1, so a threshold would either forbid subtlety or pass two values that are literally the same. Its bite makes hover equal the row surface in Graphite and it reports *"the row itself and hover are both #0A0F14"*.
  - **Print and forced colours are where this criterion is actually decided.** Paper has no backgrounds and forced-colours mode replaces every one of them, so a selection shown only as a tint survives neither - and "which rows am I about to pay" is not a question to answer with a colour a printer drops. The selected row carries a bar on its inline-start edge, drawn on a pseudo-element so it adds no layout, positioned with `inset-inline-start` so it is on the right in Arabic without a second rule. Zebra and hover are dropped in print - paper has no pointer, and a striped table prints as a table somebody spilled toner on, at the cost of the ink of every other row - and the bar is kept, in `Highlight` under forced colours.
  - Three bites, because the check makes three claims: two states collapsing, the mark going away (which also trips the forced-colours half), and the mark placed with `left` instead of `inset-inline-start`.
- [ ] Column widths are content-aware with a declared minimum, so a long legal name does not squeeze the amount column into two lines
  - **The minimum is declared** - `minWidth` per column, validated like `width` and refused if it is not a CSS length, plus a 7rem floor on every figure column. `width` is what a column wants and `minWidth` is what it will not go below: a width is a preference a table overrules to fit, and a long legal name overruling the amount column is how the figures end up on two lines.
  - **And today it changes nothing, which the bite is how I know.** Removing the floor moved no rendered width on any page at any viewport. The grid's table sits in a horizontal scroller and its figures are `nowrap`, so the table takes the width its content wants rather than squeezing a column - the failure this criterion describes does not currently arise, by a different mechanism than the one it proposes.
  - So the check that measured it was **removed rather than kept**: it passed because the content was wide enough, not because the floor held, and a green check that cannot fail is worse than an open criterion. The floor stays as a guard against a table told to lay out fixed or placed outside a scroller, both of which squeeze.
  - What is genuinely not built is the **content-aware** half: nothing measures the longest value in a column to size it. That is what would let a name column be as wide as its names rather than as wide as the space left over, and it needs either a server-side pass over the page's rows or a measured first paint.
- [x] Totals and subtotals are visually anchored - a top border and a weight change, not a colour - because colour is the first thing a printer loses
  - Already built: the `<tfoot>` carries `border-t-2 border-outline-strong` and `font-semibold`. Ticked here rather than claimed as new work.
- [ ] Empty, loading and error states are part of the table component rather than each page's own markup
  - **Empty is done, and is enforced rather than offered**: a grid that renders no rows and was given no `empty` slot throws, because *"'No results' on a page somebody opened for a reason"* is a page that should say which reason. Loading and error are not in the component - loading is PF-280's skeleton and belongs with it, and the error state has no home yet.
- [ ] The whole of it is measured against three real financial products' grids, and the comparison is recorded so "more professional" is a judgement somebody can check rather than an opinion

### PF-279 · The logo, and a loader that is part of it
`—` · **5 pts** · depends: PF-204 · standards: WCAG 2.2 (2.3.3 animation from interactions) · `type:design`

**Commits to:** the moment between pages being the product's, not the browser's.

- [x] The mark animates as a **loader**, derived from the logo rather than drawn separately, so the thing that spins is recognisably the brand
  - It does not spin. The mark's own **flow line** travels across the peaks - the one gesture already in the logo that is about movement, and the one the product is named after. A spinner wearing the brand's colours would have been easier and would have been a spinner.
  - It is the **one place** the mark is redrawn rather than loaded. `<x-brand.logo>` loads the kit's masters as `<img>` so the artwork is used exactly as delivered; this cannot, because the motion is *inside* the drawing and a browser does not animate the inside of an `<img>`. The paths are copied from `mark-color-on-light.svg` rather than reinterpreted, and the colours go through three new roles so the drawing still passes through the token layer - including the high-contrast themes making it monochrome, which is what the kit's own high-contrast artwork does.
- [x] It appears on navigation and on a full refresh, after a delay long enough that a fast page never shows it - a loader that flashes on every click is worse than none
  - The delay is a **CSS animation delay, not a timer**. The script only takes the `hidden` off; the 400ms wait is in the stylesheet, so a page that arrives in 80ms is revealed, never reaches its delay, and is replaced before anything is painted. A `setTimeout` here would be a timer racing a navigation, and the loser is sometimes the timer firing into a page that is already gone.
  - Measured in a browser: opacity **0 the instant it is revealed, 1 after 700ms**.
  - A modified click, a `_blank`, a download, a fragment, a `mailto:` and a cross-origin link are all left alone - a download does not replace the page, so a loader shown for one would stay on screen until the person navigated away.
- [x] It respects `prefers-reduced-motion`: the same component, without the motion, still communicating that something is happening
  - Measured: iteration count **1** and duration **0.00001s** under reduced motion, through the stylesheet's blanket rule rather than a rule of the loader's own. The first version added a second `prefers-reduced-motion` block for the loader; that broke the check which reads *the* block, and the blanket rule was already stronger - it covers the next looping thing too. The check now accepts either, rather than demanding the weaker of the two.
  - The visible label is what still communicates once the mark is still. An `aria-label` alone would have left a sighted person with a stopped logo and no way to tell they were waiting.
- [x] It has a determinate form where the duration is known (an upload, an export) and an indeterminate one where it is not, because a bar that lies about progress is the most irritating thing an interface does
  - `value` switches the two: with a number the component becomes a `progressbar` with its `aria-valuenow`, and renders a bar whose width **is** the number. With none it is a `status` and only the mark moves.
  - The bar has **no transition**, and a check refuses one. A width that eases toward its number is showing a number nobody sent - for the few hundred milliseconds of the ease, the bar is displaying progress that has not happened.
  - The bar is drawn in the mark's own teal rather than a generic progress colour, which is why `brand-peak-front` is a role of its own rather than sharing one with the rear peak.
- [ ] The animation is CSS or SVG, not a library, and its cost is measured - a loader that drops frames on a mid-range phone is a loader that makes the product feel slower than no loader at all
  - **The first half is done:** inline SVG with a CSS keyframe, no library, and the thing that moves is a `transform` on one path - which is the property a compositor can animate without touching layout or paint.
  - **The cost is still not measured, and the attempt is worth recording.** A check counted `requestAnimationFrame` ticks over a second with the CPU throttled 6x and reported 59.9fps. Its bite - a full-screen blur and hue-rotate on the overlay, about as expensive as a page animation gets - did not move the number at all.
  - It does not move because rAF in headless Chromium is scheduled on a timer rather than by the compositor: an **empty page throttled 6x also reports 62fps**. The measurement was of the scheduler, not of the paint, and a check built on it would have been green whatever the loader did. The throttling itself works - an 8-million-iteration loop goes from 16ms to 102ms - so the gap is in what rAF reports, not in the emulation.
  - The check was removed rather than kept. A green check that cannot fail is worse than an open criterion: the criterion is honest about not being met, and the check would have said it was. Counting real frames needs the compositor's own numbers - a headed browser or a screencast - and that is what this is waiting on. The reasoning that the animation *should* be cheap (a `transform` on one small path, which a compositor handles without layout or paint) is still only reasoning.
  - **It loops and runs for 1.6s, which the motion rules otherwise forbid**, and that is recorded as an exception rather than slipped past. "Nothing loops" was written against decoration - the `animate-ping` that arrived through a vendor view nobody asked for. Here the repetition *is* the information: it is what says the work has not finished, and a loader that stopped after 500ms would freeze while the page was still loading, which reads as broken rather than slow. The 500ms ceiling is about waiting, and nobody waits for this - the cycle runs beside the wait rather than adding to it.
  - The allowance is granted to a declaration **inside `.pf-loader__flow`** and nothing else: a bite that puts the same keyframes on another selector is still refused. Three further bites hold the conditions that make the exception defensible - the delay, the reduced-motion stop, and the bar with no transition - and the check also refuses the allowance itself once `.pf-loader__flow` is gone, because an allowance for a rule that has been deleted is an allowance for whatever is written next in its place.

### PF-280 · Skeletons where the wait is real
`—` · **5 pts** · depends: PF-279, PF-278 · standards: WCAG 2.2 AA · `type:design`

**Commits to:** a page that is honest about what it is about to show.

- [x] A skeleton is **the shape of the content that is coming**, not a grey box - a table skeleton has the table's columns, a tile skeleton has the tile's rows
  - Three shapes - `table`, `tile`, `text` - and the table's columns are at the grid's own fractions rather than six equal bars, because a list of five figures and a name does not look like six identical rectangles. The table skeleton is even zebra-striped, so it reads as that table rather than as a form.
- [x] It is used only where the wait is measured to be long enough to matter, and the measurement is recorded per screen; everywhere else the page simply arrives
  - **Measured, and the answer is nowhere.** Thirteen screens, five loads each, in a browser against the showcase workspace: medians from 84ms to 176ms, none over the 200ms threshold. [`docs/design/wait-budget.md`](../design/wait-budget.md) holds the table.
  - So the component exists and **no screen uses it**, and `SkeletonRules` enforces that: a template using `<x-skeleton>` that is not in the budget above the threshold is refused. The bite puts one on the invoice list and gets *"`/sales/invoices` is measured at 127ms, under the 200ms threshold"*.
  - That is the criterion working rather than being dodged. Putting a placeholder on a page that arrives in 127ms because placeholders are a thing products have is how a product acquires the feeling of being slower than it is - the eye registers that something changed and has no time to read what.
  - **The reason the numbers are this shape is architectural:** there is not one `fetch` or `XMLHttpRequest` in the product. Every screen is rendered by the server and arrives whole, so there is no moment where a page exists and its content does not - which is the moment a skeleton is for. The wait that does exist is between pages, and that is the loader's.
  - The budget says what would change this: a median crossing 200ms on real tenant data rather than the showcase, the first asynchronous load, or a long operation getting its own screen. It also says to re-measure, because a number in a document nobody re-took is a number that was true once.
- [x] A skeleton never shifts the layout when the content replaces it - the thing it is standing in for occupies the same box
  - Every dimension is the content's own token. The rows are `var(--row-height)` tall - the same density token `<x-grid>`'s rows use - so a skeleton is the right height in all three densities rather than at one of them. A check asserts that specifically, and its bite replaces the token with the literal that happens to match the default: the figure is correct at that density and wrong at the other two, which is exactly the bug a literal causes and the reason it is worth refusing.
- [x] It is announced to a screen reader as busy, and the announcement stops when the content lands
  - `aria-busy` on the wrapper with one sentence of real text, and the shapes themselves `aria-hidden`: reading out eleven empty boxes is not a description of anything. The announcement stops when the skeleton is replaced, because it is the element that carried it.
  - `aria-busy` alone says a region is busy and not what is coming, which is why the sentence is there as well.
- [x] Under `prefers-reduced-motion` the shimmer stops and the skeleton stays, because the shimmer is decoration and the placeholder is information
  - **Satisfied by there being no shimmer at all**, which is the stronger form of the same reasoning. The criterion's own words are the argument: the shimmer is decoration and the placeholder is information - so the decoration was not built. The house rule that nothing loops would have needed an exception for it, and the loader's exception is defensible because its repetition *is* information; a shimmer's is not.
  - The practical gain is that the reduced path and the ordinary one are one component rather than two kept in step by hand. A check refuses any animation on the skeleton, and its bite adds one.
