# Phase 04 — The tax engine

**14 tasks · 71 points**

Tax comes before sales, and that ordering is the most consequential sequencing decision in the whole plan. The tax point date, the rate version and the per-rate-group breakdown are **inside an issued document and frozen there**. Retrofitting them means migrating immutable rows that a customer has already filed a return on, which is to say it is not possible.

The engine's organising principle is that **tax is data, not code**. A rate that changes on a date is a row. A jurisdiction's rounding rule is a row. A place-of-supply decision is a rule evaluated from rows. Nothing about a specific country is compiled into the sales path.

---

### PF-075 · Tax categories and codes
`Tax` · **5 pts** · depends: PF-026 · standards: UNCL 5305, EN 16931 · `type:schema` `type:compliance`

- [ ] Categories per UNCL 5305: `S` standard, `Z` zero-rated, `E` exempt, `AE` reverse charge, `O` out of scope, `K` intra-community supply, `G` export, `L` and `M` for the Canary Islands and Ceuta/Melilla
- [ ] `tax_codes` carrying a category, a jurisdiction, a rate, a reporting box mapping and a bilingual label
- [ ] A code's **category drives behaviour**; the label is presentation. Zero-rated and exempt are different categories with different consequences and must never be modelled as "0% with a note"
- [ ] An exemption reason is mandatory where the category requires one - EN 16931 `BT-121`/`BT-120`

### PF-076 · Rate versioning
`Tax` · **5 pts** · depends: PF-075 · standards: — · `risk:high`

- [ ] Rates are effective-dated; a rate change creates a new version and never edits the old one
- [ ] A document resolves its rate by its **tax point date**, so a historical document keeps the rate that was in force
- [ ] Overlapping validity for one code is refused by a database exclusion constraint, not by application validation alone
- [ ] A test covers a rate change mid-month and proves documents either side of it resolve differently

### PF-077 · The tax point date
`Tax` · **5 pts** · depends: PF-076 · standards: EN 16931 (`BT-7`) · `risk:high` `type:compliance`

- [ ] The tax point is its own field, distinct from the invoice date and the delivery date
- [ ] Derivation is a per-jurisdiction rule: invoice date, delivery date, payment date, or the earlier of a pair
- [ ] The derived value is **stored on the document**, not recomputed - a document issued on the 1st for a supply on the 30th belongs to the earlier return in many jurisdictions, and that decision must be frozen
- [ ] The tax point determines the period the document falls into for the return, which can differ from its accounting period; both are stored

### PF-078 · Place of supply
`Tax` · **8 pts** · depends: PF-077, PF-060 · standards: — · `risk:high` `type:compliance`

- [ ] A rule engine deciding which jurisdiction's tax applies, from: supplier country, customer country, customer registration status (B2B or B2C), item type (good, service, digital service), and the delivery location
- [ ] Rules are data with a documented source per jurisdiction, and are evaluated in a defined precedence with the winning rule recorded on the document
- [ ] Shipped cases: domestic, export of goods, intra-GCC, intra-EU B2B goods and services, digital services to consumers, and a documented fallback that **refuses rather than guesses**
- [ ] The decision and its reasoning are visible on the invoice's tax panel, because an accountant must be able to check it

### PF-079 · Reverse charge
`Tax` · **3 pts** · depends: PF-078 · standards: UNCL 5305 (`AE`), EN 16931 · `type:compliance`

- [ ] Modelled as the `AE` category, which reverses who accounts for the tax - not as a zero rate with a comment
- [ ] The invoice carries no tax amount and a **mandatory legal note**, whose text is per jurisdiction
- [ ] The recipient self-assesses: `Purchasing` posts both the input and the output tax on the bill, which is the half most systems forget
- [ ] Requires both parties to have a valid registration; issuing reverse-charge without one is refused

### PF-080 · Zero-rated, exempt and out of scope
`Tax` · **3 pts** · depends: PF-075 · standards: — · `type:compliance`

- [ ] The three are distinct categories with distinct return treatment: zero-rated is a taxable supply at 0% and is reclaimable; exempt is not a taxable supply and restricts input recovery; out of scope is outside the regime entirely
- [ ] Each requires its own evidence: an export needs proof of export, an exemption needs a reason code
- [ ] A document whose category requires evidence cannot be issued without it recorded

### PF-081 · The rounding policy
`Tax` · **5 pts** · depends: PF-006, PF-075 · standards: EN 16931, ISO 4217 · `risk:high` `type:compliance`

