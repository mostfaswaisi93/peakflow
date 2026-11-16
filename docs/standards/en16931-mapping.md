# EN 16931 business term mapping

The internal semantic invoice model, mapped term by term to EN 16931-1. This file and the code must agree, and **a test asserts both directions**: every term the serializers emit appears here, and every term mapped here is emitted.

**Cardinality** is as EN 16931 defines it: `1..1` mandatory single, `0..1` optional single, `1..n` mandatory repeatable, `0..n` optional repeatable. Where a term is conditional, the condition is stated — EN 16931's conditionality is where most implementations go wrong, because a term that is optional in the schema is frequently mandatory under a rule.

**Status** column: ✅ populated · ⚠️ conditional · ⛔ deliberately not populated (with a reason).

The code's side of this agreement is `App\Exchange\Semantics\BusinessTerm` — one enum case per term, carrying its group and its cardinality — and `BusinessGroup`, which carries the nesting. `bin/verify exchange` fails when a term exists on one side and not the other. Cardinality is compared only for rows that name a single term: a row covering `BT-75`–`BT-79` states "0..1 each" and is more readable than five rows, and the registry is where a term's exact cardinality lives.

---

## BG — Document level

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-1` | Invoice number | 1..1 | `invoices.number`, from the gapless series | ✅ |
| `BT-2` | Invoice issue date | 1..1 | `invoices.issue_date` (`date`, never a timestamp) | ✅ |
| `BT-3` | Invoice type code | 1..1 | `invoices.document_type` (UNCL 1001) | ✅ |
| `BT-5` | Invoice currency code | 1..1 | `invoices.currency` (ISO 4217) | ✅ |
| `BT-6` | VAT accounting currency | 0..1 | Entity functional currency, when it differs from `BT-5` | ⚠️ |
| `BT-7` | **Tax point date** | 0..1 | `invoices.tax_point_date`, derived by the jurisdiction rule and **frozen** | ⚠️ |
| `BT-8` | Tax point date code | 0..1 | Mutually exclusive with `BT-7` per `BR-CO-3`; the profile decides which is used | ⚠️ |
| `BT-9` | Payment due date | 0..1 | `invoice_due_dates`; omitted when `BT-20` is used | ⚠️ |
| `BT-10` | Buyer reference | 0..1 | `invoices.buyer_reference` — mandatory under some CIUS | ⚠️ |
| `BT-11` | Project reference | 0..1 | Project dimension value | ⚠️ |
| `BT-12` | Contract reference | 0..1 | `invoices.contract_reference` | ⚠️ |
| `BT-13` | Purchase order reference | 0..1 | `invoices.customer_order_reference` | ⚠️ |
| `BT-14` | Sales order reference | 0..1 | Originating `sales_orders.number` | ⚠️ |
| `BT-15` | Receiving advice reference | 0..1 | ⛔ No goods-receipt concept on the sales side | ⛔ |
| `BT-16` | Despatch advice reference | 0..1 | ⛔ No despatch module | ⛔ |
| `BT-17` | Tender or lot reference | 0..1 | ⛔ Public-procurement only; add with a profile that needs it | ⛔ |
| `BT-18` | Invoiced object identifier | 0..1 | `invoices.object_identifier` with its scheme | ⚠️ |
| `BT-19` | Buyer accounting reference | 0..1 | Party's accounting reference | ⚠️ |
| `BT-20` | Payment terms | 0..1 | Rendered from `payment_terms`, in the recipient's language | ⚠️ |
| `BT-21` | Invoice note subject code | 0..1 in `BG-1` | Legal-note codes, e.g. the reverse-charge note | ⚠️ |
| `BT-22` | Invoice note | 1..1 in `BG-1` | Free-text notes and the mandatory per-jurisdiction legal notes | ⚠️ |
| `BT-23` | Business process type | 0..1 | Set by the jurisdiction profile | ⚠️ |
| `BT-24` | Specification identifier | 1..1 | The profile's customization id (EN 16931, Peppol BIS, ZATCA, …) | ✅ |

### BG-3 — Preceding invoice reference

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-25` | Preceding invoice number | 1..1 | `invoice_references` — **mandatory on a credit note** | ✅ |
| `BT-26` | Preceding invoice issue date | 0..1 | From the referenced document | ✅ |

