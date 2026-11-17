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

### 4.1 The story

| Role | Name | Hue | Meaning | Why this choice |
|------|------|:---:|---------|-----------------|
| **Primary** | **Summit Indigo** | 266° | Trust, decision, action | Blue-indigo is the global language of trust in finance (banks, Stripe, Mercury); 266° sets it apart from the generic 240° blue |
| **Tertiary / brand accent** | **Flow Teal** | 192° | Money in motion, cash flow | Separates the brand from "every blue fintech", works superbly in dark mode, and is not green, so it never reads as "profit" |
| **Insight** | **Insight Violet** | 300° | Automation, match suggestions, **the operator console's identity** | Separates "what the machine proposed" from "what a human decided", and tells an operator at once they are in the Platform plane |
| Success | — | 155° | Matched, posted | For states only — **not for positive figures** |
| Warning | — | 70° | Needs a human, suspense | — |
| Danger | — | 25° | Errors, **the accounting negative** | — |
| Neutral | Cool Slate | 264° | Surfaces and text | A cool grey with a hint of indigo, because a warm grey next to a red reads as orange |
| Midnight | — | 274° | Surfaces of the luxurious Midnight theme | A dark tinted with indigo rather than a dead black |

**Why neither gold nor green as the brand colour?** Gold collides with the amber of "warning" and green with "success". More importantly, a green brand breaks the rule that **a positive figure is neutral** — money is not good news by default.

### 4.2 The generation principle: fixed lightness per step

Every ramp uses **the same lightness L at each step**, whatever the hue — the principle Stripe described when it rebuilt its colours. The result is that contrast becomes a property of **the distance between steps** rather than of the hue: text at step 700 on step 50 passes in every family.

| Step | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950 |
|------|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| **L (OKLCH)** | 97.8% | 95% | 90% | 82.5% | 72% | 62.5% | 54% | 46% | 38.5% | 30% | 22% |

Chroma follows a curve that peaks at 500–600 and is then clipped automatically to stay inside sRGB, so the hex values below are exactly displayable.

### 4.3 Primitives

Format: the `L C H` triple as stored in `app.css` (consumed as `oklch(var(--p-summit-600))`), then the equivalent hex.

| Step | summit (primary) | flow (tertiary) | insight | success | warning | danger |
|---|---|---|---|---|---|---|
| 50 | `97.8% 0.01 266` #F4F8FF | `97.8% 0.01 192` #F1FAF9 | `97.8% 0.012 300` #F9F6FF | `97.8% 0.012 155` #F2FAF4 | `97.8% 0.013 70` #FEF6EE | `97.8% 0.011 25` #FFF5F4 |
| 100 | `95% 0.024 266` #E7EFFF | `95% 0.022 192` #DEF4F2 | `95% 0.028 300` #F1EBFF | `95% 0.027 155` #E1F4E7 | `95% 0.03 70` #FCECDA | `95% 0.025 25` #FFE9E6 |
| 200 | `90% 0.048 266` #CFDEFF | `90% 0.044 192` #BEE8E5 | `90% 0.057 300` #E3D6FF | `90% 0.052 155` #C4E9CF | `90% 0.058 70` #F7D8B5 | `90% 0.052 25` #FFD1CD |
| 300 | `82.5% 0.087 266` #ABC5FF | `82.5% 0.075 192` #8AD6D2 | `82.5% 0.103 300` #D0B7FF | `82.5% 0.09 155` #96D7AC | `82.5% 0.099 70` #EFBB7D | `82.5% 0.098 25` #FFADA6 |
| 400 | `72% 0.145 266` #7AA1FF | `72% 0.106 192` #3FBAB6 | `72% 0.17 300` #B688FE | `72% 0.128 155` #5ABC81 | `72% 0.14 70` #DC932E | `72% 0.174 25` #FF716B |
| 500 | `62.5% 0.201 266` #4E7CFF | `62.5% 0.107 192` #009C98 | `62.5% 0.2 300` #9C63EC | `62.5% 0.15 155` #13A15E | `62.5% 0.135 70` #BB7600 | `62.5% 0.21 25` #EC3E41 |
| 600 | `54% 0.201 266` #3761E1 | `54% 0.093 192` #007F7C | `54% 0.196 300` #8348CD | `54% 0.134 155` #00844A | `54% 0.117 70` #996000 | `54% 0.206 25` #CB1B28 |
| 700 | `46% 0.184 266` #274ABD | `46% 0.079 192` #006663 | `46% 0.18 300` #6A34AB | `46% 0.114 155` #006A3A | `46% 0.1 70` #7B4C00 | `46% 0.187 25` #A80019 |
| 800 | `38.5% 0.16 266` #1B3897 | `38.5% 0.066 192` #004F4D | `38.5% 0.156 300` #532588 | `38.5% 0.095 155` #00522C | `38.5% 0.084 70` #603A00 | `38.5% 0.156 25` #830011 |
| 900 | `30% 0.133 266` #0E236F | `30% 0.052 192` #003634 | `30% 0.13 300` #3A1463 | `30% 0.074 155` #00381C | `30% 0.065 70` #422700 | `30% 0.122 25` #5D0009 |
| 950 | `22% 0.102 266` #051249 | `22% 0.038 192` #00201F | `22% 0.1 300` #230741 | `22% 0.055 155` #00220F | `22% 0.048 70` #291600 | `22% 0.09 25` #3A0003 |

