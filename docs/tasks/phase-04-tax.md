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

- [x] Modelled as the `AE` category, which reverses who accounts for the tax - not as a zero rate with a comment. `AE` and `Z` both leave the tax column empty, which is exactly why they get conflated, and the check asserts the four-way matrix for both from the code list rather than from the enum
- [x] The invoice carries no tax amount and a **mandatory legal note**, whose text is per jurisdiction. Six profiles, Arabic and English, each with the article it comes from. A profile with no note **refuses**: the fallback prints a document missing a mandatory legal mention and looks complete
- [ ] The recipient self-assesses: `Purchasing` posts both the input and the output tax on the bill, which is the half most systems forget
- [x] Requires both parties to have a valid registration; issuing reverse-charge without one is refused - and valid **on the tax point**, not today. A document raised in May for a supply in March is judged on March, and a registration that lapsed in April was valid then
- [x] `party_tax_registrations` had an identifier and nothing about when it was the customer's identifier, so it gained a half-open `daterange` with an exclusion constraint over `(party_id, jurisdiction, scheme)` - deliberately **not** including the identifier, because a renumbered business has two identifiers in the same jurisdiction whose periods must not overlap
- [x] A refusal tells **no registration** apart from **one that had lapsed**, with the date it stopped applying. The two send somebody to different places - the customer record, or the customer - and a refusal that cannot distinguish them gets resolved by typing a number in
- [x] Found on the way: `Entity::primaryTaxRegistration()` answers for **today**, while its own docblock says an invoice issued last year must carry the number that was current when it was issued. `now()` cannot answer that, so `taxRegistrationOn()` takes the date. The bite - looking the customer up as at today - was accepted silently: the number is on the record, it is current, and it was not theirs when the supply happened
- [x] Found on the way: the layer rules refused the first version outright. Tax may not depend on `Parties`, which is right - a module that reaches into another's models decides treatments from whatever happens to be on a record. Tax now declares `CounterpartyRegistrations` and `Sales\Services\CounterpartyRegistrationReader` supplies it, which is the first file in the Sales module and deliberately the smallest possible one
- [x] Found on the way: the citation check failed on my own data - the Jordanian note cited a law and no article. The fix was **not** to invent one. A guessed article reads as checked and is the first thing an auditor looks up, so two sources are marked `article unverified here`, the same stance `database/iso/provenance.json` takes, and the check accepts a citation or that marker

> The open criterion is the purchasing half, which is Phase 07: posting both the input and the output tax on the bill is what makes a reverse charge net to nothing for a fully recoverable customer and cost money for one that is partly exempt. The sell-side gate, the note and the registration checks are here.

### PF-080 · Zero-rated, exempt and out of scope
`Tax` · **3 pts** · depends: PF-075 · standards: — · `type:compliance`

- [x] The three are distinct categories with distinct return treatment: zero-rated is a taxable supply at 0% and is reclaimable; exempt is not a taxable supply and restricts input recovery; out of scope is outside the regime entirely
- [x] Each requires its own evidence: an export needs proof of export, an exemption needs a reason code. Three kinds - export proof, **dispatch** proof and the customer's registration - and the second is the one that catches people: an intra-Community supply is exempt in the state of dispatch only if the goods arrived somewhere else in the Community, and there is no customs declaration to prove it because there is no customs frontier
- [ ] A document whose category requires evidence cannot be issued without it recorded
- [x] The evidence requirement is declared on the **place-of-supply rule**, not derived from the category, because the category is not enough: `Z` is what a Saudi export carries and also what a domestic zero-rated foodstuff carries. Derive it from the category and either every loaf of bread needs a customs declaration or no Saudi export needs one. `TaxCategory::requiresEvidence()` stays as a floor, so a rule that forgot its list cannot claim less than the code list demands
- [x] Found on the way: PF-078's export rule carried `Z` for every jurisdiction, and EN 16931 codes an export `G` ("free export item, VAT not charged") while the Saudi regime zero-rates it under Art. 32. One row was wrong for an EU supplier and the other spelling would be wrong for a Saudi one, so it is **two rows** - which is the case that shows why the rule book holds the category rather than deriving it from "is it cross-border"
- [x] Each evidence item carries a **reference**, not a boolean. A ticked box with nothing behind it is worse than nothing: the system says the evidence exists, so nobody looks for it, and the assessment is unanswerable. A whitespace reference is refused, and the refusal is told apart from a missing one
- [x] Exemption reason codes are **data, validated**: a VATEX subset keyed by code, each mapped to its category with bilingual text and its article. A code used with the wrong category is refused - `vatex-eu-ic` on an exempt line reports an intra-Community supply that did not happen, and the other member state will look for a figure in a box and not find it
- [x] The code list is an explicit subset and an unknown code is **refused**, which blocks a legitimate exemption whose code nobody has added yet. That is an availability problem, fixed by a row, and the right direction: a code is the first thing a receiving system validates, and an invented one fails a document that was otherwise correct after it was filed
- [x] `L` and `M` are named in the configuration as categories with no code shipped, so the refusal points at the gap rather than looking like a bug in the gate. A check asserts that every category requiring a reason has either codes or a declared gap - a category in neither place refuses every document silently

