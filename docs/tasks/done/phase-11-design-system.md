# Phase 11 — Design system, data grid, accessibility and RTL — finished

The tasks of [phase-11-design-system.md](../phase-11-design-system.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-266 · The theme switcher, and the flash it must not have
`—` · **3 pts** · depends: PF-199 · standards: WCAG 2.2 AA · —

- [x] A person chooses among the five themes, and the choice is **persisted before the first paint** - a switcher that applies after hydration shows the wrong theme for a frame, and on a dark-theme user that frame is a white flash in a dark room
- [x] The preference follows the account rather than the browser, because somebody who set dark on their laptop did not ask to be blinded by their phone
- [x] `prefers-color-scheme` is the default for an account that has not chosen, and choosing overrides it - a product that ignores the system setting and one that refuses to let you leave it are both wrong
- [x] `prefers-contrast` and `prefers-reduced-transparency` select the high-contrast themes, since the people those settings exist for should not have to find a menu
- [x] **Found while building it:** Tailwind v4 defaults `dark:` to the media query, so a class-based toggle leaves every `dark:` utility compiling, landing in the HTML and doing nothing. `@custom-variant dark` is load-bearing and says so in the stylesheet