**Neutral — Cool Slate (264°)**

| Token | OKLCH | Hex | | Token | OKLCH | Hex |
|---|---|---|---|---|---|---|
| neutral-0 | `100% 0 264` | #FFFFFF | | neutral-500 | `55.5% 0.02 264` | #6D737F |
| neutral-25 | `98.9% 0.003 264` | #FAFBFD | | neutral-600 | `46.5% 0.021 264` | #535A66 |
| neutral-50 | `97.6% 0.005 264` | #F5F7FB | | neutral-700 | `37.5% 0.02 264` | #3C414C |
| neutral-100 | `95% 0.007 264` | #ECEEF3 | | neutral-800 | `28.5% 0.018 264` | #262A33 |
| neutral-150 | `92.4% 0.009 264` | #E3E6EC | | neutral-850 | `24.5% 0.017 264` | #1C2029 |
| neutral-200 | `89.5% 0.01 264` | #D9DCE3 | | neutral-900 | `21% 0.016 264` | #151820 |
| neutral-300 | `82% 0.013 264` | #C0C4CD | | neutral-950 | `16.5% 0.014 264` | #0B0E15 |
| neutral-400 | `68% 0.017 264` | #9398A3 | | neutral-1000 | `12.5% 0.012 264` | #05060B |

**Midnight (274°) — surfaces of the Midnight theme and the sidebar**

| Token | OKLCH | Hex | | Token | OKLCH | Hex |
|---|---|---|---|---|---|---|
| midnight-1000 | `11% 0.03 274` | #03040F | | midnight-700 | `31% 0.052 274` | #282E4B |
| midnight-950 | `14% 0.038 274` | #050719 | | midnight-600 | `40% 0.05 274` | #3F4663 |
| midnight-900 | `17.5% 0.045 274` | #0B0E24 | | midnight-400 | `70% 0.04 274` | #969DB8 |
| midnight-850 | `21% 0.05 274` | #11162F | | midnight-300 | `82% 0.03 274` | #BEC3D8 |
| midnight-800 | `25% 0.052 274` | #1A1F3A | | midnight-100 | `95% 0.012 274` | #ECEEF7 |

### 4.4 Semantic roles across the five themes