> The open criterion is the issue path, which is Phase 05. `EvidenceGate::assertSatisfied()` is the rule it will call, built and checked now so the issue path has something to call rather than a comment saying it should.

### PF-081 · The rounding policy
`Tax` · **5 pts** · depends: PF-006, PF-075 · standards: EN 16931, ISO 4217 · `risk:high` `type:compliance`

- [x] `RoundingPolicy` resolved per jurisdiction: the mode (half-up by default, because EN 16931 and most VAT regimes specify it, not half-even), the scale, and whether tax is computed per line or per rate group. The mode existed from PF-006; PF-081 added the **basis**, which is the element that gets forgotten - a mode and a scale are not enough to make two systems agree on a document
- [x] The policy is **configuration**, so a jurisdiction that differs is a row and not a branch. An unconfigured profile falls back to the platform default, because refusing to round would make a new jurisdiction unusable until somebody noticed and the noticing happens on an invoice; a basis somebody **wrote and spelled wrong** throws, because that is a decision that was made and then discarded
- [x] A worked example per shipped jurisdiction is committed as a fixture with its expected output to the cent - `tests/Golden/rounding/jurisdictions.json`, six profiles, each with its lines, its net and its tax under **both** bases. The numbers were produced by the code and then written down by hand, so the check compares the code against the file rather than against itself
- [x] A test proves that the two policies produce different, specified answers on the same document - so the policy is demonstrably doing something. Every fixture differs between the bases and the check **refuses one that does not**: an example where they agree demonstrates nothing while sitting in a fixture file looking like evidence
- [x] The **scale is not a per-jurisdiction setting**, deliberately. JOD has three decimals because ISO 4217 says so, not because of Jordan's VAT rules. A regime that mandates rounding to something other than its currency's exponent - the nearest five fils, or Switzerland's 0.05 - is real, is not shipped, and would be a third key in the file rather than a branch anywhere
- [x] `TaxRounding::taxOn()` rounds to the **currency's exponent**, not to the storage scale. Rounding to four places per line and summing gives the same answer as rounding once, which would make the class look like it worked while doing nothing - and that bite fired on exactly that mutation
- [x] The drift between the two bases has **no consistent direction**: the per-group figure is higher for the SAR, EGP and JOD fixtures and lower for the two EUR ones. A check asserts both directions appear, because a per-line total that was always higher could be corrected afterwards with a rounding adjustment and this one cannot

### PF-082 · Tax computation
`Tax` · **8 pts** · depends: PF-081, PF-078 · standards: EN 16931 `BG-23`, `BR-CO-14` · `risk:high` `type:compliance`

