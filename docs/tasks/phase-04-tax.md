# Phase 04 — The tax engine

**14 tasks · 71 points**

Tax comes before sales, and that ordering is the most consequential sequencing decision in the whole plan. The tax point date, the rate version and the per-rate-group breakdown are **inside an issued document and frozen there**. Retrofitting them means migrating immutable rows that a customer has already filed a return on, which is to say it is not possible.

The engine's organising principle is that **tax is data, not code**. A rate that changes on a date is a row. A jurisdiction's rounding rule is a row. A place-of-supply decision is a rule evaluated from rows. Nothing about a specific country is compiled into the sales path.

---

### PF-075 · Tax categories and codes
`Tax` · **5 pts** · depends: PF-026 · standards: UNCL 5305, EN 16931 · `type:schema` `type:compliance`

- [x] Categories per UNCL 5305, all nine: `S`, `Z`, `E`, `AE`, `O`, `K`, `G`, and `L`/`M` for the Canary Islands and Ceuta/Melilla. `L` and `M` are in because EN 16931 requires them - a Spanish seller with Canary Islands customers cannot issue a conformant invoice without them - and they are separate regimes rather than rates of Spanish VAT, which is why they may carry a rate where the six zero categories may not
- [x] The categories are **code, not configuration**, and that is the one exception to "tax is data". A category's meaning is fixed by a published code list, so configuration makes it a thing an operator can change - and the change that gets made is "exempt supplies are input-recoverable", because somebody is reconciling an input tax box that will not balance and that flag is the fastest way to make the number move
- [x] `restrictsInputRecovery()` rather than an `inputRecoverable` boolean. The **degree** of restriction is a jurisdiction rule - partial exemption methods differ - so a flag saying "recoverable: false" would be a lie in every regime that has a method. What is universal is which categories trigger the question; the apportionment is PF-084
- [x] `tax_codes` carrying a category, a jurisdiction, a reporting box mapping and a bilingual label, with the **rate in `tax_rates`** - see PF-076 for why the split is structural
- [x] A code's **category drives behaviour**; the label is presentation. The behaviour matrix is asserted from the code list written out in the check suite, not derived from the enum - a check that derived it would assert the enum equals itself, and the whole point is that `Z`, `E` and `O` are three different things
- [x] Collapsing them reaches further than the return: registration thresholds are computed from **taxable turnover**, so treating zero-rated as exempt can make a business appear not to need a registration it does need
- [x] An exemption reason is mandatory where the category requires one - EN 16931 `BT-121`/`BT-120` - enforced by a CHECK constraint on the code, so the default exists and a document cannot omit it. An invoice that says 0% with no reason is an invoice that cannot be defended

### PF-076 · Rate versioning
`Tax` · **5 pts** · depends: PF-075 · standards: — · `risk:high`

- [x] Rates are effective-dated; a rate change creates a new version and never edits the old one. Precisely: the **rate** is never edited - the validity window changes, because an open-ended row has to stop being open-ended for a successor to exist, and a check asserts the old row's rate is untouched
- [x] **Two tables, because a category is not an attribute of a rate.** The first implementation versioned everything together, which made the category an attribute of each rate version - so a rate change could silently also change the treatment, and the service had to refuse it in application code. Structure that cannot express the mistake beats a refusal that has to remember to fire. `docs/domain/tax-engine.md` had specified this shape before the code existed; the one-table version was a divergence nobody had decided on
- [x] Validity is a **half-open `daterange`** (`[from,until)`), so "in force on" is one `validity @> date` containment test that the query and the constraint share. Two nullable date columns need a `COALESCE` in both places, and the day they are written with `<` in one and `<=` in the other is the day a document resolves two rates. It also means a successor's start **is** its predecessor's end, so no call site does arithmetic on "the day before"
- [x] A document resolves its rate by its **tax point date**, so a historical document keeps the rate that was in force. There is deliberately no `current()` on the resolver, and a tax point before the code's first version is **refused rather than guessed** - resolving the nearest version would silently apply a rate that was not law on the day of the supply
- [x] Overlapping validity for one code is refused by a database exclusion constraint, attempted **through the model** rather than the service - a check that went through the service would pass while the constraint was missing. Four overlap shapes are tried, plus a non-overlapping one that must still be accepted, because a constraint that refused everything would pass all four
- [x] A rate change mid-period, measured: Saudi Arabia's 5% to 15% on 2020-07-01, with tax points on 2018-06-30, 2020-06-30, 2020-07-01 and 2026-11-15 resolving 5, 5, 15 and 15 - and 2017-12-31 refused
- [x] Making the half-open end bound inclusive was tried as a deliberate break, and **the exclusion constraint itself rejected the rate change**. The database catches the off-by-one
- [x] The zero-rate invariant is the database's too, as a trigger rather than a CHECK, because the category lives on the parent row and a CHECK cannot read another table. It fires on a category change as well, since either side can break it: a `Z` code gaining a 5% rate, or an `S` code at 5% being changed to `Z`
- [x] Found on the way: the row-level-security check passed with RLS removed from the migration entirely, because it queried through the `BelongsToEntity` global scope - which returns nothing whether or not the database has a policy. The whole point of PF-026 is that the application scope is not the only line of defence, so the check bypasses it and asks the database, in both directions
- [x] Found on the way: `TaxRate::fraction()` first used `bcdiv`, and `bcmath` is under `suggest` rather than `require` - so it is absent here and a `bc*` call is a fatal error at the moment somebody computes tax. An architecture rule now refuses the whole family, and that rule was itself wrong on its first attempt: an optional namespace separator written as `\\?` becomes a required literal `?` inside a single-quoted PHP string, so it matched nothing and passed