### BG-4 / BG-5 — Seller

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-27` | Seller name | 1..1 | **Frozen** entity legal name | ✅ |
| `BT-28` | Seller trading name | 0..1 | Frozen entity trading name | ⚠️ |
| `BT-29` | Seller identifier | 0..n | Entity identifiers with their scheme | ⚠️ |
| `BT-30` | Seller legal registration id | 0..1 | Commercial registration, with scheme | ⚠️ |
| `BT-31` | Seller VAT identifier | 0..1 | Frozen `tax_registrations` — one of `BT-29`/`BT-30`/`BT-31` is required by `BR-CO-26`, and the per-category rules require this one where VAT is charged | ⚠️ |
| `BT-32` | Seller tax registration id | 0..1 | A second scheme where a jurisdiction has one | ⚠️ |
| `BT-33` | Seller additional legal information | 0..1 | Capital, legal form, where required | ⚠️ |
| `BT-34` | Seller electronic address | 0..1 | With its **EAS** scheme code — mandatory for Peppol | ⚠️ |
| `BT-35`–`BT-39` | Seller address lines, city, post code, subdivision | 0..1 each | Frozen address | ✅ |
| `BT-40` | Seller country code | 1..1 | ISO 3166-1 alpha-2 | ✅ |
| `BT-41`–`BT-43` | Seller contact name, phone, email | 0..1 each | Entity billing contact | ⚠️ |

### BG-7 / BG-8 — Buyer

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-44` | Buyer name | 1..1 | **Frozen** party legal name | ✅ |
| `BT-45` | Buyer trading name | 0..1 | Frozen trading name | ⚠️ |
| `BT-46` | Buyer identifier | 0..1 | Party identifier with scheme | ⚠️ |
| `BT-47` | Buyer legal registration id | 0..1 | With scheme | ⚠️ |
| `BT-48` | Buyer VAT identifier | 0..1 | Frozen registration — **mandatory for reverse charge and intra-community** | ⚠️ |
| `BT-49` | Buyer electronic address | 0..1 | With its EAS scheme code — mandatory for Peppol | ⚠️ |
| `BT-50`–`BT-54` | Buyer address lines, city, post code, subdivision | 0..1 each | Frozen address | ✅ |
| `BT-55` | Buyer country code | 1..1 | ISO 3166-1 alpha-2 | ✅ |
| `BT-56`–`BT-58` | Buyer contact name, phone, email | 0..1 each | Party billing contact | ⚠️ |

### BG-10 / BG-11 / BG-13 — Payee, tax representative, delivery

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-59`–`BT-61` | Payee name, identifier, legal registration | 0..1 each | Factoring and assignment; omitted when the payee is the seller | ⚠️ |
| `BT-62` | Tax representative name | 1..1 in `BG-11` | Where a jurisdiction requires one | ⚠️ |
| `BT-63` | Tax representative VAT identifier | 1..1 in `BG-11` | `BR-56` — a representative with no VAT number is not one | ⚠️ |
| `BT-64`–`BT-68` | Tax representative address lines, city, post code, subdivision | 0..1 each | Frozen address | ⚠️ |
| `BT-69` | Tax representative country code | 1..1 in `BG-12` | `BR-20` | ⚠️ |
| `BT-70`–`BT-71` | Deliver-to name and location identifier | 0..1 each | Delivery address | ⚠️ |
| `BT-72` | Actual delivery date | 0..1 | `invoices.delivery_date` — distinct from `BT-2` **and** `BT-7` | ⚠️ |
| `BT-73`–`BT-74` | Invoicing period start and end | 0..1 each | Service period, which also drives IFRS 15 recognition | ⚠️ |
| `BT-75`–`BT-79` | Deliver-to address lines, city, post code, subdivision | 0..1 each | Frozen delivery address | ⚠️ |
| `BT-80` | Deliver-to country code | 1..1 in `BG-15` | `BR-57` — a delivery address without a country is not an address | ⚠️ |

### BG-16 — Payment instructions

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-81` | Payment means type code | 1..1 | UNCL 4461 | ✅ |
| `BT-82` | Payment means text | 0..1 | Human-readable description | ⚠️ |
| `BT-83` | Remittance information | 0..1 | **The reference matching pass 1 looks for.** Generated, stable, and printed on the PDF | ✅ |
| `BT-84` | Payment account identifier | 1..1 in `BG-17` | Receiving `bank_accounts.iban`; `BR-50` — a credit transfer with no account is not one | ⚠️ |
| `BT-85` | Payment account name | 0..1 | Account holder | ⚠️ |
| `BT-86` | Payment service provider id | 0..1 | BIC | ⚠️ |
| `BT-87`–`BT-88` | Payment card number and holder | 1..1 / 0..1 in `BG-18` | ⛔ No card data is handled, by design | ⛔ |
| `BT-89`–`BT-91` | Mandate reference, creditor identifier, debited account | 0..1 each | Populated for direct debit | ⚠️ |

