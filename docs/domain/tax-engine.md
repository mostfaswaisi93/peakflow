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

| Code | Name | Taxable supply? | Input recovery | Notes |
|------|------|-----------------|----------------|-------|
| `S` | Standard rate | Yes | Yes | The default |
| `Z` | Zero-rated | **Yes**, at 0% | **Yes** | A taxable supply; recovery is preserved |
| `E` | Exempt | **No** | **Restricted** | Not a taxable supply; restricts input recovery |
| `AE` | Reverse charge | Yes, by the recipient | Yes, by the recipient | No tax on the document; a mandatory legal note |
| `O` | Out of scope | No | n/a | Outside the regime entirely |
| `K` | Intra-community supply | Yes, at 0% | Yes | Requires both registrations |
| `G` | Export | Yes, at 0% | Yes | Requires proof of export |

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

## Rate versioning

```sql
ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_no_overlap
    EXCLUDE USING gist (tax_code_id WITH =, validity WITH &&);
```

A rate change creates a **new version** and never edits the old one. A document resolves its rate by its tax point, so a historical document keeps the rate that was in force. Overlapping validity for one code is refused by the database, not by application validation alone — a rate seeded by a migration would bypass the latter.

A test covers a rate change mid-month and proves documents either side of it resolve differently.

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