| Role | Light | Graphite (dark) | Midnight | HC Light | HC Dark |
|---|---|---|---|---|---|
| `--c-background` | neutral-50 #F5F7FB | neutral-1000 #05060B | midnight-1000 #03040F | neutral-0 #FFFFFF | neutral-1000 #05060B |
| `--c-surface` | neutral-0 #FFFFFF | neutral-950 #0B0E15 | midnight-950 #050719 | neutral-0 #FFFFFF | neutral-1000 #05060B |
| `--c-surface-container` | neutral-50 #F5F7FB | neutral-900 #151820 | midnight-900 #0B0E24 | neutral-0 #FFFFFF | neutral-1000 #05060B |
| `--c-on-surface` | neutral-900 #151820 | neutral-100 #ECEEF3 | neutral-50 #F5F7FB | neutral-1000 #05060B | neutral-0 #FFFFFF |
| `--c-on-surface-muted` | neutral-600 #535A66 | neutral-300 #C0C4CD | midnight-300 #BEC3D8 | neutral-800 #262A33 | neutral-150 #E3E6EC |
| `--c-outline` (decorative dividers) | neutral-200 #D9DCE3 | neutral-800 #262A33 | midnight-800 #1A1F3A | neutral-700 #3C414C | neutral-300 #C0C4CD |
| `--c-outline-control` (**new**: input borders ≥ 3:1) | neutral-500 #6D737F | neutral-500 #6D737F | midnight-400 #969DB8 | neutral-900 #151820 | neutral-150 #E3E6EC |
| `--c-primary` | summit-600 #3761E1 | summit-400 #7AA1FF | summit-400 #7AA1FF | summit-800 #1B3897 | summit-200 #CFDEFF |
| `--c-on-primary` | neutral-0 #FFFFFF | neutral-1000 #05060B | midnight-1000 #03040F | neutral-0 #FFFFFF | neutral-1000 #05060B |
| `--c-tertiary` (**new**) | flow-700 #006663 | flow-400 #3FBAB6 | flow-400 #3FBAB6 | flow-900 #003634 | flow-200 #BEE8E5 |
| `--c-insight` (replaces `info`) | insight-600 #8348CD | insight-300 #D0B7FF | insight-300 #D0B7FF | insight-800 #532588 | insight-200 #E3D6FF |
| `--c-danger` | danger-600 #CB1B28 | danger-400 #FF716B | danger-400 #FF716B | danger-800 #830011 | danger-200 #FFD1CD |
| `--c-warning` | warning-700 #7B4C00 | warning-400 #DC932E | warning-400 #DC932E | warning-900 #422700 | warning-200 #F7D8B5 |
| `--c-success` | success-700 #006A3A | success-400 #5ABC81 | success-400 #5ABC81 | success-900 #00381C | success-200 #C4E9CF |
| `--c-figure-negative` | danger-700 #A80019 | danger-300 #FFADA6 | danger-300 #FFADA6 | danger-800 #830011 | danger-200 #FFD1CD |
| `--c-focus-ring` | summit-500 #4E7CFF | summit-300 #ABC5FF | flow-300 #8AD6D2 | summit-800 #1B3897 | warning-200 #F7D8B5 |
| `--c-sidebar` (does not invert) | midnight-950 #050719 | midnight-1000 #03040F | midnight-1000 #03040F | neutral-1000 #05060B | neutral-1000 #05060B |
| `--c-sidebar-selected` | summit-800 #1B3897 | summit-900 #0E236F | midnight-800 #1A1F3A | summit-800 #1B3897 | summit-900 #0E236F |

The remaining roles follow the same pattern: `*-subtle` comes from step 50 in light themes and 950 in dark ones; `on-*-subtle` from 800–900 in light themes and 100 in dark ones. `--c-figure`, `--c-debit` and `--c-credit` stay **neutral** in every theme.

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

## 9. Tasks

**Format:** `UX-### · Title` → area · points (1 point ≈ half a focused day) · depends · files, followed by acceptance criteria, each of which can only be true or false.

### Phase A — Foundations (tokens) · 9 tasks · 40 points

#### UX-001 · The new "Summit & Flow" primitives
`Tokens` · **5 pts** · depends: — · extends: PF-199 · `resources/css/app.css`

- [x] The `summit`, `flow`, `insight`, `success`, `warning` and `danger` ramps (11 steps), `neutral` (16 steps) and `midnight` (10 steps) are defined as `L C H` triples with exactly the values in 4.3
- [x] The fixed lightness table (4.2) is documented as a comment above the ramps, with the reason it exists
- [x] The old `blue` / `red` / `amber` / `green` / `violet` / `teal` ramps are removed, with no reference left in `resources/` or `app/`
- [x] No component or template references a primitive (`--p-*`) directly, outside the semantic layer

#### UX-002 · Semantic roles for the five themes
`Tokens` · **5 pts** · depends: UX-001 · `resources/css/app.css`, `layouts/*.blade.php`

- [x] `:root` (Light), `.dark` (Graphite), `[data-theme='midnight']`, `[data-theme='hc-light']` and `[data-theme='hc-dark']` each define every role in 4.4
- [x] Midnight and HC Dark also carry the `.dark` class, so `@custom-variant dark` works unchanged
- [x] The new roles `--c-outline-control`, `--c-tertiary`, `--c-insight` and `--c-surface-container*` are defined and mapped in `@theme`
- [x] Every input, checkbox and select border uses `outline-control`, not `outline`
- [ ] `--c-info` becomes an alias of `--c-insight` for one release, then is removed