### BG-20 / BG-21 — Document-level allowances and charges

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-92` | Document level allowance amount | 1..1 in `BG-20` | `invoice_allowances_charges.amount` | ⚠️ |
| `BT-93`–`BT-94` | Allowance base amount and percentage | 0..1 each | Where the allowance was computed from a percentage | ⚠️ |
| `BT-95` | Allowance VAT category code | 1..1 in `BG-20` | **Apportioned across rate groups before tax**, residual assigned deterministically | ⚠️ |
| `BT-96`–`BT-98` | Allowance rate, reason, reason code | 0..1 each | `BR-CO-21` requires a reason or a reason code | ⚠️ |
| `BT-99` | Document level charge amount | 1..1 in `BG-21` | Same mechanism | ⚠️ |
| `BT-100`–`BT-101` | Charge base amount and percentage | 0..1 each | Same mechanism | ⚠️ |
| `BT-102` | Charge VAT category code | 1..1 in `BG-21` | Same mechanism | ⚠️ |
| `BT-103`–`BT-105` | Charge rate, reason, reason code | 0..1 each | `BR-CO-22` requires a reason or a reason code | ⚠️ |

### BG-22 — Document totals

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-106` | Sum of line net amounts | 1..1 | Σ `BT-131`. `BR-CO-10` | ✅ |
| `BT-107` | Sum of allowances | 0..1 | Σ `BT-92` | ⚠️ |
| `BT-108` | Sum of charges | 0..1 | Σ `BT-99` | ⚠️ |
| `BT-109` | Invoice total without VAT | 1..1 | `BT-106` − `BT-107` + `BT-108`. `BR-CO-13` | ✅ |
| `BT-110` | Invoice total VAT amount | 0..1 | **Σ `BT-117` across the breakdown. `BR-CO-14`** | ⚠️ |
| `BT-111` | VAT amount in accounting currency | 0..1 | Converted at the tax point rate when `BT-6` is present | ⚠️ |
| `BT-112` | Invoice total with VAT | 1..1 | `BT-109` + `BT-110`. `BR-CO-15` | ✅ |
| `BT-113` | Paid amount | 0..1 | Prepayments settled against this invoice | ⚠️ |
| `BT-114` | Rounding amount | 0..1 | Only where a jurisdiction requires document rounding | ⚠️ |
| `BT-115` | Amount due for payment | 1..1 | `BT-112` − `BT-113` + `BT-114`. `BR-CO-16` | ✅ |

### BG-23 — VAT breakdown

**One entry per unique `(category, rate)` pair.** This is the group the tax engine accumulates unrounded and rounds once.

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-116` | VAT category taxable amount | 1..1 | Group net | ✅ |
| `BT-117` | VAT category tax amount | 1..1 | Group tax, **rounded once at group level** | ✅ |
| `BT-118` | VAT category code | 1..1 | UNCL 5305 | ✅ |
| `BT-119` | VAT category rate | 0..1 | Resolved by **tax point**, so a historical document keeps its rate | ⚠️ |
| `BT-120` | VAT exemption reason text | 0..1 | Mandatory for `E`, `AE`, `K`, `G`, `O` per `BR-E-10`, `BR-AE-10`, `BR-IC-10`, `BR-G-10`, `BR-O-10`. **Not** for `Z`: zero-rated is a rate, not an exemption, and there is no `BR-Z-10` | ⚠️ |
| `BT-121` | VAT exemption reason code | 0..1 | Coded equivalent, where the profile defines a code list | ⚠️ |

### BG-24 — Additional supporting documents

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-122` | Supporting document reference | 1..1 in `BG-24` | The attachment's own reference; `BR-52` | ⚠️ |
| `BT-123` | Supporting document description | 0..1 | What the attachment is | ⚠️ |
| `BT-124` | External document location | 0..1 | A URL, where the document is hosted rather than embedded | ⚠️ |
| `BT-125` | Attached document | 0..1 | The bytes, base64, with their MIME type and filename — how Factur-X embeds its XML (PF-125) | ⚠️ |

An attachment is carried rather than linked where the recipient has to keep it: a URL in an archived invoice is a document that disappears when the host does, and the retention period is measured in years.

## BG-25 — Invoice line

