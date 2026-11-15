# Money, precision and rounding

This document is first among the architecture documents because every other number in the system inherits whatever it gets wrong.

## Three representations that are all wrong, and the one that is used

### Floats are disqualified outright

IEEE 754 binary floating point cannot represent `0.1`. The consequences are not theoretical:

```php
0.1 + 0.2 === 0.3;              // false
(0.1 + 0.7) * 10;               // 7.999999999999999
round(2.675, 2);                // 2.67, not 2.68
```

Summing a ledger of 400,000 lines accumulates a drift that is small, **non-deterministic across platforms**, and impossible to explain to an auditor. There is no float anywhere in PeakFlow's money path - not in a column, not in an Eloquent cast, not in a JSON response - and an architecture test enforces it.

### Minor-unit integers are nearly right, and rejected

Storing 1,234.56 as the integer `123456` is the usual next answer. It is exact and it is fast. It is rejected for three concrete reasons:

**1. The exponent is not 2.** ISO 4217 assigns each currency a minor unit, and it is not always two:

| Exponent | Currencies | A naive 2-decimal assumption gives |
|----------|------------|-------------------------------------|
| **3** | `BHD`, `JOD`, `KWD`, `OMR`, `TND`, `LYD`, `IQD` | A **1000×** error. `JOD 1.234` stored as `123` reads back as `1.23` |
| **0** | `JPY`, `KRW`, `VND`, `CLP`, `ISK`, `XOF`, `XAF` | A **100×** error, and a fictional decimal part |
| **2** | Most | Correct |

An integer column is therefore meaningless without its currency, and every single read has to divide by a per-currency power of ten. One forgotten division is a thousandfold error in a Jordanian dinar amount, **and it will look plausible** - `1.23` where `1,234.000` belongs is a number an eye slides over. Since `JOD` is a target market currency, this is not an edge case.

**2. Two currencies have no decimal subunit at all.** `MGA` (1 ariary = 5 iraimbilanja) and `MRU` (1 ouguiya = 5 khoums) are base-5. No power of ten represents them.

**3. Unit prices need more precision than amounts.** A price of `0.0725` per kWh, per minute or per litre is ordinary in utilities, telecoms and fuel. Minor units force one scale for both price and total, and either the price or the total loses.

### What is used: PostgreSQL `numeric`, and `brick/money`