#### UX-003 · The contrast matrix for five themes
`Tests` · **5 pts** · depends: UX-002 · extends: PF-200 · `tests/`, `bin/verify`

- [x] The pairs live in one data file that the test reads: [`resources/css/contrast-pairs.json`](../../resources/css/contrast-pairs.json) - 53 pairs, and a role in no pair must say why under `unpaired`
- [x] The test converts OKLCH to sRGB with the same clipping algorithm, and fails if any ratio drops below its target (4.5 / 3, and 7 / 4.5 in HC)
- [x] It runs under `php bin/verify` with no test runner, in line with the repository's policy
- [x] Changing the light theme's `outline-control` to `neutral-400` fails the check (2.89:1), **and a test proves it**

#### UX-004 · Theme switcher, persisted before first paint
`Shell` · **5 pts** · depends: UX-002 · `layouts/app.blade.php`, `layouts/plain.blade.php`, `partials/theme-switcher.blade.php`, `partials/theme-boot.blade.php`, `resources/js/theme-boot.js`, `App\Support\Ui\ThemePreference`

- [x] Options: Automatic / Light / Graphite / Midnight / High contrast light / High contrast dark, persisted per user on the server (`users.theme`, replacing `session('theme')`) and in a cookie, so the sign-in page opens in the chosen theme before anyone is signed in
- [x] "Automatic" respects `prefers-color-scheme` and `prefers-contrast: more`, the latter selecting HC, and follows the device if it changes while the page is open
- [x] The class and attribute are rendered by the server on `<html>`, so there is no flash of the wrong theme on load; Automatic is resolved by a blocking script in `<head>` ahead of the stylesheet (verified from the served HTML, and enforced by `bin/verify design`)
- [ ] `forced-colors: active` (Windows high contrast) gets rules using the system colours `ButtonText` and `Highlight`, and no border disappears — **the rules exist and `bin/verify design` requires them; not yet checked on screen in Windows high-contrast mode**

The operator console stays dark always, by its own design (`layouts/ops.blade.php`), so it has no switcher.

#### UX-005 · Type scale and editorial figures
`Tokens` · **3 pts** · depends: UX-001 · extends: PF-201 · `resources/css/app.css`

- [x] The type tokens in section 5 are defined in `@theme`, with no arbitrary sizes (`text-[17px]`) in templates
- [x] An `<x-figure.display>` component renders figure, unit and decimals as described in 5, with tabular figures
- [x] A check forbids `letter-spacing` and `tracking-*` on elements whose text is Arabic (`:lang(ar)`)

#### UX-006 · Shape, radius and elevation
`Tokens` · **3 pts** · depends: UX-002 · `resources/css/app.css`

- [x] `--radius-*` per role as in 6.1, and `--elevation-0..5` as pairs (shadow in light; border + highlight in dark) as in 6.3
- [x] The five `surface-container-*` levels (6.2) are defined for every theme
- [x] The z-index layers (6.4) are tokens, with no literal `z-[...]` value in templates

#### UX-007 · Motion and transparency tokens
`Tokens` · **3 pts** · depends: — · extends: PF-214 · `resources/css/app.css`

- [x] Every token in 7.1 is defined, and `--ease-tactile` has a `cubic-bezier` fallback for browsers without `linear()`
- [x] The existing `prefers-reduced-motion` block stays, and `prefers-reduced-transparency` is added as in 6.5
- [x] A check fails if the built CSS contains a duration > 500ms or `animation-iteration-count: infinite`

#### UX-008 · Material You: the tenant brand colour
`Tenancy` · **8 pts** · depends: UX-003 · `app/Tenant/Tenancy/*`, tenant settings

- [ ] A tenant chooses a seed colour; the generator applies its hue to the fixed lightness table and clips chroma (4.8)
- [ ] A seed in a blocked range is shifted, and the reason is shown in words
- [ ] The contrast matrix runs on the generated ramp, and any failure falls back to Summit with a message
- [ ] Danger, warning, success, figure-negative and the sidebar are unaffected, and a test proves it
- [ ] The colour is injected as CSS variables in a server-rendered `<style>` — no third-party file, no JavaScript

#### UX-009 · The icon system
`—` · **3 pts** · depends: — · `resources/svg/`, an `<x-icon>` component

