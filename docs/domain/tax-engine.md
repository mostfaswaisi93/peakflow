# The tax engine

**Tax is data, not code.** A rate that changes on a date is a row. A jurisdiction's rounding rule is a row. A place-of-supply decision is a rule evaluated from rows. Nothing about a specific country is compiled into the sales path.

The engine is built in Phase 04, **before** sales, and that sequencing is the most consequential decision in the plan: the tax point date, the rate version and the per-rate-group breakdown are *inside* an issued document and frozen there. Retrofitting them means migrating immutable rows a customer has already filed a return on.

## Three dates, and they are not the same

| Date | Term | Determines |
|------|------|------------|
| Invoice date | `BT-2` | When the document was issued |
| **Tax point** | `BT-7` | **Which return period the tax falls in** |
| Delivery date | `BT-72` | When the supply occurred |

Derivation of the tax point is a **per-jurisdiction rule**: the invoice date, the delivery date, the payment date, or the earlier of a pair. The derived value is **stored on the document**, not recomputed — a document issued on the 1st for a supply on the 30th belongs to the earlier return in many jurisdictions, and that decision must be frozen at issue.

A document's accounting period and its return period can therefore differ. Both are stored, and a document whose tax point falls in an **already-filed** period is refused rather than silently landing in the next one.

## Categories

| Code | Name | Taxable supply? | Input recovery | Must be 0% | Notes |
|------|------|-----------------|----------------|:---:|-------|
| `S` | Standard rate | Yes | Yes | | The default, and the only category needing no reason |
| `Z` | Zero-rated | **Yes**, at 0% | **Yes** | ✓ | A taxable supply; recovery is preserved |
| `E` | Exempt | **No** | **Restricted** | ✓ | Not a taxable supply; restricts input recovery |
| `AE` | Reverse charge | Yes, by the recipient | Yes, by the recipient | ✓ | No tax on the document; a mandatory legal note |
| `O` | Out of scope | No | Restricted | ✓ | Outside the regime entirely |
| `K` | Intra-community supply | Yes, at 0% | Yes | ✓ | Requires both registrations; needs evidence |
| `G` | Export | Yes, at 0% | Yes | ✓ | Requires proof of export |
| `L` | Canary Islands (IGIC) | Yes | Yes | | A separate regime with its own non-zero rates |
| `M` | Ceuta and Melilla (IPSI) | Yes | Yes | | As above |

`L` and `M` are in the list because EN 16931 requires them: a Spanish seller with Canary Islands customers cannot issue a conformant invoice without them. They are separate regimes rather than rates of Spanish VAT, which is why they are categories — and why they may carry a rate where the other six zero categories may not.

**The categories are code, not configuration** — `App\Tenant\Tax\Enums\TaxCategory`. Everything else in this engine is data, and these are the exception: a category's meaning is fixed by a published code list, so putting it in configuration makes it a thing an operator can change. The change that gets made is *"exempt supplies are input-recoverable"*, because somebody is reconciling an input tax box that will not balance and that flag is the fastest way to make the number move.

`restrictsInputRecovery()` is deliberately not a boolean called `inputRecoverable()`. The **degree** of restriction is a jurisdiction rule — partial exemption methods differ, and some regimes allow recovery below a de minimis — so a flag saying "recoverable: false" would be a lie in every regime that has a method. What is universal is which categories trigger the question, and the apportionment belongs to PF-084.

One more consequence of collapsing `Z` into `E`: registration thresholds are computed from **taxable turnover**, so it can make a business appear not to need a registration it does need.

> **`Z`, `E` and `O` are three different things and must never be modelled as "0% with a note".** Zero-rated preserves input recovery; exempt restricts it; out of scope is not in the regime. Collapsing them produces a return that is wrong in the input-tax boxes, which is the half nobody checks until an audit.

Each category that requires evidence — a reason code for an exemption, proof of export, a counterparty registration for intra-community — **cannot be issued without it recorded**.

## Place of supply

A rule engine, evaluated from data with a documented source per jurisdiction, deciding which jurisdiction's tax applies:

| Input | Why it matters |
|-------|----------------|
| Supplier country and registration | The default jurisdiction |
| Customer country | Cross-border determination |
| Customer registration status (B2B / B2C) | Reverse charge and digital-service rules turn on it |
| Item type: `good`, `service`, `digital_service` | Different rules per type — which is why it is a first-class field on the item, not a category |
| Delivery location | Can override the customer's country |

Rules are evaluated in a defined precedence and **the winning rule is recorded on the document**, so an accountant can check the decision. The shipped cases are domestic, export of goods, intra-GCC, intra-EU B2B goods and services, and digital services to consumers — plus a documented fallback that **refuses rather than guesses**.

