# Redesign plan — PeakFlow "Summit & Flow"

> **Status:** Proposal · **Date:** 2026-11-17 · **Extends:** [`design-system.md`](design-system.md) · [`interaction-and-motion.md`](interaction-and-motion.md) · [`accessibility.md`](accessibility.md) · [`rtl-and-i18n.md`](rtl-and-i18n.md) · [Phase 11](../tasks/phase-11-design-system.md)
>
> This document **does not replace** the existing design system; it builds on it. Every non-negotiable there still holds: contrast verified by a test, tabular figures, the accounting negative in parentheses, no motion inside a table, never colour alone, nothing fetched from a third party. What this plan adds is **visual identity, depth and interaction**, and it states exactly **where each modern style is allowed and where it is forbidden**.
>
> Tasks here carry the `UX-###` prefix so they never collide with `PF-###` ids in the backlog. Where a task extends an existing `PF` task, it says so.

---

## Contents

1. [The idea in two lines](#1-the-idea-in-two-lines)
2. [Governing principles](#2-governing-principles)
3. [The style matrix — where each style lives](#3-the-style-matrix)
4. [Colour system "Summit & Flow"](#4-colour-system-summit--flow)
5. [Typography and editorial figures](#5-typography-and-editorial-figures)
6. [Shape, layers and depth](#6-shape-layers-and-depth)
7. [Motion and micro-interactions](#7-motion-and-micro-interactions)
8. [Screen-by-screen blueprint](#8-screen-by-screen-blueprint)
9. [Tasks](#9-tasks)
10. [Definition of done and quality gates](#10-definition-of-done-and-quality-gates)
11. [Sources](#11-sources)

---

## 1. The idea in two lines

**"Calm precision where numbers are read; depth and life everywhere else."**

Tables, forms and reports — 80% of an accountant's day — are built on **Swiss Design + Flat 2.0 + Minimalism**: a strict grid, high contrast, zero ornament. Identity, sign-in, the dashboard and floating layers carry **Layered / Spatial / Glass / Dark Gradient / Bento / Motion**. Playful decoration (**Clay / Organic**) is confined to illustrations and empty states.

### The 80 / 15 / 5 visual budget

| Share | Surfaces | Styles allowed |
|------:|----------|----------------|
| **80%** | Tables, forms, reports, the invoice builder, bank reconciliation | Swiss · Flat 2.0 · Minimalism · Editorial Typography · Data-driven · High Contrast |
| **15%** | Dashboard, sidebar, floating layers (menus, command palette, dialogs), sign-in | Layered · Spatial · Bento · Glass · Dark Gradient · Aurora · Material You · Tactile · Motion |
| **5%** | Empty states, error pages, first-run welcome, moments of success | Claymorphism · Organic Shapes · Soft UI |

> **Why this split?** A financial system is used eight hours a day, and every visual effect inside the data area is paid for with the accountant's attention. That is why [`design-system.md`](design-system.md) says distinctiveness comes "from restraint executed precisely — not from gradients". This plan respects that, and gives identity its room **outside** the table.

---

## 2. Governing principles

1. **The figure is the hero.** Anything that competes visually with a financial figure is removed or toned down.
2. **Depth explains a relationship.** A higher layer means newer, more urgent or temporary. No shadow purely for looks.
3. **Glass only over a known background.** No `backdrop-filter` over a table or variable text, because contrast can no longer be guaranteed.
4. **Motion explains; it does not decorate.** 80–320ms, nothing over 500ms, nothing that loops, and nothing animates while a table is scrolled or edited.
5. **Colour + icon + word.** Every financial state reads without colour (WCAG 1.4.1).
6. **Five themes from one set of tokens.** Light · Graphite (dark) · Midnight · High Contrast Light · High Contrast Dark. No component writes `dark:` at the call site.
7. **Arabic first.** No `letter-spacing` on Arabic text (it breaks letter joining), no `uppercase` in Arabic, directional icons mirror and figures do not.
8. **Nothing from a third party at runtime.** Fonts, icons and illustrations are self-hosted; blob and clay artwork is SVG/CSS in the repository.
9. **Personalisation within limits.** A tenant may choose its brand colour (Material You), but financial-meaning colours (danger, warning, success, the negative) **can never be re-tinted**.

---

## 3. The style matrix

**Key:** ✅ core, everywhere · 🟢 used in specific places · 🟡 decorative only · ⛔ forbidden in data areas

| # | Style | Verdict | Where we use it | Where it is forbidden | Guard rail |
|---|-------|:------:|-----------------|-----------------------|------------|
| 1 | **Swiss Design** | ✅ | Grid, alignment, tables, reports, print | — | 12-column grid, spacing only from the 4px scale |
| 2 | **Minimalism** | ✅ | Every working screen | — | Every element must justify itself with a function |
| 3 | **Flat Design 2.0** | ✅ | Buttons, inputs, badges, tables | — | Flat surfaces + a light shadow for separation only + control borders ≥ 3:1 |
| 4 | **Editorial Typography** | ✅ | Balances, KPIs, page titles | Cells inside a table (they stay at density size) | Tabular figures always |
| 5 | **Data-driven UI** | ✅ | KPI cards, sparklines, receivables ageing, cash forecast, match confidence | — | Every chart has a table alternative and a text summary (PF-211) |
| 6 | **High Contrast UI** | ✅ | Two full themes + `prefers-contrast: more` + `forced-colors` | — | Text ≥ 7:1, controls ≥ 4.5:1, zero transparency |
| 7 | **Layered UI** | ✅ | Surface container levels, sidebar, cards | — | Five elevation levels; borders instead of shadows in dark themes |
| 8 | **Material You** | 🟢 | Tenant brand colour, public quote page, tonal tabs and buttons | Financial-meaning colours | The generator refuses hues near red, amber and green |
| 9 | **Bento Grid** | 🟢 | "Overview" dashboard, entity page, report summary | Tables and forms | Every tile has a figure + a trend + a drill-down link |
| 10 | **Spatial UI** | 🟢 | Drawers, dialogs, command palette, bulk-action bar, reconciliation | Scrolling inside tables | A fixed z-index map; motion originates from the element's source |
| 11 | **Tactile UI** | 🟢 | Button press, switches, checkboxes, the file drop zone | — | Targets ≥ 24×24 (2.5.8) at every density |
| 12 | **Motion Design / Micro-interactions** | 🟢 | Transitions, confirmations, card lift on hover, check-mark drawing | A table while scrolling or editing; anything that loops | `prefers-reduced-motion` tested |
| 13 | **Glass UI / Glassmorphism** | 🟢 | Command palette, sign-in card, top bar once scrolled, toasts | Over tables and variable text | Opaque fallback under `prefers-reduced-transparency` and in HC themes |
| 14 | **Dark Gradient / Dark Luxury** | 🟢 | The Midnight theme, sign-in, the welcome card | Behind tables (a table always sits on a solid surface) | Gradients on backgrounds only; text on solid surfaces |
| 15 | **Aurora / Gradient Mesh** | 🟡 | Sign-in background, empty-dashboard header, public page | Any working screen | **Static, no animation** (the rule: nothing loops) |
| 16 | **Organic Shapes** | 🟡 | Empty states, error pages, welcome, entity avatars | Functional containers and tables | SVG, `aria-hidden`, no functional meaning |
| 17 | **Claymorphism** | 🟡 | Soft 3D illustrations for empty states, welcome and "reconciliation complete" | Buttons, inputs, functional cards | The artwork is decorative; the message is always text |
| 18 | **Soft UI** | 🟡 | Welcome and settings surfaces | Tables | Soft shadows **with real borders** (the safe replacement for Neumorphism) |
| 19 | **Neumorphism** | ⛔ | Nowhere | Everywhere | Fails WCAG 1.4.11: control boundaries have no contrast. Soft UI takes its place |

> **A plain note on Neumorphism:** its appeal depends on a button being the same colour as its background, set apart only by a light and a dark shadow. By definition that is a boundary contrast near 1.1:1, so a low-vision user does not see the button at all. It is rejected despite its popularity, and Soft UI takes its place with real borders.

---

## 4. Colour system "Summit & Flow"

### 4.0 Superseded by the PeakFlow brand kit (2026-11-17)

> **The brand kit decides the colours, the type and the logo; this section's indigo "Summit" palette was a proposal made before the kit existed.** What shipped is built on the kit, with the METHOD of this section unchanged: fixed lightness per step, roles rather than colours, every pair measured. `resources/css/app.css` is the source of truth for the values; `php bin/verify design` holds them.

| Kit colour | Hex | Where it lives now |
|---|---|---|
| Midnight Navy | `#0F1E3D` | `--p-brand-navy`. Ink and the primary action in the light theme; the sidebar in every theme but high contrast; the `navy` ramp is built on its hue (263°) |
| Flow Teal | `#0E9C8F` | `--p-brand-teal`. The `accent` role on light - graphics, indicators, the sparkline. White on it is 3.40:1, so it is never a text colour or a fill under text; links and `tertiary` use a deeper step of its hue (184.4°). Step 500 of the `flow` ramp IS the brand teal |
| Glacier Teal | `#2DD4BF` | `--p-brand-glacier`. Primary action, accent and focus in the dark themes, with navy ink on it (8.86:1); the sidebar's "you are here" marker |
| Summit Amber | `#FFB547` | `--p-brand-amber`. The logo's summit dot, a faint glow in the aurora, and the high-contrast-dark focus ring - nothing else, because amber also means "warning" |
| Mist | `#F3F6FA` | `--p-brand-mist`. The page background in the light theme; the neutral ramp is built on its hue (255.5°) |

- **All five convert back to their exact hex**, and `bin/verify design` fails if one is "adjusted".
- **Type:** Plus Jakarta Sans for Latin (Medium 500 body, Bold 700 headings) and Readex Pro for Arabic, self-hosted at build time. **Figures stay in IBM Plex Sans**: measured, Plus Jakarta Sans has no tabular figures - with `tabular-nums` on, "1111111" set 131 px and "8888888" 140 px - and a figure column whose digits differ in width cannot be scanned.
- **Logo:** `<x-brand.logo>` (`resources/views/components/brand/logo.blade.php`) applies the kit's rules - full from 200 px, small from 120 to 200 px, the PF monogram under 120 px, the Arabic artwork for Arabic pages, and one artwork per theme (colour on light, colour on dark, black and white for high contrast), never recoloured. Masters are in `public/brand/`; favicons, the touch icon and `site.webmanifest` in `public/`.
- **Contrast after the change:** 57 pairs (43 text, 14 control/graphic) × 5 themes = 285 measurements, all passing. The tables in 4.3–4.4 below record the earlier proposal and are kept for the reasoning, not the values.

### 4.1–4.4 Removed: the pre-brand-kit proposal

> These four sections held the indigo "Summit" palette — its story, its hue table, its primitives and its
> semantic roles — as proposed **before the brand kit existed**. Section 4.0 above records what replaced
> them and why, and keeps the part that survived: the method. Fixed lightness per step, roles rather than
> colours, every pair measured.
>
> The values themselves are deleted rather than kept "for the reasoning". A table of hexes that are no
> longer the hexes is a table somebody reads and uses, and a document that holds two palettes is a
> document whose reader has to know which half is dead. `resources/css/app.css` is the source of truth
> for the values and carries the reasoning beside each one; `php bin/verify design` holds them to it.

### 4.5 Contrast — measured, not estimated

**53 legitimate pairs (43 text, 10 control/graphic) × 5 themes = 265 measurements**, computed with the WCAG 2.2 formula. The pairs are declared in [`resources/css/contrast-pairs.json`](../../resources/css/contrast-pairs.json) and measured from `app.css` itself by `php bin/verify design`. Targets: text ≥ 4.5:1, controls and graphics ≥ 3:1; in the HC themes text ≥ 7:1 (AAA) and controls ≥ 4.5:1. **Result: 265/265 pass.**

| Pair | Light | Graphite | Midnight | HC Light | HC Dark |
|---|---:|---:|---:|---:|---:|
| `on-surface` / `surface` | 17.75 | 16.63 | 18.63 | 20.24 | 20.24 |
| `on-surface-muted` / `surface` | 6.95 | 11.05 | 11.41 | 14.37 | 16.19 |
| `link` / `surface` | 5.30 | 11.18 | 11.57 | 10.20 | 14.99 |
| `on-primary` / `primary` | 5.30 | 8.07 | 8.14 | 10.20 | 14.99 |
| `figure-negative` / `surface` | 7.84 | 10.83 | 11.21 | 10.64 | 14.70 |
| `on-danger-subtle` / `danger-subtle` | 9.94 | 15.28 | 15.28 | 16.61 | 15.28 |
| `on-warning-subtle` / `warning-subtle` | 12.90 | 15.01 | 15.01 | 16.24 | 15.01 |
| `on-success-subtle` / `success-subtle` | 8.81 | 14.81 | 14.81 | 15.99 | 14.81 |
| `on-sidebar-muted` / `sidebar` | 6.91 | 7.06 | 7.60 | 14.75 | 16.19 |
| `outline-control` / `surface` | 4.76 | 4.05 | 7.43 | 17.75 | 16.19 |
| `focus-ring` / `background` | 3.46 | 11.72 | 12.28 | 10.20 | 14.89 |
| **Lowest text ratio in the theme** | **4.76** | **6.67** | **7.43** | **9.17** | **12.83** |

> **What the measurement caught:** in the first draft, input borders (`neutral-400` on white) measured **2.89:1** — a WCAG 1.4.11 failure by a margin the eye does not notice. So a new role, `--c-outline-control`, now exists separately from the decorative `--c-outline`. That is exactly the kind of defect the PF-200 test exists for.

### 4.6 Chart colours (Data-driven)

The **categorical** palette is derived from the hue order of Okabe-Ito, known for being safe under colour-vision deficiency, then re-toned in OKLCH so that (a) every series clears 3:1 against the surface and (b) every series takes a **different lightness**, so telling series apart never relies on hue alone. The lightnesses were picked by an optimiser that maximises the smallest colour distance (ΔE in OKLab) under the Machado (2009) simulation of all three types of colour blindness.

| # | Series | Light | Dark / Midnight |
|--:|--------|-------|-----------------|
| 1 | Summit blue | `oklch(44% 0.163 258)` #004DAA · 7.68:1 | `oklch(74% 0.135 258)` #74ACFF · 8.35:1 |
| 2 | Amber | `oklch(64% 0.144 65)` #C67600 · 3.38:1 | `oklch(74% 0.145 65)` #E89537 · 8.08:1 |
| 3 | Flow teal | `oklch(64% 0.116 180)` #00A38F · 3.06:1 | `oklch(62% 0.111 180)` #0D9B89 · 5.58:1 |
| 4 | Vermillion | `oklch(48% 0.182 32)` #AD1B00 · 6.91:1 | `oklch(62% 0.17 32)` #D9553F · 4.89:1 |
| 5 | Orchid | `oklch(40% 0.17 345)` #80005C · 9.79:1 | `oklch(90% 0.065 345)` #FFCDE8 · 13.93:1 |
| 6 | Sky | `oklch(56% 0.112 230)` #007FA9 · 4.40:1 | `oklch(78% 0.111 230)` #64C4F0 · 9.83:1 |
| 7 | Olive | `oklch(44% 0.095 108)` #585600 · 7.39:1 | `oklch(82% 0.119 108)` #CBCA6A · 11.21:1 |
| 8 | Violet | `oklch(56% 0.19 300)` #8851D1 · 4.89:1 | `oklch(62% 0.162 300)` #966BD8 · 4.96:1 |

**Smallest distance between the first five series (ΔE OKLab):**

| Vision | Light | Dark |
|---|---:|---:|
| Typical | 0.162 | 0.152 |
| Protanopia | 0.140 | 0.122 |
| **Deuteranopia** (the most common) | **0.138** | **0.112** |
| Tritanopia | 0.124 | 0.108 |

> **What the measurement caught:** the first ordering, with alternating lightness bands, made Amber and Vermillion **almost identical (ΔE = 0.007)** for someone with deuteranopia — around 6% of men. After optimisation the distance is 0.138, roughly 20 times larger.

**Chart rules:**

- **At most 5 coloured series.** Anything beyond is grouped as "Other" (grey `neutral-400`) or labelled directly on the lines.
- **Direct labels** at the end of each line rather than relying on a legend wherever possible.
- **Patterns (decal)** in ECharts are always on in the HC themes and optional elsewhere.
- **Sequential** (receivables ageing, intensity): the summit ramp 100 → 800: #E7EFFF → #CFDEFF → #ABC5FF → #7AA1FF → #4E7CFF → #3761E1 → #274ABD → #1B3897.
- **Diverging** (variance against budget): vermillion ↔ neutral ↔ blue, which is safe for colour blindness — **never red ↔ green**: #992400 · #C26B4C · #E0B29E · **#EDEEF1** · #A1C1E4 · #4C89CC · #0053A5.
- **The time axis stays left-to-right** even in RTL, exactly as figures do not flip. Titles, legends and text labels mirror. (Recorded in [`rtl-and-i18n.md`](rtl-and-i18n.md) by UX-022.)

### 4.7 Gradients (Dark Gradient · Aurora)

Defined as tokens and never written inside a template:

```css
:root {
  /* Sign-in, welcome, empty-dashboard header - static, no animation */
  --g-aurora:
    radial-gradient(60% 50% at 15% 20%, oklch(var(--p-summit-500) / 0.35), transparent 70%),
    radial-gradient(50% 45% at 85% 10%, oklch(var(--p-flow-400) / 0.28), transparent 70%),
    radial-gradient(55% 50% at 70% 95%, oklch(var(--p-insight-500) / 0.22), transparent 70%),
    linear-gradient(160deg, oklch(var(--p-midnight-1000)), oklch(var(--p-midnight-900)));

  /* Midnight theme page background - behind content only; tables sit on a solid surface */
  --g-midnight-page: linear-gradient(170deg, oklch(var(--p-midnight-1000)) 0%, oklch(var(--p-midnight-950)) 55%, oklch(20% 0.06 285) 100%);

  /* The sign-in primary action only - white text ≥ 5.30:1 at every stop */
  --g-brand-cta: linear-gradient(135deg, oklch(var(--p-summit-600)), oklch(var(--p-insight-600)));

  /* Lit edge for Midnight cards (Dark Luxury) */
  --g-edge-glow: linear-gradient(180deg, oklch(100% 0 0 / 0.10), oklch(100% 0 0 / 0.02));
}
```

### 4.8 Material You — the tenant brand colour

- A tenant chooses **one seed colour**. The generator takes **only its hue (H)**, applies it to **the same fixed lightness table** from 4.2, then clips chroma to sRGB.
- **Blocked seed ranges:** hues 10°–45° (collide with danger), 55°–95° (warning) and 135°–165° (success). A seed in one of them is shifted to the nearest safe edge, **and the user is told why**.
- After generation the contrast matrix (4.5) is re-run on the new ramp. **Any failure falls back to Summit**, with a message saying why.
- What takes the brand colour: `primary`, `primary-subtle`, `surface-selected`, `focus-ring`, the public quote page and the printed document header. **What never does:** danger, warning, success, figure-negative and the sidebar.

---

## 5. Typography and editorial figures

| Token | Size | Weight | Used for |
|---|---|---|---|
| `--text-display-xl` | `clamp(2.5rem, 2rem + 2vw, 3.75rem)` | 600 | The headline balance on the welcome card |
| `--text-display` | `clamp(2rem, 1.6rem + 1.5vw, 3rem)` | 600 | The KPI figure in a large Bento tile |
| `--text-headline` | `1.5rem` | 600 | Page title |
| `--text-title` | `1.125rem` | 600 | Card or section title |
| `--text-body` | `var(--font-step)` (13–15px by density) | 400 | Body text and tables |
| `--text-label` | `0.8125rem` | 500 | Labels and column headers |
| `--text-caption` | `0.75rem` | 400 | Notes and units |

**Editorial figures:**

- `font-family: var(--font-figure)` (IBM Plex Sans) with `tabular-nums lining-nums`, **always**.
- The currency code is **smaller and lighter**: `SAR` at 0.45em in `on-surface-muted`, aligned to the baseline. The figure is the hero; the unit is context.
- Decimals in large display figures use weight 400 and the muted colour: **128,450**.75. It reads "the important part first" without dropping precision.
- `letter-spacing: -0.02em` on large Latin figures only. **Never on Arabic text**, where it breaks letter joining.
- Arabic lines: `line-height: 1.6` for body and `1.3` for headings — a scale tuned on Latin crowds Arabic.

---

## 6. Shape, layers and depth

### 6.1 Radius — organic, but disciplined

| Role | Value | Note |
|---|---|---|
| Table cell, progress bar | 0–4px | Tables are strictly Swiss |
| Input, select | 8px | |
| Button | 10px | |
| Chip, avatar | full | |
| Card | 16px | |
| Bento tile | 20px | |
| Dialog | 24px | |
| Bottom sheet, sign-in card | 28px | |
| Organic shapes | `border-radius: 42% 58% 63% 37% / 41% 44% 56% 59%` or an SVG blob | Decorative only, `aria-hidden` |

### 6.2 Surface levels (Layered — Material tone-based)

| Level | Light | Graphite | Midnight | Used for |
|---|---|---|---|---|
| `container-lowest` | neutral-0 | neutral-1000 | midnight-1000 | The table area background |
| `container-low` | neutral-25 | neutral-950 | midnight-950 | Cards |
| `container` | neutral-50 | neutral-900 | midnight-900 | Side panels |
| `container-high` | neutral-100 | neutral-850 | midnight-850 | Menus and popovers |
| `container-highest` | neutral-150 | neutral-800 | midnight-800 | The selected item in a menu |

### 6.3 Elevation — shadow and border together

| Level | Light (shadow) | Dark / Midnight (border + highlight) | Used for |
|---|---|---|---|
| e0 | none | none | Flat surfaces |
| e1 | `0 1px 2px oklch(20% .02 264/.06), 0 1px 1px oklch(20% .02 264/.04)` | `1px solid outline` + `inset 0 1px 0 oklch(100% 0 0/.04)` | Cards |
| e2 | `0 4px 12px -2px oklch(20% .02 264/.08)` | + an `outline-strong` border | A hovered card, a stuck header |
| e3 | `0 12px 24px -6px oklch(20% .02 264/.12)` | + `inset 0 1px 0 oklch(100% 0 0/.06)` | Menus and popovers |
| e4 | `0 24px 48px -12px oklch(20% .02 264/.18)` | + `--g-edge-glow` | Drawers |
| e5 | `0 32px 64px -16px oklch(20% .02 264/.24)` | + glow | Dialogs and the command palette |

### 6.4 Depth map (Spatial — fixed z-index)

| z | Layer |
|---:|---|
| 0 | The page |
| 10 | Sticky table headers, the page bar |
| 20 | The sidebar |
| 30 | The floating bulk-action bar; the reconciliation "remaining difference" bar |
| 40 | Menus, tooltips, popovers |
| 50 | Drawers |
| 60 | Dialogs + scrim |
| 70 | The command palette |
| 80 | Toasts |
| 90 | The skip link |

### 6.5 Glass — the only recipe allowed

```css
.glass {
  background: oklch(var(--c-surface) / 0.72);
  backdrop-filter: blur(20px) saturate(140%);
  border: 1px solid oklch(100% 0 0 / 0.10);
  box-shadow: var(--elevation-4);
}
/* The opaque fallback: Safari does not support reduced-transparency, so the default must be safe too */
@media (prefers-reduced-transparency: reduce) { .glass { background: oklch(var(--c-surface)); backdrop-filter: none; } }
@media (prefers-contrast: more)              { .glass { background: oklch(var(--c-surface)); backdrop-filter: none; border-width: 2px; } }
[data-theme^='hc'] .glass                    { background: oklch(var(--c-surface)); backdrop-filter: none; }
@supports not (backdrop-filter: blur(1px))   { .glass { background: oklch(var(--c-surface) / 0.96); } }
```

**The rule:** glass is allowed only over a **known, tested** background — the aurora, the dark scrim or a fixed surface. No more than **3** `backdrop-filter` elements may be visible at once, because it is expensive on the GPU.

---

## 7. Motion and micro-interactions

### 7.1 Tokens

| Token | Value | Used for |
|---|---|---|
| `--duration-instant` | 80ms | Button press, switch toggle |
| `--duration-fast` | 120ms | Hover colour change, tooltip in |
| `--duration-base` | 180ms | Menus, expand and collapse |
| `--duration-slow` | 240ms | Drawers, toasts |
| `--duration-emphasized` | 320ms | Dialogs, page transitions (View Transitions) |
| **Maximum** | **500ms** | Nothing above it. Nothing loops |
| `--ease-standard` | `cubic-bezier(0.2, 0, 0, 1)` | The default (exists today) |
| `--ease-emphasized-out` | `cubic-bezier(0.05, 0.7, 0.1, 1)` | Elements entering |
| `--ease-emphasized-in` | `cubic-bezier(0.3, 0, 0.8, 0.15)` | Elements leaving |
| `--ease-tactile` | `linear(0, 0.6 18%, 1.04 45%, 0.99 70%, 1)` | A slight rebound on press release (controls only) |

### 7.2 The micro-interaction catalogue

| Interaction | Behaviour | Duration | Note |
|---|---|---|---|
| Button press (Tactile) | `scale(0.98)` + inner shadow, then rebound on `--ease-tactile` | 80ms / 180ms | Both `pointer: coarse` and `fine` |
| Hover on a Bento tile | `translateY(-2px)` + e1 → e2 | 180ms | Only under `(hover: hover)` |
| Checkbox | Check mark drawn via `stroke-dashoffset` | 180ms | |
| Switch | Thumb slides + slight rebound | 180ms | Mirrors in RTL |
| Copy an IBAN or reference | Icon turns into ✓ + "Copied" in a live region | 1.5s, then back | |
| Posting or issuing succeeded | Drawn ✓ + toast, **only after the server confirms** | 240ms | Never optimistic about money |
| Totals "calculating" | "Calculating…" text + the old figure dimmed to 50% | Immediate | No spinner hiding the figure |
| Focus ring appears | `outline-offset` from 4px to 2px | 120ms | |
| KPI figure on first dashboard load | Count-up on an `aria-hidden` copy while the final value is in the DOM | 400ms | Off under reduced motion, **never in tables** |
| A row changed after server confirmation | `primary-subtle` background flash that fades | 400ms | Once, never during scrolling |
| Skeleton | **Static, no shimmer** | — | The rule: nothing loops |
| Field error | Colour + icon + message | Immediate | **No shake** — it is a vestibular trigger |

### 7.3 Spatial transitions

- **A drawer** enters from the `inline-end` side — from the left in Arabic, from the right in English — using a logical `translate`.
- **A dialog** scales from 0.96 to 1 with opacity, with `transform-origin` at the button that opened it.
- **List → detail** uses the View Transitions API with a shared `view-transition-name` on the element, falling back silently to no motion.
- **The background behind a dialog:** a scrim of `oklch(var(--p-midnight-1000) / 0.55)`. **The content behind is not scaled down**, because scaling a table makes its text shimmer.

---

## 8. Screen-by-screen blueprint

### 8.1 The shell — `layouts/app.blade.php`

- **A sidebar that is always dark** (`--c-sidebar`, does not invert): logo, navigation with icons and labels, collapsible groups; the active item on `sidebar-selected` with a 3px `flow-400` indicator on the `inline-start` side.
- **The top bar** is solid and becomes **glass only once the page scrolls** (because page content is then behind it, the glass uses 0.92 rather than 0.72 to keep contrast). It holds search (Ctrl+K), the entity switcher, notifications and the user menu.
- **The entity and period indicator** (PF-204): an always-visible chip — "Demo Trading Co · SAR · Period: November 2026 (open)" — whose border turns to warning when the period is closed.
- **Theme and density switcher** in the user menu: Light / Graphite / Midnight / High contrast / Automatic, and density Comfortable / Compact / Dense.

### 8.2 Sign-in — `auth/login.blade.php` and `ops/login.blade.php`

**Dark Gradient + Aurora + Glass + Organic:**

- A full-screen `--g-aurora` background, **static**.
- An organic SVG shape behind the card at 12% `flow-400`, `aria-hidden`.
- A glass card, 28px radius, 420px wide. **The inputs inside are solid** (`surface`); the glass is for the frame only.
- The "Sign in" button uses `--g-brand-cta`, with white text ≥ 5.30:1.
- An Arabic / English switcher above the card.
- **The operator console:** the same design, but the glow and the focus ring are **Insight Violet**, with an "Operator console" badge — so the planes cannot be confused.

### 8.3 Overview — `app/home.blade.php` (Bento + Data-driven + Editorial)

A 12-column grid on wide screens, 6 on medium and a single column on mobile:

```
┌───────────────────────────────┬───────────────┬───────────────┐
│ Cash position  (6×2)          │ Receivables   │ Payables      │
│ 128,450.75 SAR  ▲ 4.2% (text) │ ageing        │ due this week │
│ 90-day chart + forecast band  │ (sequential)  │               │
│                               ├───────────────┴───────────────┤
│                               │ Bank reconciliation progress  │
│                               │ 82% matched · 14 lines waiting│
├───────────────┬───────────────┼───────────────────────────────┤
│ Unposted      │ Tax period    │ Recent activity  (6)          │
│ drafts (3)    │ status (3)    │ who did what, and when        │
├───────────────┴───────────────┼───────────────────────────────┤
│ Quick actions (6)             │ Match suggestions (Insight)(6)│
└───────────────────────────────┴───────────────────────────────┘
```

- **Every figure drills down**: clicking any figure opens the journal lines that produced it. This is the README's third rule — every balance is explainable.
- **A trend is never only a colour:** "▲ 4.2% vs last month" — arrow + sign + words.
- **The welcome card** when there is no data: a soft aurora + a clay illustration + three steps to get started.

### 8.4 Choose entity — `app/choose-entity.blade.php`

Cards with an organic avatar (the entity's first letter inside a blob tinted from the chart series), the functional currency, the country and the last activity. Above 6 entities it becomes a searchable list.

### 8.5 Bank import — `banking/import-*.blade.php`

- **The drop zone (Tactile):** a dashed `outline-control` border; on drag-over it turns `primary-subtle`, the border goes solid `primary` and the icon lifts 4px. Fully keyboard operable ("Choose a file" button).
- **The preview:** a strict Swiss table with a sticky header and tabular figures; error rows on `danger-subtle` + an icon + the error text.

### 8.6 Bank reconciliation (Spatial)

Two side-by-side panes — bank lines and open documents — and a **floating "remaining difference" bar (z-30) in dark glass** that is always visible. Match suggestions in Insight with a confidence meter (bar + percentage + a readable reason). When reconciliation completes, one moment of success: a small clay illustration and a message.

### 8.7 The invoice builder

A Swiss line grid, a totals panel stuck to the `inline-end` side with a visible "calculating" state, and a live validation panel with icons and text. The "Issue" button is visually separate and is not reachable by `Enter`.

### 8.8 The command palette (Ctrl / ⌘ + K)

Glass over a dark scrim, 640px wide with a 24px radius, named sections, icons and keyboard shortcuts. Enters on `--duration-emphasized`, leaves on `--duration-fast`.

### 8.9 Error pages — `errors/*.blade.php`

A large organic shape + a clay illustration per case (403 a lock, 404 a compass, 419 an hourglass, 500 a gear, 503 tools), with a clear title, what happened and what can be done. **The text message is always enough on its own.**

### 8.10 The operator console — `layouts/ops.blade.php`

The same shell, but the active indicator, focus ring and header badge are **Insight Violet**, with a permanent "You are in the platform console". A colour identity for the plane prevents the "I thought I was inside the customer's account" mistake.

### 8.11 The public quote page — `public/quote.blade.php`

Takes the tenant's brand colour (Material You), a very soft aurora in the header, and a Swiss line table. **Printing from it is always light.**

### 8.12 Print

No change to the rules: every theme is forced to light for print — no gradients, no glass, no shadows — and the draft watermark is a word.

---

## 9. Tasks — moved to the backlog

> This section held 41 UX tasks and 172 points. They are now in
> **[Phase 11 — the design system](../tasks/phase-11-design-system.md)**, which is the backlog
> `bin/verify repo` keeps honest: it counts the criteria, compares them against the progress table, and
> fails the build when the two disagree.
>
> Two backlogs for one body of work is the problem this project keeps refusing everywhere else — a
> second place holding the same truth, drifting from the first. Nothing checked this one, so the day
> a task shipped there was no mechanism to notice that it had.
>
> What this document is for is the part that cannot live in a task list: the **reasoning** behind the
> colour method, the shape and depth recipes, the motion budget, the glass rule, and the screen-by-screen
> blueprint in section 8. Phase 11 implements against those, and the sections above are where an
> argument about a token belongs.

## 10. Definition of done and quality gates

A `UX` task is not closed unless:

- [ ] The contrast matrix (UX-003) passes in **all five themes**
- [ ] The screen is tested in **RTL and LTR**, and at **all three densities**
- [ ] `prefers-reduced-motion`, `prefers-reduced-transparency`, `prefers-contrast: more` and `forced-colors` are each tested, not assumed
- [ ] No colour carries meaning alone — every state has an icon + a word
- [ ] Nothing is fetched from a third party (the PF-017 rule in `bin/verify`)
- [ ] No motion in tables, no duration > 500ms, nothing that loops
- [ ] Print is still light and clean
- [ ] `npm run build` runs **after** `php artisan view:cache`, never after `view:clear` (the documented trap)

---

## 11. Sources

- Stripe — [Designing accessible color systems](https://stripe.com/blog/accessible-color-systems): fixed lightness per step, contrast as a property of step distance
- Material Design — [Tone-based surface color in M3](https://material.io/blog/tone-based-surface-color-m3): the `surface-container` levels
- Android Developers — [Material 3 Expressive color system](https://developer.android.com/design/ui/wear/guides/styles/color/system): dynamic colour from a seed
- Chrome for Developers — [CSS prefers-reduced-transparency](https://developer.chrome.com/blog/css-prefers-reduced-transparency): supported from Chrome / Edge 118, not in Safari — hence a safe default
- Web Platform DX — [prefers-reduced-transparency support](https://web-platform-dx.github.io/web-features-explorer/features/prefers-reduced-transparency)
- data.europa.eu — [Accessible colour palettes](https://data.europa.eu/apps/data-visualisation-guide/accessible-colour-palettes), and Datawrapper — [Colorblindness, part 2](https://www.datawrapper.de/blog/colorblindness-part2): Okabe-Ito and direct labelling
- GitLab — [Modernize data visualization color system](https://gitlab.com/gitlab-org/gitlab-services/design.gitlab.com/-/work_items/3378): higher lightness and lower chroma in dark themes
- Machado, Oliveira & Fernandes (2009), *A Physiologically-based Model for Simulation of Color Vision Deficiency*: the simulation matrices used in 4.6
- W3C — WCAG 2.2: success criteria 1.4.1, 1.4.3, 1.4.6, 1.4.11, 1.4.13, 2.4.11 and 2.5.8
- 2026 trends (directional, not normative): [SaaS dashboard design trends 2026](https://dev.to/thekitbase/saas-dashboard-design-trends-in-2026-with-examples-121m) · [Fintech UI/UX 2026](https://www.theskinsfactory.com/uiux-design-blog/fintech-ui-ux-design) · [Fintech dashboard examples](https://adminlte.io/blog/fintech-dashboard-design-examples/) · [Best colour palette for fintech](https://www.inspoai.io/blog/best-color-palette-for-fintech-app)

> **How the figures were produced:** the values in 4.3–4.6 were generated by a script that converts OKLCH to sRGB with binary-search chroma clipping, computes contrast with the WCAG 2.2 formula, and simulates colour-vision deficiency with the Machado matrices in linear RGB. UX-003 moves the same algorithm into `bin/verify`, so the figures in this document stay reproducible.
