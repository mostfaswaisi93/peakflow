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
