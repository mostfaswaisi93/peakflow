# Phase 03 — Parties, catalog, currencies and FX

**16 tasks · 66 points**

The master data every document references. Two parts of this phase carry more risk than their size suggests: **party bank details**, because beneficiary substitution is the highest-value attack in the entire product, and **FX rates**, because a rate stored with the wrong direction or inverted on the fly produces differences that are small, plausible and impossible to explain.

---

### PF-060 · Addresses and tax registrations
`Parties` · **3 pts** · depends: PF-059 · standards: ISO 3166-1, EN 16931, Peppol EAS

- [x] Multiple typed addresses per party: legal, billing, delivery, with the EN 16931 address terms as the shape
- [x] Tax registrations with jurisdiction, scheme and identifier, validated for structure where the scheme defines one
- [x] Electronic addressing identifiers with their **EAS** scheme code, which is what Peppol routing needs
- [ ] A party's billing address and tax registration are **copied onto a document at issue**, never referenced - an issued invoice is a historical statement

### PF-072 · Global search
`—` · **5 pts** · depends: PF-059, PF-064 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] Meilisearch across documents, parties, accounts and items, scoped to the tenant and filtered by the user's entity roles and permissions **in the engine's own filter**, not after the fact
- [ ] Searching by phone number, tax registration, invoice number or amount all work, because those are what people actually have to hand
- [ ] Soft-deleted rows stay hidden; a permission a user lacks hides the result rather than returning a forbidden one
- [ ] Keyboard-navigable results with `Ctrl`/`⌘`+`K`, arrows and `Enter`; indexing is queued and a failure never blocks a write

### PF-281 · A demo fixture somebody can sell from, in shekels
`—` · **8 pts** · depends: PF-056, PF-068 · standards: ISO 4217 · `risk:high`

**Commits to:** the seeded workspace being the product's shop window rather than a smoke test.

The development seeder exists to prove the thing boots. What a demo needs is different: a company with a year of trading behind it, so that every screen has something true on it and a trial balance that actually balances. A product demonstrated on three invoices is a product the viewer has to imagine.

**The functional currency is the shekel.** That is a change of default, not a new option: `ILS` becomes the seeded entity's functional currency, and the fixture's foreign-currency work is measured against it rather than against the riyal.

- [ ] The seeded entity's functional currency is **ILS**, and every derived figure - the trial balance, the ageing, the control accounts - is stated in it
- [ ] Rates exist for the pairs the fixture actually uses, on the dates it uses them, so no document is posted at a rate nobody loaded - the seeder fails loudly rather than falling back
- [ ] At least one customer and one supplier trade in a **different** currency, so the FX difference account is non-zero and the revaluation has something to revalue
- [ ] A year of documents across the whole cycle: quotes that became orders, orders that became invoices, invoices part-paid and fully paid, a credit note, a dunning case, bills matched three ways, a payment run released, a bank statement imported and reconciled
- [ ] The ledger **ties**: `bin/verify ledger` passes against the seeded data, and the receivables and payables ageing each equal their control account - a demo whose books do not reconcile is a demo that cannot be shown to an accountant
- [ ] Dates are relative to the run, not hard-coded, so the fixture is never "last year's demo" and the ageing buckets have something in each
- [ ] It is **separate from** the development seeder and the two do not drift: the development one stays minimal and fast, the demo one is the one with a year in it, and a check asserts both still run
- [ ] Every person in it is invented, under a reserved domain, as PF-239 requires