- [x] Tax is accumulated **unrounded** per `(category, rate)` group, then rounded once per group - not per line and not once per document
- [x] The result is a VAT breakdown matching EN 16931 `BG-23`, with one entry per unique category-and-rate combination. Keyed on the category **and** the rate: keyed on the category alone a 15%/5% document has one entry reporting a rate that applies to neither, and `15` and `15.00` are normalised into one group. The entries come out in **first-appearance order**, not sorted, because a sort on equal keys is where "recomputing gives identical output" quietly stops being true
- [x] `BR-CO-14` is asserted directly: the document's tax total equals the sum of the breakdown entries - structurally, because there is no second accumulator, and recomputed anyway, because "it cannot differ by construction" is a claim about today's code while the rule is a claim about the document
- [x] Line-level and document-level discounts, charges and allowances are apportioned across rate groups before tax, with the apportionment residual assigned deterministically rather than left to float. By each group's **positive** taxable amount: apportioning by absolute value spreads a discount onto credit lines and makes the credit larger, measured at a net of **-11.00** for a -10.00 line with a 1.00 discount. The shares are allocated at the **storage** scale, since they are intermediate and rounding them first rounds twice
- [x] Property-based test: for any generated document, the sum of line nets equals the document net, the breakdown sums to the tax total, and recomputing the document twice gives identical output. 200 documents per run, and the coverage was **measured** rather than assumed - 195 of 200 compute, 100 with adjustments, 100 with a negative line, one to four entries each. A property check that skips everything passes trivially
- [x] Mixed-rate documents, negative lines and a 100% discount line each have a named test - with exact expected figures, including a negative entry of -2.00 for a credit line at a different rate, and a document whose only line is free (its entry has to survive, or the recipient's validator rejects a category that is missing from `BG-23`)
- [x] Found on the way: one bite did **not** fire. Stopping the per-line basis from seeing the apportioned lines left a document reporting a taxable amount of 140.00 with tax computed on 150.00 - and `BR-CO-14` still held, because every entry was consistent with itself and the document was consistent with nothing. The check now asserts the figure (16.33, not 17.50); the invariant does not notice

### PF-083 · Withholding tax
`Tax` · **5 pts** · depends: PF-082 · standards: — · `type:compliance`

- [x] Withholding is deducted by the **payer from the payment**, not from the invoice - so it lives in the settlement path, not in the document total. Asserted architecturally: a check reads the source of `TaxCalculator`, `TaxBreakdown`, `TaxBreakdownEntry` and `TaxableLine` and refuses any mention of withholding. The moment a document total can see it, somebody puts it on an invoice - and an invoice that nets it off understates the receivable and the VAT base at once
- [x] A rate per party, per item type and per jurisdiction, effective-dated. Resolved by **specificity**, and a party-specific row beats an item-specific one: a party row is normally a treaty rate or an exemption certificate, and the reverse ordering would withhold 20% from a supplier holding a certificate that says 5%
- [ ] An invoice total that does not equal the cash received is **correct** when withholding applies, and the allocation logic must handle it rather than treating the shortfall as a short payment
- [x] A withholding certificate is generated and tracked, because the payee needs it to claim the credit. Numbered from the series, and its **period is the deduction's**: an invoice raised in March and paid in May produces a May certificate, and reporting it in March gives the payee a credit the authority will not match
- [x] Its own reporting, separate from VAT. `totalsFor()` reads the certificates - not the ledger, which holds the posting, and not a VAT accumulator, which is right only where the two filing cycles happen to coincide. Void certificates drop out, because the payee cannot claim against one
- [x] **No rate configured is not a rate of zero.** `rateFor()` returns null for a jurisdiction nobody has set up; a zero rate is how an exemption is recorded, dated and sourced. A payer that withholds nothing because of the first is liable for what it should have withheld, and `deduct()` takes a rate rather than looking one up so a caller cannot skip the distinction
- [x] The **base** is the regime's choice - the net or the gross - and the difference is the whole VAT amount rather than a rounding difference: 1,000 net with 150 of VAT at 10% is 100 withheld on the net and 115 on the gross
- [x] The overlap constraint uses `COALESCE(party_id::text, '-')` rather than the nullable columns: `NULL` is not equal to `NULL` under `=`, so an exclusion over them directly would let two "any party, any item" rows cover the same dates. The cast to text avoids `char(26)`'s blank padding making the sentinel a different value from itself. A successor that **abuts** the predecessor is accepted, because a constraint that made a rate change impossible is a constraint somebody drops
- [x] A certificate for a deduction of zero is **refused**: it tells the payee it may claim nothing, which it can work out without a document, and an exemption belongs on the rate table

> The open criterion is the allocation path, which is PF-120 in Phase 09 - an invoice settled in full against short cash. `payment_reference` is free text until it adds the payment key beside it, and the arithmetic the allocation depends on is asserted here: the payable plus the withheld comes back to the base exactly.

### PF-084 · Tax accounts and posting
`Tax` · **3 pts** · depends: PF-082, PF-047 · standards: — · `type:posting`

- [x] Separate `vat_input` and `vat_output` control accounts per jurisdiction per entity. `account_roles` gained a `jurisdiction` column, **required** for the VAT pair and **refused** for every other role - both halves of one CHECK, because a VAT account with no jurisdiction is unreconcilable and a bank account with one is a value the next reader has to interpret. The unique index collapses `NULL` to a sentinel, since `NULL` is not equal to `NULL` and a plain index would permit two bank roles
- [x] Posting rules for sales tax, purchase tax, reverse charge (both sides) and withholding, each written out as debits and credits - in `RuleDescription`, in Arabic and English, for the inspection screen where an accountant checks a rule without reading PHP. Posted through the real `Poster` in the checks, because a rule can return a balanced set of lines that the poster then refuses
- [x] A non-recoverable portion posts to expense rather than to the input account, which is what makes the input account reconcilable to the return. Irrecoverable tax with **no account to put it on is refused**: the alternative is that it goes somewhere nobody named
- [x] The chart template stamps the entity's **own country** on the VAT roles at provisioning. A template cannot know it - `ifrs-generic` is used by entities anywhere - and what is true at provisioning is that the entity is registered where it is incorporated. A second jurisdiction is a row an operator adds, and a check proves it can be added without disturbing the first
- [x] `PostingContext::account()` takes the jurisdiction and **refuses to default it**. The bite that defaulted it to whatever the entity had assigned was accepted silently, which is exactly the failure: one authority's VAT in the other's return, with a figure that looks plausible in both
- [x] The reverse charge is **one rule, not two**, because the legs are not independently correct: the input without the output reports a claim with no liability, which is the shape of a refund request an authority investigates. For a fully recoverable business they net to nothing - which is why forgetting the output leg is invisible on a trial balance - and for a partly exempt one the output liability is still the whole tax
- [x] No payable line on a reverse charge. The supplier is owed the net and nothing more; crediting the payable shows them as owed money that goes to the authority
- [x] Withholding clears the counterparty account **in full** on both sides, with the deduction on its own control account. Two roles were added - `withholding_receivable` and `withholding_payable` - and assigned in all three chart templates; the Saudi and Jordanian charts already had a withholding payable account with no role

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