- [ ] A self-hosted SVG sprite, **no icon font** (the known trap in `design-system.md`)
- [ ] Every icon is declared directional (mirrors in RTL) or non-directional
- [ ] Decorative icons are `aria-hidden="true"`; functional ones have an accessible name

### Phase B — Components · 14 tasks · 55 points

#### UX-010 · Buttons (Flat 2.0 + Tactile)
`Components` · **5 pts** · depends: UX-006, UX-007 · `resources/views/components/button.blade.php`

- [x] Variants: filled, tonal (Material You), outline, ghost and danger; sizes follow `--control-height` by density
- [x] Press: `scale(.98)` over 80ms, then release on `--ease-tactile` (the `.tactile` class). An inner shadow on press is left out: the scale already reads as pressed, and a shadow change on a filled button is one more thing forced-colours mode discards
- [ ] The loading state keeps the button's width (no jump) and announces "Working…" in a live region
- [ ] An irreversible action (issue, post) has a distinct treatment, asks for confirmation, and is not triggered by `Enter` in a text field

#### UX-011 · Inputs and forms
`Components` · **5 pts** · depends: UX-002 · extends: PF-212

- [ ] The label is always above the field (never a placeholder as a label), the border is `outline-control`, and focus gives a `primary` border + ring
- [ ] Error, warning and success states: colour + icon + a message tied by `aria-describedby`, **with no shake**
- [ ] The currency input: the currency symbol on the correct side per language, tabular figures, right-aligned even in RTL

#### UX-012 · Badges, chips and document states
`Components` · **3 pts** · depends: UX-002, UX-009

- [ ] Every state (draft, issued, paid, overdue, disputed, matched, suspended) = subtle colour + icon + word
- [ ] "Draft" has a hatched border, and "Disputed" is a different colour from "Overdue", per `design-system.md`
- [ ] Every badge passes the contrast matrix in all five themes

#### UX-013 · Cards and Bento tiles
`Components` · **5 pts** · depends: UX-005, UX-006

- [ ] `<x-card>` with elevation levels, and `<x-bento.tile>` accepting a title, an editorial figure, a trend, a micro-viz and a drill-down link
- [x] The trend shows an arrow + a sign + words ("▲ 4.2% vs last month"), never colour alone
- [x] The hover lift happens only under `(hover: hover)` and not under reduced motion
- [ ] The whole tile is keyboard-activatable (one link covers it) with no nested links

#### UX-014 · Glass surfaces
`Components` · **3 pts** · depends: UX-007

- [x] One `.glass` class as in 6.5; `backdrop-filter` is written nowhere else (checked)
- [x] The opaque fallback works under reduced transparency, `prefers-contrast`, the HC themes and unsupported browsers
- [ ] No more than 3 glass elements are visible at once (checked in a browser test)

#### UX-015 · The table — visual layer
`Grid` · **5 pts** · depends: UX-002, UX-005 · extends: PF-205

- [ ] A sticky header on a solid surface, showing an e2 shadow only once it sticks (IntersectionObserver)
- [ ] A hovered row uses `surface-hover`; a selected row `surface-selected` + a 3px `primary` border on `inline-start`
- [ ] No zebra striping by default; it is a user option in the dense density
- [ ] **Zero motion** during scroll and edit, with a test asserting `transition` on cells is none

#### UX-016 · Dialogs, drawers and bottom sheets
`Components` · **5 pts** · depends: UX-006, UX-007

- [ ] A dialog scales from 0.96 at the button that opened it; a drawer enters from `inline-end`; a mobile bottom sheet enters from below
- [ ] Focus is trapped, Esc closes, **and focus returns to the opening button**
- [ ] A scrim as specified in 7.3, with no scaling of the content behind

#### UX-017 · Toasts and confirmations
`Components` · **3 pts** · depends: UX-014

- [ ] A glass toast in the bottom `inline-end` corner, with icon + text + an undo button where undo is possible
- [ ] Financial success (post, issue, match) appears only after the server responds, with a test for it
- [ ] Toasts are announced in `role="status"`, errors in `role="alert"`

#### UX-018 · Loading states (skeletons)
`Components` · **2 pts** · depends: UX-006 · extends: PF-213

- [ ] Skeletons match the final layout (no reflow on arrival)
- [ ] **Static, with no pulse or shimmer**, in line with the "nothing loops" rule

#### UX-019 · Empty states (Clay + Organic)
`Components` · **5 pts** · depends: UX-009 · extends: PF-213