**As built (PF-078):** ten rules in [`config/place_of_supply.php`](../../config/place_of_supply.php), each with its article; the engine knows five conditions and eight words and no country at all. Precedence is an integer in the row rather than the row's position, because array order is invisible in a diff. `abroad` and `outside` are two different words, and the distinction is the whole of intra-bloc treatment. A missing delivery location refuses rather than falling through to a rule that the blocked one outranks.

The full account, including the three orderings that are not obvious and why the United Kingdom is a dated departure rather than a deletion, is in [`place-of-supply.md`](place-of-supply.md).

## Rate versioning

```sql
ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_no_overlap
    EXCLUDE USING gist (tax_code_id WITH =, validity WITH &&);
```

**Two tables, because a category is not an attribute of a rate.** `tax_codes`
carries what a code *is* — its category, jurisdiction, labels, exemption reason,
return boxes — and `tax_rates` carries what it *was*, version by version.

That split is structural rather than tidy. The first implementation versioned
everything in one table, which made the category an attribute of each rate
version, so a rate change could silently also change the treatment and the
service had to refuse it in application code. Structure that cannot express the
mistake beats a refusal that has to remember to fire.

A rate change creates a **new version** and never edits the old one. A document
resolves its rate by its tax point, so a historical document keeps the rate that
was in force. Overlapping validity for one code is refused by the database, not
by application validation alone — a rate seeded by a migration would bypass the
latter.

### Validity is a half-open range

`validity` is a `daterange` with `[)` bounds: inclusive start, exclusive end. So
"in force on a date" is `validity @> date`, one containment test that the query
and the exclusion constraint share — two nullable date columns need a `COALESCE`
in both places, and the day they are written with `<` in one and `<=` in the
other is the day a document resolves two rates.

It also means a successor's start **is** its predecessor's end. They abut
exactly, with no gap and no overlap, and no call site does arithmetic on "the
day before":

```
[2018-01-01,2020-07-01)   5%
[2020-07-01,)            15%
```

Making that end bound inclusive was tried as a deliberate break, and the
exclusion constraint itself rejected the rate change — the database catches the
off-by-one.

### Measured

Saudi Arabia went from 5% to 15% on 1 July 2020. A system holding one rate per
code reprints every pre-July invoice at 15%, and reprinting an invoice is what
you do when a customer disputes it or an authority asks for it.

```
tax point 2018-06-30  ->   5.000000000000 %   (fraction 0.050000000000)
tax point 2020-06-30  ->   5.000000000000 %
tax point 2020-07-01  ->  15.000000000000 %   (fraction 0.150000000000)
tax point 2026-11-15  ->  15.000000000000 %
tax point 2017-12-31  ->  refused
```

The last line matters as much as the others: a tax point before the code's first
version is **refused rather than guessed**, because resolving the nearest version
would silently apply a rate that was not law on the day of the supply.

There is deliberately no method called `current()` on the resolver. A resolver
that reads the active row gets today's rate onto a historical invoice, and the
invoice is wrong in a way that only shows when somebody compares it to the return
it was filed on.

Superseding refuses three things, each because it would rewrite history: a
version starting on or before the one it replaces, a code with nothing
open-ended to close, and a code that does not exist.

### What the database refuses rather than the application

| Constraint | Refuses |
|------------|---------|
| `tax_rates_no_overlap` | Two versions covering one day. Which rate a document gets would depend on row order, so the same invoice computed twice could produce two different tax amounts |
| `tax_rates_respect_the_category` | A `Z` row at 5%, which is not a zero rate, or an `E` row at 5%, which is a taxable supply wearing an exempt label. A trigger rather than a CHECK, because the category lives on the parent row and a CHECK cannot read another table — and it fires on a category change too, since either side can break it |
| `tax_codes_non_standard_carries_a_reason` | Any non-standard category without an EN 16931 `BT-121` code and `BT-120` text |
| `tax_rates_source_is_present` | A rate with no citation. The question asked six months later is *"where did 15% come from"* |
| `tax_rates_validity_has_a_start` | A range with no lower bound, which is in force before the tax existed |

Each is attempted **through the model, bypassing the service**, because a check
that went through the service would pass while the constraint was missing.

### Percentages, not fractions

The `rate` column holds `15.000000` — fifteen percent, written the way an
authority publishes it and an invoice prints it. A column holding `0.15` means
every reader has to remember which it is, and the one written the way everybody
says it is the one that gets checked. So the division happens in exactly one
place, `TaxRate::fraction()`, because multiplying a net amount by 15 is a mistake
waiting for somebody to make it.