### PF-077 · The tax point date
`Tax` · **5 pts** · depends: PF-076 · standards: EN 16931 (`BT-7`) · `risk:high` `type:compliance`

- [ ] The tax point is its own field, distinct from the invoice date and the delivery date
- [x] Derivation is a per-jurisdiction rule: invoice date, delivery date, payment date, or the earlier of a pair. Five bases in `TaxPointBasis`, read from `config/jurisdictions.php` per profile, and `TaxPoint::derive()` returns a `TaxPointDecision` carrying the date, the basis, **which input won** and the profile it came from
- [ ] The derived value is **stored on the document**, not recomputed - a document issued on the 1st for a supply on the 30th belongs to the earlier return in many jurisdictions, and that decision must be frozen
- [ ] The tax point determines the period the document falls into for the return, which can differ from its accounting period; both are stored
- [x] A missing date **refuses** rather than falls back. The delivery-date basis with no delivery date used to return the invoice date, which is the single worst failure available here: it is silent, it is plausible, and it puts the supply in the wrong return. `TaxPointUndeterminable::missingDates()` names the profile and the basis
- [x] Found on the way: the two "earlier of" bases have **no UNCL 2005 code**. `3` is the invoice date, `35` the delivery date, `432` the payment date, and "the earlier of the invoice date and the delivery date" is not one of them - so `documentCode()` reports the code of the date that actually **won**, not the rule that chose it. A document that declared `3` while the delivery date won would be wrong on `BT-8`
- [x] Found on the way: an **unknown basis string** and an **unlisted profile** are different mistakes. A profile nobody has configured yet should fall back to the invoice date; a configured profile whose basis is a typo should not, because the fallback would hide it. So the profile name falls back and the basis string throws, and both are checked. `jofotara` had no entry at all and now has one

> The two open criteria are a column on a document, and documents arrive in Phase 05 (PF-089 onwards). What exists now is the decision and the period it falls into - `TaxPoint::fallsInMonth()` - and `TaxPointDecision` is `JsonSerializable` precisely so the whole decision, not just its date, can be frozen onto the row when there is a row to freeze it onto.

### PF-078 · Place of supply
`Tax` · **8 pts** · depends: PF-077, PF-060 · standards: — · `risk:high` `type:compliance`

- [x] A rule engine deciding which jurisdiction's tax applies, from: supplier country, customer country, customer registration status (B2B or B2C), item type (good, service, digital service), and the delivery location. Five conditions, a vocabulary of eight words, and no expressions - a rule book with a language in it is a program, and a program in configuration is a program nobody tests
- [x] Rules are data with a documented source per jurisdiction, and are evaluated in a defined precedence with the winning rule recorded on the document. Precedence is an **integer in the row**, not the row's position: array order is invisible in a diff, so a rule moved up three lines would change what thousands of documents charge and read as a reformatting. A check asserts that reversing the file's order changes nothing
- [x] Shipped cases: domestic, export of goods, intra-GCC, intra-EU B2B goods and services, digital services to consumers, and a documented fallback that **refuses rather than guesses**. Sixteen cases asserted on the winning rule **and the tax category** - a case asserting only the country would accept a reverse charge as a domestic sale whenever the two agree
- [ ] The decision and its reasoning are visible on the invoice's tax panel, because an accountant must be able to check it
- [x] `abroad` and `outside` are **two words**, and the distinction is the whole of intra-bloc treatment. Delivering from France to Germany is `abroad` and `bloc:eu`; to Switzerland it is `abroad` and `outside`. One is an intra-Community supply in category `K`, the other an export in `Z` that needs evidence of exit, and a single "is the customer foreign" flag collapses them invisibly - both answers carry a country and a rule id
- [x] A missing delivery location **refuses rather than falling through**. Continuing to a lower-precedence rule would answer a question that a higher-precedence rule had a claim on, and the answer would look ordinary: a country, a rule id, a legal source, and nothing saying the rule that should have decided was never evaluated. Measured with the refusal removed: goods with no delivery location resolved to the supplier's country by `domestic`
- [x] Bloc membership is validated against the vendored ISO 3166-1 register, not a regex. `ZZ` matches `/^[A-Z]{2}$/`, and **EU VAT identifiers are not ISO codes** - they use `EL` for Greece and `XI` for Northern Ireland, which is exactly what somebody copying from a VAT manual writes. A member no country record can equal never matches, and the bloc then answers "export" for a supply that is intra-bloc
- [x] The United Kingdom is recorded as a **dated departure**, not deleted. Deleting it answers correctly today and cannot answer anything about a supply made in 2019, and a credit note against a 2019 invoice is treated the way the original was. Measured with the date removed and GB back in the list: an intra-Community supply reported in category `K` for a country that left the Community
- [x] Found on the way: `bin/verify` evaluated every suite eagerly, so a suite that **threw** while being built aborted the whole run before a single line printed - 23 other checks went quiet because one configuration file was contradictory. Found by proving the bloc check bites: the deliberate contradiction was refused exactly as designed and took the run with it. A thrown exception is now a failed check and the suites after it still run

> The open criterion is a panel on an invoice, and invoices arrive in Phase 05. `PlaceOfSupplyDecision::explain()` already returns the sentence it will show, in Arabic and English, and the decision is `JsonSerializable` so the reasoning can be frozen onto the document rather than recomputed from party records that will have changed by the time anybody looks.

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
