# Place of supply

**PF-078.** Which jurisdiction's tax applies to a supply, and which rule said so.

A tax rate is useless without a jurisdiction. Before anything can be charged, the system has to decide whose tax this supply is subject to, and that decision depends on five facts: what is being supplied, where the supplier is, where the customer is, whether the customer can account for the tax itself, and where the goods actually went.

The decision is made by `App\Tenant\Tax\Services\PlaceOfSupply`. It knows how to compare those five facts against a rule book and how to refuse. It does not know a single country.

---

## The rules are data

Every country, bloc, article and treatment is in [`config/place_of_supply.php`](../../config/place_of_supply.php). Adding a jurisdiction is a row with a legal citation; it is not a code change, a deploy or a match arm.

This is Phase 04's organising principle applied to the hardest case. A place-of-supply rule compiled into the sales path cannot be reviewed by the person who knows whether it is right — an accountant can read a table of countries and articles, and cannot read a `match` statement.

### First match wins, by explicit precedence

Rules carry a `precedence` integer and are evaluated in ascending order. The first rule whose conditions all hold decides.

Precedence is a number in the row rather than the row's position in the file, because array order is invisible in a diff. A rule moved up three lines changes what thousands of documents charge and reads as a reformatting. Numbers are spaced by ten so a rule can be inserted between two without renumbering — renumbering being the other way a rule book silently changes meaning.

The checks assert both halves of this: that precedence decides, and that reversing the order of two rules in the file changes nothing.

### The condition vocabulary

| Condition | Words |
|---|---|
| `supply_type` | `goods`, `services`, `digital_services`, `*` |
| `supplier` | `*`, `bloc:<name>`, an ISO 3166-1 alpha-2 code |
| `customer` | `*`, `same`, `abroad`, `outside`, `bloc:<name>`, alpha-2 |
| `status` | `business`, `consumer`, `*` |
| `delivery` | `*`, `same`, `abroad`, `outside`, `bloc:<name>`, alpha-2 |

Any condition may be a list, which matches if any element matches.

- `same` — the supplier's own country
- `abroad` — any other country
- `outside` — another country that shares no bloc with the supplier
- `bloc:x` — a member of bloc x; on `customer` and `delivery` this excludes the supplier's own country, so a bloc rule can never also catch the domestic case

There are no expressions, no callbacks and no boolean nesting. A rule book with a language in it is a program, and a program in configuration is a program nobody tests. What the vocabulary cannot express is added as a word with a documented meaning and a check — not as a clever row.

### Why `abroad` and `outside` are two different words

Because the distinction is the whole of intra-bloc treatment.

A French supplier delivering to Germany is `abroad` and `bloc:eu`. The same supplier delivering to Switzerland is `abroad` and `outside`. One is an intra-Community supply reported in category `K`; the other is an export in category `Z` that cannot be zero-rated without evidence of exit. A single "is the customer foreign" flag collapses them, and the collapse is not visible in the answer — both produce a country and a rule id.

---

## The shipped rules

| # | Rule | Place | Category | Evidence |
|---|---|---|---|---|
| 10 | `goods-delivered-in-the-supplier-state` | supplier | `S` | — |
| 20 | `intra-gcc-goods-to-a-business` | delivery | `AE` | customer registration |
| 30 | `intra-gcc-goods-to-a-consumer` | supplier | `S` | — |
| 40 | `intra-eu-goods-to-a-business` | delivery | `K` | dispatch proof, customer registration |
| 50 | `intra-eu-goods-to-a-consumer` | delivery | `S` | — |
| 55 | `goods-exported-from-the-eu` | supplier | `G` | export proof |
| 60 | `goods-exported` | supplier | `Z` | export proof |
| 70 | `digital-services-to-a-consumer-abroad` | customer | `S` | — |
| 80 | `services-to-a-business-abroad` | customer | `AE` | customer registration |
| 90 | `services-to-a-consumer-abroad` | supplier | `S` | — |
| 100 | `domestic` | supplier | `S` | — |

Each row's legal source is in the config file beside it.

### Why an export is two rows

EN 16931 has a category for an export — `G`, "free export item, VAT not charged" — and a separate one for a domestic zero-rated supply, `Z`. The Saudi regime zero-rates exports instead: Art. 32 of the Implementing Regulations, and a ZATCA invoice carries `Z` with the article as its reason.

One row carrying `Z` for everybody was wrong for an EU supplier; one carrying `G` would be wrong for a Saudi one. Which code an export takes is a fact about a regime, so it is two rows — and this is the case that shows why the rule book has to hold the category rather than derive it from "is it cross-border".

It is also why the **evidence requirement is on the rule and not on the category**. `Z` is what a Saudi export carries and also what a domestic zero-rated foodstuff carries. Derive the requirement from the category and either every loaf of bread needs a customs declaration, or no Saudi export needs one. `TaxCategory::requiresEvidence()` remains as a floor, so a rule that forgot its list cannot claim less than the code list demands.

### Three orderings that are not obvious

**Goods that never leave come first.** A French customer collecting goods from a Saudi warehouse is a Saudi supply. The goods did not move, and a billing address is not evidence that they did. A rule book that tested the customer's country first would zero-rate that supply as an export, which is the most commonly assessed export-evidence failure there is.

**Domestic comes last.** Every rule above it either requires the goods to have moved or the customer to be abroad, so a supply reaching the last row is genuinely domestic. Putting `domestic` first would catch a domestic customer taking delivery abroad — and that is an export.

**Digital services to consumers sit above the general B2C rule, and below the general B2B rule.** A consumer download is taxed where the consumer is (Art. 58). A business download is reverse-charged like any other service (Art. 44), which is why rule 70 is restricted to consumers and rule 80 covers both service types.