| Term | Name | Card. | Source | Status |
|------|------|-------|--------|--------|
| `BT-126` | Line identifier | 1..1 | Line sequence, stable across re-serialization | ✅ |
| `BT-127` | Line note | 0..1 | Line note | ⚠️ |
| `BT-128` | Line object identifier | 0..1 | With scheme | ⚠️ |
| `BT-129` | Invoiced quantity | 1..1 | `numeric(21,6)` | ✅ |
| `BT-130` | Quantity unit of measure | 1..1 | UN/CEFACT Rec 20 | ✅ |
| `BT-131` | Line net amount | 1..1 | `round_half_up(unit_price × quantity, 4)` — **rounded once here** | ✅ |
| `BT-132` | Order line reference | 0..1 | Originating order line | ⚠️ |
| `BT-133` | Line accounting reference | 0..1 | Dimension values | ⚠️ |
| `BT-134`–`BT-135` | Line period start and end | 0..1 each | Service period per line | ⚠️ |
| `BT-136` | Line allowance amount | 1..1 in `BG-27` | Line-level allowances | ⚠️ |
| `BT-137`–`BT-140` | Line allowance base, percentage, reason, reason code | 0..1 each | `BR-CO-23` requires a reason or a reason code | ⚠️ |
| `BT-141`–`BT-145` | Line charge equivalents | 0..n | Line-level charges | ⚠️ |
| `BT-146` | Item net price | 1..1 | `numeric(21,8)` — eight decimals, so per-litre and per-kWh pricing needs no scaling factor | ✅ |
| `BT-147` | Item price discount | 0..1 | Against `BT-148` | ⚠️ |
| `BT-148` | Item gross price | 0..1 | List price before the discount | ⚠️ |
| `BT-149`–`BT-150` | Item price base quantity and its unit | 0..1 each | Per-thousand and per-hundred pricing | ⚠️ |
| `BT-151` | Line VAT category code | 1..1 | UNCL 5305 | ✅ |
| `BT-152` | Line VAT rate | 0..1 | Resolved by tax point | ⚠️ |
| `BT-153` | Item name | 1..1 | **The line's own frozen description**, not a live item reference | ✅ |
| `BT-154` | Item description | 0..1 | Frozen | ⚠️ |
| `BT-155` | Item seller identifier | 0..1 | `items.code` | ⚠️ |
| `BT-156` | Item buyer identifier | 0..1 | The customer's own code for the item | ⚠️ |
| `BT-157` | Item standard identifier | 0..1 | GTIN, with scheme | ⚠️ |
| `BT-158` | Item classification identifier | 0..n | **With its scheme id** — UNSPSC, GPC or UNCL 7143. A bare code is not conformant | ⚠️ |
| `BT-159` | Item country of origin | 0..1 | ISO 3166-1 | ⚠️ |
| `BT-160`–`BT-161` | Item attribute name and value | 1..1 each in `BG-32` | Item attributes; `BR-54` requires both | ⚠️ |

## The calculation rules, asserted directly

Each has a dedicated failing-case test, because a calculation rule that is only checked by the validator is a rule nobody understands:

| Rule | Assertion |
|------|-----------|
| `BR-CO-10` | `BT-106` = Σ `BT-131` |
| `BR-CO-11` | `BT-107` = Σ `BT-92` |
| `BR-CO-12` | `BT-108` = Σ `BT-99` |
| `BR-CO-13` | `BT-109` = `BT-106` − `BT-107` + `BT-108` |
| **`BR-CO-14`** | **`BT-110` = Σ `BT-117`** — the reason tax is grouped rather than per-line |
| `BR-CO-15` | `BT-112` = `BT-109` + `BT-110` |
| `BR-CO-16` | `BT-115` = `BT-112` − `BT-113` + `BT-114` |
| `BR-CO-17` | `BT-117` = `BT-116` × (`BT-119` ÷ 100), within rounding tolerance |
| `BR-CO-3` | `BT-7` and `BT-8` are mutually exclusive |
| `BR-CO-9` | A VAT identifier begins with the ISO 3166-1 alpha-2 code of its issuing country |
| `BR-CO-26` | The seller carries an identifier, a legal registration or a VAT number, so the buyer can match it automatically |
| `BR-53` | A VAT accounting currency (`BT-6`) brings the VAT amount in it (`BT-111`) |

## Terms deliberately not populated

| Term | Reason |
|------|--------|
| `BT-15` Receiving advice reference | No goods-receipt concept on the sales side |
| `BT-16` Despatch advice reference | No despatch module; PeakFlow is not a logistics system |
| `BT-17` Tender or lot reference | Public-procurement specific; added with a profile that requires it |
| `BT-87`, `BT-88` Card information | **No card data is handled, by design.** Avoiding PCI DSS scope is the point |

An omission is a decision, and a decision has a reason. A term with neither does not get added.