`numeric` is arbitrary-precision exact decimal. `SUM()` over it is exact. PostgreSQL will not coerce it to a float. In PHP, every operation goes through [`brick/money`](https://github.com/brick/money) with an **explicitly named rounding context** - there is no default parameter, which is precisely why the library was chosen over a hand-rolled helper.

The cost is honest: `numeric` arithmetic is slower than `bigint`. The benchmark that matters is a trial balance over ten million lines, and the answer there is a covering index on `(entity_id, period_id, account_id)` plus partition pruning - not a faster wrong type. See [`ADR-0004`](decisions/ADR-0004-money-representation.md).

> **A Laravel `decimal` cast returns a string.** `'balance' => 'decimal:4'` gives `"1234.5600"`. That is correct and deliberate: the moment a money value becomes a PHP `float` it is already wrong. Do not cast to `float` to make the arithmetic easier. Use `Money::of($string, $currency)`.

## The three scales

Exactly three exist, and an architecture test fails a migration that introduces a fourth.

| Scale | Type | Used for | Rationale |
|-------|------|----------|-----------|
| **Amount** | `numeric(21,4)` | Journal lines, document totals, tax subtotals, payments, balances | Four decimals absorb the three-decimal currencies plus one guard digit; presentation rounds to the currency's own exponent |
| **Unit price** | `numeric(21,8)` | Item and line unit prices, per-unit tax | Covers per-litre, per-kWh, per-minute and per-thousand pricing with no scaling factor |
| **Rate** | `numeric(21,12)` | FX rates, tax percentages, allocation ratios | Enough that a round trip through an inverse rate does not drift |

`numeric(21,4)` holds 17 integer digits - about 10 quadrillion - which is past any real general ledger and well inside `numeric`'s own limits.

## Where rounding happens, exactly once

Rounding twice is how a document's lines stop adding up to its total. **A value is rounded when it becomes a legal amount, and never again.**

```
  unit_price (8dp)  ×  quantity (6dp)
          │
          └─ round HALF_UP → 4dp ──────────────►  line net            ① rounded once
  
  line net  ×  tax_rate (12dp)
          │
          ├─ accumulate UNROUNDED per (tax category, rate)
          └─ round HALF_UP → 4dp ──────────────►  tax subtotal/group  ② rounded once
  
  Σ line nets ────────────────────────────────►  document net        ③ sum of ①, not re-rounded
  Σ tax subtotals ───────────────────────────►  document tax        ④ sum of ②, not re-rounded
  ③ + ④ ──────────────────────────────────────►  document gross
          │
          └─ round to the currency's exponent ─►  displayed / payable  ⑤ a view, never stored as a second total
```

### Why half-up rather than banker's rounding

EN 16931 and most VAT regimes specify **half away from zero** (half-up). IEEE 754 and many language defaults use half-even ("banker's"). The difference appears on roughly one document in a hundred and compounds across a return.

`brick/money` requires the mode explicitly. The policy is resolved **per jurisdiction**, as data, not as a constant - so a jurisdiction that differs is a row.

### Why tax is computed per rate group

EN 16931 requires a VAT breakdown (`BG-23`) with one entry per unique combination of tax category and rate, and rule `BR-CO-14` states the document's tax total equals the sum of those entries. Rounding each line's tax and summing gives a different - and in most jurisdictions, wrong - answer.

#### Worked example: why per-line rounding fails

Three lines at 15%, amounts chosen to sit on rounding boundaries:

| Line | Net | Tax unrounded | Tax if rounded per line (2dp) |
|------|-----|---------------|-------------------------------|
| 1 | 10.03 | 1.5045 | 1.50 |
| 2 | 10.03 | 1.5045 | 1.50 |
| 3 | 10.03 | 1.5045 | 1.50 |
| | **30.09** | **4.5135** | **4.50** |

- **Per rate group** (correct): accumulate `4.5135`, round once → **`4.51`**
- **Per line then sum** (wrong): `1.50 + 1.50 + 1.50` → **`4.50`**

One cent, on one document. Across 50,000 documents in a quarter it is a reconciliation difference between the ledger and the filed return that nobody can locate, because each individual document looks correct.

#### Worked example: a three-decimal currency

A `JOD` invoice, one line, quantity 3, unit price `12.3456789` (8dp), tax 16%:

```
line net unrounded  = 3 × 12.3456789        = 37.0370367
line net (4dp)      = round HALF_UP          = 37.0370
tax unrounded       = 37.0370 × 0.16         = 5.925920
tax subtotal (4dp)  = round HALF_UP          = 5.9259
document net        = 37.0370
document tax        = 5.9259
document gross      = 42.9629
displayed (JOD, 3dp)= round HALF_UP          = 42.963   ← presentation only
```

The stored gross stays `42.9629`. **Storing `42.963` as the gross is how a ledger stops balancing**, because the receivable and the revenue plus tax would then differ by `0.0001` per document.

#### Worked example: a zero-exponent currency

A `JPY` invoice, net `11,430`, tax 10%:

```
tax unrounded       = 1,143.0
tax subtotal (4dp)  = 1143.0000
document gross      = 12573.0000
displayed (JPY, 0dp)= ¥12,573
payable             = ¥12,573
```

`JPY` has no minor unit, so the presentation exponent is zero and the payable amount is an integer. The stored value still carries four decimals, and the API returns `"12573.0000"` with `"currency": "JPY"` - the client formats, the server does not pre-format.

## Three consequences that are easy to get wrong

**1. The displayed total is a view, not a stored second total.** There is exactly one gross amount per document, at the amount scale. The presentation exponent is applied at render and at payment time.

**2. A payment rounding difference is posted, not absorbed.** When a customer pays `999.99` against `1,000.00` and policy says settle it, the `0.01` becomes its own journal line against a configured `rounding_difference` account. It does not quietly shrink the receivable. The tolerance is a per-entity setting with a **platform-enforced ceiling** - "write off anything under 50" is a policy decision, not a developer's constant.

**3. An apportionment residual is assigned deterministically.** A document-level 7% discount across three rate groups will not divide evenly. The residual goes to the **largest** weight, always, by rule - never left to floating-point luck.

Shares are computed **toward zero**, so the residual is explicit and carries the amount's own sign rather than appearing as an overshoot. Where several weights are equally the largest, the residual goes to the **first** of them: three equal groups put it on group one, every time.

```
100.00 across weights 1 : 1 : 1   →  33.3334 / 33.3333 / 33.3333   (sums to 100.0000)
100.00 across weights 70 : 20 : 10 →  70.0000 / 20.0000 / 10.0000
-100.00 across weights 1 : 1 : 1  → -33.3334 / -33.3333 / -33.3333  (sums to -100.0000)
```

That tie-break is arbitrary, and it is **fixed**, which is the only property that matters: a document that recomputes to a different total is worse than one that is a cent off. It is stated here because "the largest group" is ambiguous when groups are equal, and an ambiguity in a rounding rule becomes two implementations.

## FX, and why rates are never inverted

Rates are stored as `(from, to, date, rate, provider, rate_set)` with the **direction stored, never inferred**. If both `USD→EUR` and `EUR→USD` are used, both are stored.

```
stored:      USD→EUR = 0.920000000000
computed:    EUR→USD = 1 / 0.92 = 1.086956521739...
round-trip:  1,000 USD → 920.00 EUR → 1,000.0000000004 USD
```

The drift is tiny and it is *persistent*: it appears in every consolidation, in the same direction, and someone will eventually ask about it. Storing both directions costs one row.

Three rate sets coexist, because they genuinely differ and tax authorities frequently mandate their own: a **daily market** rate for transactions, a **monthly average** for profit-or-loss translation, and a **statutory** rate for tax purposes.

| Event | Rate | Posting |
|-------|------|---------|
| Invoice issued in foreign currency | Spot on the **tax point date** | Receivable converted at that rate |
| Payment received | Spot on the **value date** | Difference is a **realized** FX gain or loss |
| Period end, open monetary balance | **Closing** rate | **Unrealized** revaluation, reversed and recomputed each period rather than accumulated |
| Consolidation | Closing for the balance sheet, average for profit or loss | Translation difference to **other comprehensive income**, not to profit or loss |

## The invariant that balances in one currency only

This is the subtlety that breaks naive multi-currency ledgers.

Each journal line stores the transaction-currency amount, the rate, and the functional-currency amount. **The zero-sum invariant applies to the functional amount only.**

A single entry recording a `USD 1,000` receipt against a `EUR 920` invoice, in an entity whose functional currency is `SAR`:

| Account | Txn amount | Txn ccy | Rate → SAR | Functional (SAR) |
|---------|-----------:|---------|-----------:|-----------------:|
| Bank | 1,000.0000 | USD | 3.7500 | 3,750.0000 |
| Accounts receivable | (920.0000) | EUR | 4.0500 | (3,726.0000) |
| FX gain | — | — | — | (24.0000) |
| | | | | **0.0000** ✓ |

Summed in `USD` it is not zero. Summed in `EUR` it is not zero. Both of those are **correct**. Asserting the invariant on the transaction currency is a bug that only appears once a customer pays in a foreign currency - which is to say, in production.

### What enforces it, and what happens without it

Three claims are checked on every journal line by `assert_line_translation()`, a trigger rather than a service method - because an import, a correction script and a console session all reach `journal_lines` directly and none of them passes through the application:

| Claim | Refused form |
|-------|--------------|
| A foreign-currency line carries a rate | `EUR 4,000`, `fx_rate = NULL`, `functional_amount = 4,000.0000` |
| Its functional amount follows from that rate | `EUR 4,000` at `4.0125` recorded as `SAR 4,000` |
| A functional-currency line carries **no** rate | `SAR 1,000` with `fx_rate = 1.000000000000` |

The third looks pedantic and is not. A rate of `1` asserts that a translation happened and came out even, which is a different claim from no translation at all, and afterwards the two cannot be told apart - while "was this converted?" is asked every time a balance is read in two currencies.

The first was measured with the trigger dropped. A **balanced** pair of untranslated `EUR 4,000` lines was accepted, and the trial balance then reported the account holding `SAR 4,000` where `SAR 16,050` belonged. `SAR 12,050` gone, the entry balancing perfectly, and every total in the system agreeing with every other one.

The mistake is never single-sided, which is why the check writes a balanced pair: a rule that forgets to translate forgets on every line, so the balance constraint sees nothing wrong.

The tolerance on the second claim is **one unit at the amount scale** - enough to absorb a half-up versus half-even disagreement and nothing else. Deliberately not a percentage: a tolerance that scales with the amount is a tolerance that hides a wrong rate on a large invoice.

A missing rate is `RateNotAvailable`, and a rate that exists only in the opposite direction is a *different* refusal, because that is the moment someone writes `1 / $rate` by hand. An architecture rule fails the build on the obvious spellings of that - including `BigDecimal::one()->dividedBy($rate, …)`, which avoids the float and does the same wrong arithmetic, and `$amount->dividedBy($rate, …)`, which is the inverse with no `1` in it at all.

### When the feeds are down

A rate feed outage **must not stop a business trading**. An invoice that cannot be issued because a third party's API is down is a worse outcome than an invoice issued at yesterday's rate, because the second one is correctable and the first one is a customer not being billed.

So `fx:fetch-rates` walks providers in precedence order, and when they are exhausted:

| Situation | What happens |
|-----------|--------------|
| A feed fails | The next provider is tried. The failure is counted against that feed and recorded on the run. |
| No feed has the pair | The last known rate stays in force; the run is **degraded** and raises an alert. |
| Nothing on file, or nothing inside the fallback window | The pair is **unresolved**; documents in it are refused, and the run is **failed**. |

Two things it deliberately does **not** do.

It does not copy the old rate forward under today's date. That would assert the feed published it today, and would make the rate look current on every document that used it - which defeats the staleness flag entirely.

It does not fall back indefinitely. Past `fx.fallback_window_days` the pair is unresolved, because "the last known rate" from eleven months ago is not resilience, it is a wrong number with a long paper trail.

The exit code is non-zero on a degraded run. That is an alert on the **job**, not a block on trading: nothing in the fetcher has any authority over whether a document can be issued.

The alert is the part that is easy to drop, and the reason it is not: a month of invoices at a three-week-old rate produces a difference that is found at the close, is material, and is explained by nobody - while every individual invoice looked fine at the time. So a degraded run writes the provider attempts onto `fx_rate_fetch_runs`, writes an append-only audit entry beside the documents it explains, logs a warning, and exits non-zero.

## Reading a number somebody typed

`format()` and `parse()` are one pair, not two features. A form renders an amount with `format()` and takes it back from a text field, so whatever `format()` produced has to read back as the same value - in the locale it was rendered in, with the digits, separators and invisible marks that locale puts there.

That is the property `bin/verify examples` asserts: generated amounts crossed with eight currencies and two locales, the seed printed on failure so a red run is reproducible rather than folklore. The set is chosen for the cases that break the naive implementation - `JOD` and `BHD` at three decimals, `JPY` at none, negatives, and `ar` with Arabic-Indic digits, where the formatted string carries `U+200F`, `U+061C` and a narrow no-break space that nobody would type by hand but every Arabic form will hand back.

**The round trip is of the displayed value, not the stored one.** `format()` renders the currency's exponent, so 1234.5678 SAR displays as `1,234.57` and reads back as 1234.5700. Asserting the stored value round-trips would make the property false for most amounts in a ledger that stores four places - and the honest statement is the useful one: what the user sees is what the user can type back.

### Two guards, both load-bearing, both found by measurement

**ICU has to consume the whole string.** `NumberFormatter::parse()` parses as far as it can and tells you where it stopped; it does not refuse a string it only partly understood. Measured: `about 12k` read back as 12, and `١٢٬٥٠٠٫٥٠` under an `en` formatter read back as 12500. The second is a wrong number with a plausible shape. The first is worse, because twelve riyals posts, balances, and reconciles against nothing. So the parse requires full consumption - after stripping only the directional marks and non-breaking spaces ICU itself emits - and refuses anything with a remainder.

**The fallback whitelists characters rather than stripping the rest.** The first version removed everything that was not a digit or a dot, which turns `about 12k` into 12 by a different route. The pass now permits only the separators people and ICU actually produce - comma, apostrophe, the spaces, the directional marks, the sign characters - and what remains must match a plain decimal or the whole value is refused. A figure the software invented is a figure nobody can trace.

Both guards were re-broken deliberately and each one failed a check on its own. A guard whose removal changes nothing is documentation, not a control.

### One implementation

`ManualJournal` had its own normaliser for the same job, which is how a codebase ends up with two answers to "is `12k` a number" - and the second copy was the one that stripped letters. It now calls `Money::normaliseTypedAmount()`, which parses or refuses, and returns a reason when it refuses so the form can say which line it could not read.

### Rounding resolves per jurisdiction, from configuration

`RoundingPolicy::forProfile()` reads `config('jurisdictions.rounding')`, so a new country is a profile entry plus fixtures and never a change to the money layer. A profile that declares no mode falls back to the platform default instead of throwing: refusing to round because a configuration file is incomplete would make a jurisdiction unusable until somebody noticed, and the noticing would happen on an invoice.

## How this is tested

| Layer | Asserts |
|-------|---------|
| Unit | Each scale's boundary behaviour; half-up versus half-even producing specified different answers |
| Unit, per currency | Exponent 0, 2 and 3, plus `MGA`/`MRU`, each with a named test |
| Property-based | `format` then `parse` round-trips the **displayed** value for any amount, currency and locale, seed printed on failure; recomputing a document twice is identical; any balanced line set still balances after a storage round trip |
| Golden file | Jurisdiction rounding fixtures with expected output **to the cent**, from official corpora where one exists |
| Mutation | A minimum score gate on `Support\Money`, `Tax` and `Settlement`. A money suite that still passes against mutated arithmetic is not testing the arithmetic |
| Architecture | No `float` declaration, cast or coercion in the money path; no fourth scale |
| Feature | `ledger:verify` at the end of every test that posts - the global invariant an individual assertion cannot fake |