- [ ] 6 self-hosted SVG clay illustrations: no data, the filter excluded everything, the request failed, reconciliation complete, welcome, permission denied
- [ ] Every illustration is `aria-hidden`; the text message and the action are sufficient on their own
- [ ] The three cases (no data / filter / failure) are visually and textually distinct

#### UX-020 · Menus and tooltips
`Components` · **3 pts** · depends: UX-006

- [ ] Elevation e3 on `container-high`, entering over 120ms from the anchor's side
- [ ] A tooltip also appears on focus, not only on hover, and stays while hovered (WCAG 1.4.13)

#### UX-021 · Tabs, switches and checkboxes
`Components` · **3 pts** · depends: UX-010

- [ ] Segmented tabs in the Material You style (a tonal indicator sliding over 180ms)
- [ ] The switch mirrors in RTL, the checkbox draws its check, and every target is ≥ 24×24 at dense density

#### UX-022 · The chart theme (ECharts)
`Reporting` · **5 pts** · depends: UX-002 · extends: PF-211

- [ ] An ECharts theme generated from the tokens per theme; a chart cannot pass its own colours
- [ ] Categorical, sequential and diverging palettes exactly as in 4.6; `aria.decal` on in HC
- [ ] The time axis runs left-to-right in RTL while titles and legends mirror; the decision is added to `rtl-and-i18n.md`
- [ ] Every chart has a text summary and a table alternative behind a disclosure

#### UX-023 · Sparklines and micro-viz
`Reporting` · **3 pts** · depends: UX-022

- [x] A lightweight SVG sparkline (no ECharts) with a 1.75px non-scaling stroke and an end dot, and an accessible name summarising the trend
- [ ] The match-confidence meter: an Insight bar + a percentage + a text reason
- [x] The receivables ageing: one labelled bar per bucket, each with its amount as text, so no colour has to carry the meaning

### Phase C — Shell and screens · 11 tasks · 52 points

#### UX-030 · The shell with the layered sidebar
`Shell` · **8 pts** · depends: UX-004, UX-009, UX-010 · extends: PF-204 · `layouts/app.blade.php`, `partials/entity-switcher.blade.php`

- [ ] An always-dark sidebar as in 8.1, collapsible to icons, with its state persisted per user
- [ ] A top bar that turns glass (0.92) only on scroll
- [ ] The entity and period chip is visible on every screen, with a warning border when the period is closed
- [ ] The skip link and `<main tabindex="-1">` remain and work

#### UX-031 · The sign-in screen
`Identity` · **5 pts** · depends: UX-014, UX-010 · `auth/login.blade.php`, `ops/login.blade.php`, `layouts/plain.blade.php`

- [x] Static aurora + an organic shape + a glass card with solid inputs, as in 8.2
- [x] A `--g-brand-cta` button with white text ≥ 5.30:1 (measured)
- [x] Text on the glass card, measured in the worst case (every light source stacked behind it): the faintest text is 6.43:1 in light, 8.82:1 in Graphite, 9.24:1 in Midnight
- [ ] Operator sign-in carries the Insight Violet identity and a clear badge
- [ ] LCP < 1.5s locally; the background is pure CSS with no images

#### UX-032 · The "Overview" dashboard (Bento)
`Reporting` · **8 pts** · depends: UX-013, UX-023 · `app/home.blade.php`, `AppTenantReportingOverviewOverviewQuery`, `OverviewController`

- [x] The grid as in 8.3 with three breakpoints (12 / 6 / 1), working in RTL with no special rules (checked on screen at 800 and 1440 px, light and Midnight)
- [ ] Every figure opens its drill-down (the source journal lines) — **open**: no ledger or document screens exist yet to drill into; `<x-bento.tile href>` is ready for them (Phase 10)
- [x] The welcome card appears when there is no data (`bin/verify reporting`); the count-up is UX-052
- [x] The dashboard's query count is constant whatever the data size: 8 queries with no documents and with several, measured by `bin/verify reporting` against invoices issued through the real Issuer

#### UX-033 · Choose entity
`Tenancy` · **3 pts** · depends: UX-013 · `app/choose-entity.blade.php`

- [ ] Cards with an organic avatar + currency + country, turning into a searchable list above 6 entities

#### UX-034 · Bank import
`Banking` · **5 pts** · depends: UX-015, UX-019 · `banking/import-upload.blade.php`, `banking/import-preview.blade.php`, `banking/partials/import-submit.blade.php`