- [ ] `RoundingPolicy` resolved per jurisdiction: the mode (half-up by default, because EN 16931 and most VAT regimes specify it, not half-even), the scale, and whether tax is computed per line or per rate group
- [ ] The policy is **configuration**, so a jurisdiction that differs is a row and not a branch
- [ ] A worked example per shipped jurisdiction is committed as a fixture with its expected output to the cent
- [ ] A test proves that the two policies produce different, specified answers on the same document - so the policy is demonstrably doing something

### PF-082 · Tax computation
`Tax` · **8 pts** · depends: PF-081, PF-078 · standards: EN 16931 `BG-23`, `BR-CO-14` · `risk:high` `type:compliance`

- [ ] Tax is accumulated **unrounded** per `(category, rate)` group, then rounded once per group - not per line and not once per document
- [ ] The result is a VAT breakdown matching EN 16931 `BG-23`, with one entry per unique category-and-rate combination
- [ ] `BR-CO-14` is asserted directly: the document's tax total equals the sum of the breakdown entries
- [ ] Line-level and document-level discounts, charges and allowances are apportioned across rate groups before tax, with the apportionment residual assigned deterministically rather than left to float
- [ ] Property-based test: for any generated document, the sum of line nets equals the document net, the breakdown sums to the tax total, and recomputing the document twice gives identical output
- [ ] Mixed-rate documents, negative lines and a 100% discount line each have a named test

### PF-083 · Withholding tax
`Tax` · **5 pts** · depends: PF-082 · standards: — · `type:compliance`

- [ ] Withholding is deducted by the **payer from the payment**, not from the invoice - so it lives in the settlement path, not in the document total
- [ ] A rate per party, per item type and per jurisdiction, effective-dated
- [ ] An invoice total that does not equal the cash received is **correct** when withholding applies, and the allocation logic must handle it rather than treating the shortfall as a short payment
- [ ] A withholding certificate is generated and tracked, because the payee needs it to claim the credit
- [ ] Its own reporting, separate from VAT

### PF-084 · Tax accounts and posting
`Tax` · **3 pts** · depends: PF-082, PF-047 · standards: — · `type:posting`

- [ ] Separate `vat_input` and `vat_output` control accounts per jurisdiction per entity
- [ ] Posting rules for sales tax, purchase tax, reverse charge (both sides) and withholding, each written out as debits and credits
- [ ] A non-recoverable portion posts to expense rather than to the input account, which is what makes the input account reconcilable to the return

### PF-085 · Return periods and box mapping
`Tax` · **5 pts** · depends: PF-084 · standards: — · `type:compliance`

- [ ] Return periods per registration, which need not match accounting periods - monthly VAT on a quarterly-closing entity is normal
- [ ] Each tax code maps to the boxes of each return it appears on
- [ ] A document belongs to a return period by its **tax point**, and a document whose tax point falls in a filed period is refused rather than silently landing in the next one
- [ ] Mapping is data per jurisdiction with a documented source

### PF-086 · Return generation
`Tax` · **8 pts** · depends: PF-085 · standards: — · `risk:high` `type:compliance`

- [ ] A box-by-box return for a period, with **drill-down from every box to the journal lines behind it**
- [ ] A pre-filing checklist: unposted documents in the period, unmatched bank lines, a non-zero suspense balance, documents missing evidence for their category
- [ ] Filing freezes a **snapshot** - the figures, the supporting line ids and a hash - which cannot change afterwards even if the underlying data is later adjusted
- [ ] Filing is permissioned, requires MFA, and is audited
- [ ] A filed return is re-printable byte-identically, which is what gets attached to a query from the authority

### PF-087 · Post-filing adjustments
`Tax` · **3 pts** · depends: PF-086 · standards: IAS 8 · `type:compliance`

- [ ] An adjustment to a filed period lands in the **next open** return period, carrying a reference to the period it corrects
- [ ] A filed return is never edited; a correction is a new document with its own audit trail
- [ ] An adjustments schedule accompanies the next return, which is what the authority expects to see
- [ ] Where a jurisdiction requires an amended return instead, the profile declares it and the flow differs accordingly

### PF-088 · Tax account reconciliation
`Tax` · **5 pts** · depends: PF-086, PF-054 · standards: — · `risk:high`

The check that proves the return and the ledger agree.

- [ ] A report reconciling the movement on the input and output tax control accounts for a period against the return's boxes, line by line
- [ ] Differences are itemized and classified, never netted into a single reconciling figure
- [ ] A non-zero unexplained difference **blocks the period close** unless a controller signs it off with a reason
- [ ] Included in `ledger:verify` as a per-period assertion