### The general B2C services rule reads backwards and is correct

A service supplied to a consumer is taxed where the **supplier** is established (Art. 45), so a Saudi firm advising a French individual charges Saudi VAT. Only named exceptions move it: digital services (rule 70), and — not yet modelled — services connected with immovable property, passenger transport and admissions to events.

Those three are absent as **rows**, not as capability. Each would be a `SupplyType` case and a rule with its article. The gap is written down here because an engine that silently treated a hotel night as an ordinary service would place it in the wrong country, and a gap nobody recorded is a gap nobody closes.

---

## Blocs

`gcc` and `eu`, each with the instrument its membership comes from and each member validated against the vendored ISO 3166-1 register in [`database/iso/countries.tsv`](../../database/iso/countries.tsv).

Validated against the register rather than a regex, because `ZZ` matches `/^[A-Z]{2}$/` and is not a country — and because **EU VAT identifiers are not ISO 3166-1 codes.** VAT systems use `EL` for Greece and `XI` for Northern Ireland. Both are exactly what somebody copying from a VAT manual would write, and a member that no country record can equal is a member that silently never matches. The bloc would then answer "export" for a supply that is intra-bloc.

### A departure is kept, with its date

The United Kingdom left the EU on 2020-12-31 (end of the transition period). It is recorded as a departure rather than deleted from the list.

Deleting it answers "export" correctly today and cannot answer anything about a supply made in 2019 — and a credit note against a 2019 invoice has to be treated the way the original was. The engine does not use the date yet; PF-087 (post-filing adjustments) is where it starts to.

The checks assert the dated departure, and the rule book refuses a country listed as both a member and a departure, because which of the two is right decides whether a supply there is intra-bloc or an export.

### GCC implementation status is not modelled

Being party to the Unified Agreement is not the same as having implemented it, and the Agreement's intra-GCC provisions operate only between states that have. That is not a membership question — it is a transitional one whose answer differs per pair of states and per date.

It is left out deliberately. Treating an unimplemented state as a non-member would make an intra-GCC supply look like a zero-rated export, which is the direction of error that costs a business money rather than one that shows up as an overpayment. The rows place the supply in the destination state, and PF-080's evidence requirement is what stops a zero rate being claimed without proof.

---

## The fallback refuses

There is no default jurisdiction. A supply no rule covers raises `PlaceOfSupplyUndeterminable`, and the message says what the engine would have needed.

Every alternative fallback issues a document that is wrong:

- **the supplier's country** overcharges a customer who will not pay it and understates a liability abroad
- **zero-rating** is the error an authority assesses years later, with penalties
- **the customer's country** invents a registration the business may not have

A document that cannot be placed cannot be issued, which is an argument the business has with its own data instead of with a tax authority.

### A missing delivery location refuses too, and that is the subtler half

A rule that tests `delivery` cannot be evaluated without one. The engine does **not** continue to the next rule.

Continuing would let a lower-precedence rule answer a question that a higher-precedence rule had a claim on, and the answer would look perfectly ordinary — a country, a rule id, a legal source. Nothing in it would say that the rule which should have decided was never evaluated. The measured failure, with the refusal removed: a supply of goods with no delivery location resolved to the supplier's country by `domestic`.

So `RuleOutcome` has three cases rather than two. "Could not tell" is not "no".

**Defaulting the delivery location to the customer's address is correct most of the time**, which is what makes it dangerous. `SupplyContext` will not do it: the caller chooses `deliveredTo()` or `withNoDeliveryLocation()`, and the names read like the decision they are. Phase 05 defaults it on the document, where the value is stored, shown and auditable.

---

## The decision carries its reasoning

`PlaceOfSupplyDecision` holds the country, the tax category, the winning rule's id and precedence, its legal source, and the supply it was decided from. It is `JsonSerializable` so the whole decision can be frozen onto a document rather than recomputed from party records that will have changed by the time anybody looks.

The category is what reaches the invoice line, and it is the part a check must assert. `AE` moves the liability to the customer; `K` reports an intra-Community supply; `Z` demands export evidence. A check that asserted only the country would accept a reverse charge as an ordinary domestic sale whenever the two happen to agree.

PF-078's last criterion — the decision and its reasoning visible on the invoice's tax panel — waits on Phase 05, because there is no invoice yet. `explain()` returns the sentence that panel will show, in Arabic and English.

---

## Checks

`php bin/verify tax` — eight rules in `tests/Support/PlaceOfSupplyRules.php`:

- every shipped case resolves to the rule **and category** it should, from a table written out from the articles rather than read from the rule book
- a supply nothing covers refuses rather than guessing
- goods with no delivery location refuse rather than falling through
- precedence decides, and reversing the file's order changes nothing
- the decision carries the winning rule, its source and both explanations, and survives serialisation
- the rule book refuses all fifteen mistakes a configuration file allows
- bloc membership is ISO 3166-1, counts are asserted, and GB's departure is dated
- a country no register knows is refused at the party rather than surfacing as "no rule matched"

Each was proven to fail when the behaviour it checks is removed. One of those proofs found a defect in `bin/verify` itself: a suite that threw while being built aborted the whole run before anything printed, so 23 other checks went quiet. A thrown exception is now a failed check.

---

## See also

- [`docs/domain/tax-engine.md`](tax-engine.md) — categories, codes and rate versions
- [`config/place_of_supply.php`](../../config/place_of_supply.php) — the rules, with their sources
- [`docs/architecture/standards-registries.md`](../architecture/standards-registries.md) — the vendored ISO registers