- [ ] A tactile drop zone as in 8.5, with a complete keyboard alternative
- [ ] The preview table uses the new visual layer; error rows show colour + icon + text

#### UX-035 · Bank reconciliation (Spatial)
`Banking` · **5 pts** · depends: UX-014, UX-023 · extends: PF-210

- [ ] A floating dark-glass "remaining difference" bar (z-30), always visible and announced in a live region
- [ ] Suggestions in Insight with confidence and a reason, and one moment of success on completion

#### UX-036 · The invoice builder
`Sales` · **5 pts** · depends: UX-011, UX-015 · extends: PF-209

- [ ] A sticky totals panel with a "calculating" state, a live validation panel and a separate Issue button, as in 8.7

#### UX-037 · The command palette
`Shell` · **3 pts** · depends: UX-014, UX-016 · extends: PF-208

- [ ] Styled as in 8.8, with focus restored to where it was on Esc

#### UX-038 · Error pages
`—` · **3 pts** · depends: UX-019 · `errors/*.blade.php`, `layouts/error.blade.php`

- [ ] An illustration per case as in 8.9; the text is enough on its own; works in all five themes

#### UX-039 · The operator console
`Platform` · **3 pts** · depends: UX-030 · `layouts/ops.blade.php`, `ops/*.blade.php`

- [ ] The Insight Violet identity as in 8.10, with "You are in the platform console" always visible

#### UX-040 · The public quote page
`Sales` · **4 pts** · depends: UX-008 · `public/quote.blade.php`

- [ ] Takes the tenant's brand colour, prints in light, and passes the contrast matrix with that brand colour

### Phase D — Motion and interaction · 3 tasks · 10 points

#### UX-050 · Page transitions (View Transitions)
`Shell` · **3 pts** · depends: UX-007

- [ ] List → detail with a shared `view-transition-name`, 320ms, falling back silently where unsupported
- [ ] Fully disabled under reduced motion, with a test for it

#### UX-051 · The micro-interaction catalogue
`Components` · **5 pts** · depends: UX-010, UX-021

- [ ] Every row of the table in 7.2 is implemented as a reusable Alpine directive or CSS class
- [ ] A `/_design/motion` page (local environment only) shows every interaction for review

#### UX-052 · KPI count-up
`Reporting` · **2 pts** · depends: UX-032

- [ ] The final value is in the DOM for screen readers; the animated copy is `aria-hidden`
- [ ] It never runs in tables, under reduced motion or on refresh — only on first load

### Phase E — Quality and documentation · 4 tasks · 15 points

#### UX-060 · Visual regression screenshots
`Tests` · **5 pts** · depends: UX-030..040

- [ ] A screenshot per screen × 5 themes × 2 directions (RTL / LTR) × 3 densities; any unintended diff fails the build

#### UX-061 · Accessibility audit
`Tests` · **5 pts** · depends: UX-030..040 · extends: PF-216

- [ ] axe reports no errors on any screen in any theme; a complete keyboard-only path; a screen reader once per release with the result recorded

#### UX-062 · Performance budget
`Tests` · **3 pts** · depends: UX-031, UX-032

- [ ] Built CSS < 60 kB gzipped, visible `backdrop-filter` elements ≤ 3, INP < 200ms on the dashboard

#### UX-063 · Update the reference documents
`Docs` · **2 pts** · depends: UX-001..007

- [ ] `design-system.md` reflects the new tokens and adds a section "Where expressive styles are allowed" (the 80 / 15 / 5 rule)
- [ ] `interaction-and-motion.md` adds the new motion tokens and the interaction catalogue
- [ ] `rtl-and-i18n.md` adds the time-axis rule and the ban on `letter-spacing` for Arabic

### Summary

| Phase | Tasks | Points |
|---|---:|---:|
| A — Foundations | 9 | 40 |
| B — Components | 14 | 55 |
| C — Shell and screens | 11 | 52 |
| D — Motion | 3 | 10 |
| E — Quality | 4 | 15 |
| **Total** | **41** | **172** |

**Suggested order:** UX-001 → 002 → 003 (colour and its verification first, because everything after builds on them) → 004 → 005 / 006 / 007 in parallel → 010 / 011 / 013 → **UX-031 sign-in and UX-032 dashboard as the first two visible screens** → the remaining screens → Phase E.

---

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
