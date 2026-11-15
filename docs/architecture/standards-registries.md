# The standards registries

> `database/iso/` · `App\Support\Iso` · PF-007, PF-008 · `bin/verify iso`

Four files hold the standards data the whole product depends on: ISO 4217
currencies, ISO 3166-1 countries, UN/CEFACT Rec 20 units, and the locales
PeakFlow actually serves. They are data files with a provenance record, read by
classes that do nothing but read them.

## Why they are files

They were arrays in classes, packed into string constants, and the packing was
the problem. A 174-entry currency table squeezed onto thirty lines is a table
nobody reviews:

```
'JPY:392:0|KES:404|KGS:417|KHR:116|KMF:174:0|KPW:408|KRW:410:0|KWD:414:3|KYD:136|KZT:398|'.
```

`KWD:414:3` losing its `:3` in a merge is one character. The consequence is
that every Kuwaiti invoice rounds to two decimal places instead of three - a
1000x error on a figure that still looks like money, still balances against
itself, and is caught by nothing, because two decimals is what money normally
has.

As a file with one row per code and a header above it, that change is a line in
a diff that says what the column means. And the data being separable from the
code is what makes a provenance record possible at all.

## What the provenance record honestly claims

`database/iso/provenance.json` does **not** say "downloaded from ISO and
verified against the publisher's checksum". This environment cannot reach ISO,
SWIFT or UNECE, and nothing in these files was fetched.

Saying so is the point. A provenance record that implies a verification which
did not happen converts an unknown into a false assurance, and a false
assurance is never re-examined. An explicit caveat is re-examined the first
time somebody needs the data to be right.

Each entry therefore states four separate things:

| Field | What it means |
|-------|---------------|
| `obtained` | How the file got here. For three of the four: *transcribed from the published list; not downloaded in this environment* |
| `cross_checked` | What second source confirmed it **inside this process** |
| `unverified` | What nothing confirmed. The field is required, and `bin/verify iso` fails if it is missing |
| `sha256` | The digest of the file as reviewed, so an unrecorded edit is caught |

### The second source, where there is one

ICU ships with PHP and carries CLDR's own copy of ISO 4217 and ISO 3166-1. That
makes it a real independent check, and it was run: every currency code and every
country alpha-2 in these files is one ICU 74.2 also knows, with zero unknowns.
`bin/verify iso` re-runs both on every pass, so a transcription error introduced
later is caught by something other than a reviewer's eye.

What ICU cannot confirm: the alpha-3 and numeric country columns (intl exposes
no mapping for either), and **Rec 20 entirely**. The units file is the weakest
provenance in the directory and its entry says so. That caveat has teeth,
because an invoice's unit code is validated against the real Rec 20 list by the
*receiving* system - so a wrong code here is rejected there, by a customer's
software, with a schema error a salesperson cannot act on.

One code was removed rather than shipped on a guess: `KTM`, labelled "kilometre
per hour". A speed is not a chargeable quantity - an invoice line multiplies a
quantity by a unit price, and 3 km/h × 50 SAR is not money - and the label could
not be confirmed against the published list from in here. A check asserts it
stays out.

## The divergence that matters: CLDR is not ISO 4217

ICU's currency fraction digits come from CLDR, and CLDR **deliberately** differs
from ISO 4217 where inflation has retired a subunit. Measured across the whole
table, they disagree 21 times:

| Code | ISO 4217 | CLDR | |
|------|---------:|-----:|---|
| `IQD` | 3 | 0 | Nobody prices in Iraqi fils any more |
| `AFN`, `ALL`, `IRR`, `KPW`, `LAK`, `LBP`, `MMK`, `RSD`, `SOS`, `SYP`, `YER` | 2 | 0 | Same reason |
| `MGA` | 2 | 0 | Base-5 subunit, so no exponent describes it anyway |
| `XDR`, `XSU`, `XUA`, `XAU`, `XAG`, `XPT`, `XPD`, `XXX` | 0 | 2 | Units of account and metals, which CLDR formats like money |

Neither side is wrong. CLDR answers "how is this currency written in practice";
ISO 4217 answers "what is its legal minor unit". **PeakFlow answers ISO 4217's
question**, because the systems receiving its documents do: EN 16931 and
ISO 20022 validate the minor unit against ISO 4217, so an IQD amount rendered to
CLDR's zero decimals is rejected by a customer's software rather than by ours.

So `Money::format()` sets the fraction digits explicitly from the registry
rather than letting ICU choose, and the 21 divergences are enumerated and frozen
in the check suite. A new one fails the build - which is a prompt to look, not a
verdict. An ICU upgrade is the likeliest cause, and the question it raises is
whether a currency's subunit has actually been retired.