It uses `BigDecimal` and not `bcdiv`: `bcmath` is under `suggest` in
`composer.json`, so the extension is absent on this deployment and a `bc*` call
is a fatal error at the moment somebody computes tax. An architecture rule now
refuses the whole family — and that rule was itself wrong on its first attempt,
matching nothing because an optional namespace separator written as `\\?` became
a required literal `?` inside a single-quoted PHP string.

## Computation

```
for each line:
    line_net = round_half_up( unit_price × quantity, 4 )
    accumulate line_net into group[(category, rate)]

apportion document-level discounts and charges across groups
    → residual assigned deterministically to the largest group

for each group:
    tax = round_half_up( group_net × rate, 4 )      ← rounded ONCE, per group

document_net = Σ line_net
document_tax = Σ group_tax                           ← BR-CO-14
document_gross = document_net + document_tax
```

**Tax is accumulated unrounded per `(category, rate)` group and rounded once per group** — not per line, and not once per document. EN 16931 requires a VAT breakdown (`BG-23`) with one entry per unique category-and-rate combination, and `BR-CO-14` states the document's tax total equals the sum of those entries.

Per-line rounding gives a different and usually wrong answer. The worked example is in [`money-and-precision.md`](../architecture/money-and-precision.md#worked-example-why-per-line-rounding-fails): three lines of 10.03 at 15% give 4.51 computed per group and 4.50 computed per line. One cent per document, across 50,000 documents a quarter, is a reconciliation difference between the ledger and the filed return that nobody can locate — because every individual document looks correct.

**Half-up, not banker's rounding**, and it is a jurisdiction setting rather than a preference. EN 16931 and most VAT regimes specify half away from zero; IEEE 754 and many language defaults use half-even. `brick/money` requires the mode explicitly, which is why it was chosen over a hand-rolled helper — there is no default to get wrong silently.

Cases with their own named tests: mixed-rate documents, negative lines, a 100% discount line, a document-level discount that does not divide evenly across groups, and a line in a three-decimal currency.

## Reverse charge, both halves

The half most systems get right:

```
Sales:  Dr accounts_receivable 1,000 / Cr revenue (1,000)      — no tax on the document
```

The half most systems forget:

```
Purchasing: Dr expense     1,000
            Dr vat_input     150
            Cr vat_output   (150)      ← the recipient self-assesses
            Cr accounts_payable (1,000)
```

Modelled as the `AE` category, not as a zero rate with a comment. The invoice carries a **mandatory legal note** whose text is per jurisdiction, and issuing reverse-charge without both parties holding a valid registration is refused.

### As built (PF-079)

`AE` and `Z` both leave the tax column empty, which is exactly why they get conflated — and they are not the same in any return anywhere. A zero-rated supply is taxable at 0%: it counts toward taxable turnover and toward registration thresholds. A reverse charge moves the liability. Model the first as the second and three things break at once: the supplier's return reports a taxable supply it did not make, the customer's reports nothing, and nobody notices until an authority matches the two.

**The note is part of the treatment, not presentation.** Six profiles in `config/jurisdictions.php`, each with Arabic and English text and the article it comes from — Art. 226(11a) for the EU mention, Art. 47 and 53 for Saudi Arabia, Art. 283-2 CGI for France. A profile with no note **refuses**: the fallback prints a document that is missing a mandatory legal mention and looks complete.

Two of those articles are marked `article unverified here`, because the instrument is certain and the article number was not verifiable in this environment. A guessed article number is worse than a general citation: it reads as checked, and it is the first thing an auditor looks up. The check requires a citation **or** that marker, which is the same stance [`database/iso/provenance.json`](../../database/iso/provenance.json) takes.

**Both registrations are judged on the tax point, not on today.** A document raised in May for a supply in March is judged on March, and a registration that lapsed in April was valid then. `party_tax_registrations` gained a half-open `daterange` with an exclusion constraint over `(party_id, jurisdiction, scheme)` — not including the identifier, because a renumbered business has two identifiers whose periods must not overlap. `Entity::primaryTaxRegistration()` answers for today; PF-079 added `taxRegistrationOn()` for the question a document actually asks.

A refusal distinguishes **no registration** from **one that had lapsed**, with the date. The two send somebody to different places — the record, or the customer — and a refusal that cannot tell them apart gets resolved by typing a number in.

**The layering**: the tax engine may not depend on `Parties`, so it declares what it needs as `CounterpartyRegistrations` and `Sales\Services\CounterpartyRegistrationReader` supplies it. The two sides of `assertIssuable()` are deliberately asymmetrical: an `Entity` is the tenant's own company and lives in `Tenancy`, which Tax depends on throughout; a party is a counterparty, and the tax engine has no business knowing what one is.

The purchasing half — posting both the input and the output tax on the bill — is Phase 07. It is what makes a reverse charge net to nothing for a fully recoverable customer and cost money for one that is partly exempt.

Checked by `php bin/verify tax`: nine rules in `tests/Support/ReverseChargeRules.php`, each proven to fail when the behaviour is removed, including a bite on the reader that drops the end date so no registration ever lapses.

## Zero-rated, exempt and out of scope

### As built (PF-080)

All three show nothing in the tax column, which is why they get conflated, and the conflation is invisible on the invoice and wrong in every return:

| | Taxable supply | Input recovery | On the return |
|---|---|---|---|
| **zero-rated** `Z` | yes, at 0% | intact | yes, in the zero-rated box |
| **exempt** `E` | no | restricted — a cost the business bears | yes, as an exempt supply |
| **out of scope** `O` | no | restricted | not at all |

Collapsing them moves figures between boxes, changes a registration-threshold calculation (which is computed from **taxable** turnover, so treating zero-rated as exempt can make a business appear not to need a registration it does need), and claims input tax that is not recoverable.

**Evidence is declared on the place-of-supply rule, not derived from the category.** Three kinds: proof of export, proof of dispatch, and the customer's registration. `EvidenceGate::assertSatisfied()` refuses a treatment whose evidence is not recorded, and each item carries a **reference** — a customs declaration number — rather than a boolean, because a ticked box with nothing behind it is worse than nothing: the system says the evidence exists, so nobody looks for it, and the assessment is unanswerable.

Proof of dispatch is a separate kind from proof of export, and the difference is the one that catches people. An intra-Community supply is exempt in the state of dispatch **only** if the goods arrived somewhere else in the Community, and there is no customs declaration to prove it, because there is no customs frontier.

**Exemption reason codes are data, validated.** `config/tax_evidence.php` holds a VATEX subset keyed by code, each mapped to the category it belongs to with bilingual text and its article. A code used with the wrong category is refused: `vatex-eu-ic` on an exempt line reports an intra-Community supply that did not happen, and the other member state will look for a figure in a box and not find it.

The list is an explicit **subset**, and a code absent from it is refused rather than passed through. That blocks a legitimate exemption whose code nobody has added — an availability problem fixed by a row, and the right direction, because a code is the first thing a receiving system validates and an invented one fails a document that was otherwise correct, after filing. `L` and `M` are named in the config as categories with no code shipped, so the refusal points at the gap instead of looking like a bug in the gate.

The gate exists before documents do: PF-080's last criterion is that a document cannot be **issued** without its evidence, and that is Phase 05 calling this.

Checked by `php bin/verify tax`: ten rules in `tests/Support/TaxEvidenceRules.php`.

## Withholding tax

Withholding is deducted **by the payer from the payment**, not from the invoice. It therefore lives in the settlement path, with three consequences:

1. **An invoice total that does not equal the cash received is correct** when withholding applies. Naive allocation logic treats the shortfall as a short payment and either leaves the invoice open forever or writes off the difference as bad debt. Both are wrong.
2. The allocation posts `Dr withholding_tax` for the deducted amount, closing the receivable in full.
3. A **withholding certificate** is generated and tracked, because the payee needs it to claim the credit, and it has its own reporting separate from VAT.

Rates are per party, per item type and per jurisdiction, effective-dated.

## Returns

A return is a period's **box-by-box summary with drill-down from every box to the journal lines behind it.**

### Before filing

A pre-filing checklist, reporting every blocking item at once rather than failing on the first:

- Unposted documents in the period
- Unmatched bank lines
- A non-zero suspense balance
- Documents missing the evidence their category requires
- A non-zero unexplained difference between the tax control accounts and the return

### Filing

Filing **freezes a snapshot** — the figures, the supporting line ids and a hash — which cannot change afterwards even if the underlying data is later adjusted. It is permissioned, requires MFA, and is audited. A filed return is **re-printable byte-identically**, which is what gets attached to a query from the authority.

### After filing

An adjustment to a filed period lands in the **next open** return period, carrying a reference to the period it corrects. A filed return is never edited; a correction is a new document with its own audit trail, and an adjustments schedule accompanies the next return. Where a jurisdiction requires an amended return instead, the profile declares it and the flow differs accordingly.

## Tax account reconciliation

The check that proves the return and the ledger agree, and the one that makes the whole engine trustworthy:

```
opening vat_output balance
  + output tax posted in the period
  − output tax paid or offset
  = closing vat_output balance        ← must tie to the return's output boxes
```

Differences are **itemized and classified**, never netted into a single reconciling figure. A non-zero unexplained difference **blocks the period close** unless a controller signs it off with a reason — and that sign-off appears on the compliance evidence pack. The assertion is part of `ledger:verify`, per period.