The consequence of losing that explicit setting was measured by removing it:

```
IQD 1234.5678   with the guard: IQD 1,234.568   without: IQD 1,235
AFN 1234.5678   with the guard: AFN 1,234.57    without: AFN 1,235
SAR 1234.50     with the guard: 1,234.50        without: 1,234.5
```

`JOD` stayed correct in that run, which is the trap: CLDR agrees with ISO about
JOD, so a spot check on the obvious three-decimal currency proves nothing. Only
the divergent codes break, and the check asserts two of them by name.

## `latn` has to be written out

The display digit shape is configured in `config/money.php`, defaulting to
`latn`, because financial documents across the region conventionally use Western
digits even in Arabic text. The implementation skipped appending the subtag when
the answer was `latn` - an obvious simplification, since `latn` is "the normal
digits".

It is wrong for every locale whose CLDR default is something else. Measured:

```
NumberFormatter('ar')            → ١٬٢٣٤٫٥٦
NumberFormatter('ar-u-nu-latn')  → 1,234.56
```

So a configuration that said `latn` and a formatter handed a bare `ar` produced
Arabic-Indic digits on an invoice that asked for Western ones. The subtag is now
always written out: an explicit no-op is the difference between a setting that
works and a setting that appears to.

### One place builds it

`App\Support\Lang\NumberingSystem` is the only thing that appends `-u-nu-`, and
a check enforces that by tokenising every file under `app/` rather than grepping
it. The first version of that check matched the literal `'-u-nu-'` and missed
the form a second site would actually take - proved by writing `'-u-nu-latn'`
into another class and watching the rule report one site.

The reason for the rule: before it, `Money` and `CurrencyPolicy` each built the
subtag, and they disagreed about `latn`. The failure that produces is a document
showing a quantity as `١٢` beside its line total as `123.45`, on the same row.
Nobody reads that as a styling inconsistency.

Three numbering systems exist and two of them are routinely treated as one:

```
latn     0123456789   Latin
arab     ٠١٢٣٤٥٦٧٨٩   Arabic-Indic, used across the Gulf and the Levant
arabext  ۰۱۲۳۴۵۶۷۸۹   Extended Arabic-Indic (Persian, Urdu)
```

A Saudi statement rendered in `arabext` is wrong in a way that reads as a font
problem, so nobody files it as a data bug and it is never fixed.

## The language registry is not ISO 639-1

The other three registries are their standard in full, because a currency or a
country code arrives from outside: a customer in Kiribati has to be storable
whether or not anybody expected one.

A locale is the opposite. It is a promise that the product has been translated,
and a registry of 184 language codes is a list of 182 promises the product
cannot keep - every one of them offerable in a picker that then renders English,
which is worse than not offering it at all. So `languages.tsv` holds what is
served, a check asserts each served tag has its `lang/*.json`, and a language
arrives in the same commit as its translations or not at all.

Four facts per locale, each of them data because each had been a guess
somewhere:

- **Direction.** It was a private constant inside `SetLocale`, which made the
  middleware the authority on a fact the whole view layer depends on - and a
  second place to update when a language is added.
- **Numbering system.** The locale's *own* system (`arab` for Arabic), recorded
  as the CLDR fact rather than as the rendering decision. It is what the `latn`
  display default departs from, which is why both exist and why only one of them
  reaches a formatter.
- **Endonym.** The language's name in itself. A picker that offers "Arabic" to
  somebody who does not read English has failed at the one step where it cannot
  assume a language.
- **Plural categories.** Verified against ICU, not asserted: a six-category ICU
  message is formatted across 0–220 plus fractional and large values, and the
  categories that are actually reachable are collected. Arabic reaches
  `zero, one, two, few, many, other`; English reaches `one, other`. A file
  claiming otherwise would let a translator write four forms that never render,
  or two that leave four cases grammatically wrong - silently, on a page.

### Arabic-first, and the literal that undid it

`SetLocale` says in its own docblock that `en` is the fallback and never the
default, and that Arabic-first is a product decision rather than a configuration
accident. `config/app.php` said:

```php
'locale' => env('APP_LOCALE', 'en'),
```

which is the Laravel skeleton's line. With it in place, the decision survived
only while `APP_LOCALE` was present in the environment - so a deployment that
forgot it would serve English to every Arabic user, correctly as far as the
framework is concerned, and contradicting every comment in the codebase. It now
reads:

```php
'locale' => env('APP_LOCALE') ?: \App\Support\Iso\LanguageRegistry::DEFAULT_TAG,
```

`?:` rather than a default argument, because `APP_LOCALE=` with nothing after it
is a line somebody will write, and `env()` hands back the empty string for it -
which is not a locale and does not trigger a default argument. Measured: it set
the application locale to `''`. Both cases now resolve to `ar`, and a check
reads the literal out of the file, because the resolved value cannot show this
when `.env` sets it.

## Strict rows, always

The digest check runs in `bin/verify iso`, not on every request - hashing four
files per process buys nothing, because the failure it catches is an edited file
and an edited file is caught when somebody runs the checks.

What runs on **every** load is the structural check, because that is the one
that stops a bad row becoming a plausible number. Five structural failures and
six value failures make the whole file unusable and throw:

| Refused | Because the alternative is |
|---------|----------------------------|
| A row short of a field | An exponent that defaults to 0 or 2 - and 0 is legitimate, so the row would not look wrong |
| A row with an extra field | A column shifted by one, silently, for every row after it |
| A whitespace-padded field | `' SA'` becoming a country that does not exist, with the diff that added the space invisible |
| A duplicate key | One of them winning, nothing saying which, and a reviewed change to the loser having no effect |
| A header and no rows | Every code reading as "this currency does not exist" rather than as a missing file |
| An exponent that is not a digit, a two-letter currency code, a numeric country code that lost its leading zeros, a direction that is not `ltr`/`rtl`, plural categories without `other`, a dimension the enum does not have | A value that casts to something usable, which is the worst kind of bad data: it produces a wrong document rather than an error |

Padded fields are **refused rather than trimmed**. Trimming makes the file
disagree with itself - the row looks right, the key is right, and the space is
found by nobody.

## Two columns that are PeakFlow's, not the standard's

**`subunit_divisor`** on currencies. It is `10^exponent` for 172 of the 174, and
a reader that computed it would be wrong for exactly two: one ariary is five
iraimbilanja and one ouguiya is five khoums, so `MGA` and `MRU` carry an ISO
exponent of 2 while their subunit is a fifth. No power of ten describes them,
which is one of the three reasons minor-unit integer storage was rejected
outright (ADR-0004). A check asserts the other 172 agree with `10^exponent` and
that these two do not.

**`dimension`** on units. `UnitDimension` existed as an enum whose docblock said
it was there so a comparison between incompatible units could be refused - and
nothing mapped a code to it, which made it a declaration of intent rather than a
control. Now a unit has one, is refused at load if it does not, and
`UnitRegistry::comparable()` can answer the question the enum was written for.

Three hours plus two kilograms is not a rounding problem. Where it shows up is a
credit note whose unit does not match the invoice line it credits: the
quantities subtract to a number that means nothing and still prints.

## What is deliberately not in these files

Names and symbols. ICU already knows every currency, country and language name
in every locale PeakFlow serves, and a hand-maintained Arabic list beside it
would be a worse copy of the same data, going stale in a different direction.

Whether an IBAN applies to a country is also not here: ISO 13616 answers that
and `Iban::appliesTo()` owns it, so there is one answer rather than two that can
drift.

## How this is checked

`bin/verify iso` - fourteen rules, each of which has been proved to fail when
the thing it guards is broken:

| Rule | Proved by |
|------|-----------|
| The data on disk is the data somebody recorded | Changing `JOD`'s exponent to 2 |
| Every registry says where it came from, and what was not checked | Deleting the units entry's `unverified` caveat |
| No registry carries its own data in a constant | The rule PF-007 asks for, enforced rather than described |
| Every unusual exponent is named, not derived | 36 literals in the check file, both directions |
| The two base-5 subunits are not computed from a power of ten | — |
| Every code is one ICU also knows | — |
| Where CLDR disagrees with ISO 4217, the disagreement is known | Dropping `IQD` from the frozen list |
| Money renders the ISO exponent, never CLDR's | Removing the explicit fraction digits |
| A malformed row makes the file unusable rather than the data wrong | Making the loader trim instead of refuse |
| The digit shape is built in exactly one place | Writing `'-u-nu-latn'` into a second class |
| A configured `latn` actually produces Latin digits | Skipping the subtag as a no-op |
| Every served locale is one the product is translated into | Declaring Arabic with two plural forms |
| Arabic-first survives an empty environment | Restoring the skeleton's `env('APP_LOCALE', 'en')` |
| A unit knows what it measures, and refuses the ones it does not | Adding `KTM` back |
