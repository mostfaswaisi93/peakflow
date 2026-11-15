# Localization

> `App\Support\Lang` · `lang/*.json` · PF-016 · `bin/verify lang`

PeakFlow is Arabic-first. That is a product decision, and it has a technical
consequence most frameworks do not handle: **Arabic needs six plural forms, and
the rule is on the last two digits.**

## Laravel's pluraliser cannot express Arabic

`trans_choice()` takes a pipe-separated line with optional ranges:

```
{0} لا قيود|[1,1] قيد واحد|[2,2] قيدان|[3,10] قيود|[11,*] قيدًا
```

That looks like it covers Arabic. It does not, and no range expression can,
because the CLDR rule for Arabic is:

| Category | Rule | Example |
|----------|------|---------|
| `zero` | `n = 0` | لا قيود |
| `one` | `n = 1` | قيد واحد |
| `two` | `n = 2` | قيدان |
| `few` | `n % 100 = 3..10` | 3 قيود, **103 قيود**, 1003 قيود |
| `many` | `n % 100 = 11..99` | 11 قيدًا, **111 قيدًا** |
| `other` | everything else | **100 قيد**, 101 قيد, 200 قيد |

`n % 100` is the part a range cannot say. **103 counts the way 3 does** and
nothing about 103 looks like 3.

So the mechanism is ICU MessageFormat, which ships with PHP, and `trans_choice`
is forbidden by a check rather than discouraged in a document — because the
failure it produces is a sentence that reads as a translation error to the only
people who can tell.

## What this found in the existing code

`ArabicCount` offered **four** forms and selected between them with
hand-written ranges on the raw count. Measured:

```
ArabicCount::entries(103)  ->  103 قيدًا     CLDR says few:   103 قيود
ArabicCount::entries(100)  ->  100 قيدًا     CLDR says other: 100 قيد
```

Both wrong, and both are the large numbers an accountant actually sees. A
statement line reading `103 قيدًا` lands on a native reader the way "103 entry"
lands in English, and the first thought is that the software was translated
badly — a reasonable conclusion, and the wrong one to draw about the figures
beside it.

The cause was owning the wrong half of the problem. The **category** is a fact
about the locale, CLDR defines it, ICU implements it. The **word forms** are a
fact about the noun and only the caller knows them. Hand-written ranges tried to
own both and got the solved half wrong.

So `ArabicCount::category()` asks ICU and there is no range arithmetic left in
it, and `of()` takes all six forms — because 11 takes the accusative singular
(`قيدًا`) and 100 takes the genitive (`قيد`), which is a different word, and
conflating them was how the helper was wrong for every number from 100 up.

`ImportReport::summary()` was two `sprintf` templates behind
`$locale === 'ar'`, and the English branch was the embarrassing one:

```
Ready: 1 rows in 1 entries, all balanced.
```

English got no pluralisation at all, because the branch that handled it was the
Arabic one. A locale branch in the code means every sentence is written twice
and only the locale the author speaks gets checked. It is now one ICU message
per locale in the catalog, and neither form count appears in the code.

## `Message`, and what it refuses

`App\Support\Lang\Message::of($key, $arguments, $locale)` resolves a catalog
line **without** Laravel's `:placeholder` replacement — running `:`
substitution first would corrupt ICU braces it does not understand — and
formats it through `MessageFormatter`.

It throws rather than degrading. The tempting fallback for an unformattable
message is to show the raw line, and the raw line is
`{count, plural, zero{...` — which a user reads as the software being broken,
next to a figure they were about to act on. A 500 is fixed in minutes; a
nonsense sentence on an invoice is fixed when somebody complains.

**A missing argument is refused before formatting.** ICU does not treat one as
an error: measured, a six-way plural formatted with no `count` returned the
string `{count}`, so the page rendered, the sentence was nonsense, and nothing
logged it. That is precisely the failure this class claims to prevent, and it
got past the first version of it.

### The argument scanner is a parser, because a regex cannot do this

The first version matched `/\{\s*(\w+)\s*[,}]/` and a real message broke it
immediately:

```
{bad, plural, one{has} other{have}}
```

`{has}` and `{have}` are plural **branch bodies** and are syntactically
identical to the placeholder `{name}`. Nothing local to the brace distinguishes
them — only the fact that they sit in a selector's branch list — so the scanner
demanded arguments called `has` and `have` and the message could not render at
all.

So it walks the pattern: a node is `{ident}` or `{ident, type, …}`; a plural,
select or selectordinal node's content is a list of `keyword {body}` pairs, and
each body is recursed into as a message in its own right. That finds the
arguments that matter — the ones *inside* a branch, because only one branch
renders at a time and a branch is exactly where an argument gets forgotten.

## A translated label is never concatenated with a value

This is the rule PF-016 asks for, and it is enforced by a check.

Arabic adjectives agree with their noun in gender:

```php
__('حساب')   . ' جديد'    →  حساب جديد      correct — حساب is masculine
__('فاتورة') . ' جديد'    →  فاتورة جديد    WRONG  — فاتورة is feminine
```

The second needs `جديدة`, and **nothing at the concatenation site knows which
noun arrived**. The same applies to `sprintf(__('%s جديد'), $x)`: the translator
controls where the value goes and still not how the words around it agree with
it, because the adjective is in the format string and the noun is not.

The answer is one message per sentence, with the whole sentence in the catalog,
so the translator writes every form:

```
{kind, select, account{حساب جديد} invoice{فاتورة جديدة} other{…}}
```

There were **no violations** when the rule was written, which is the point of
writing it: the first one would otherwise have been found by a reader.

The rule tokenises rather than greps — a `__()` inside a docblock explaining the
rule would otherwise flag the explanation of itself.

### What is *not* forbidden

A locale ternary picking between two **labels** — `$locale === 'ar' ? 'مسودة' :
'draft'` in an enum, or `name_ar`/`name_en` on a model. There is no
pluralisation and no agreement involved: it is one word, the enum or the row is
the authority on it, and a catalog key would add indirection without adding
correctness. Dozens of those exist deliberately.

## ICU messages are keyed by name, not by their own text

The catalogs are Arabic-source-as-key: `"حساب": "account"`. ICU messages are the
one departure, with dotted keys like `import.summary.ready`, because an ICU
pattern is not a sentence a reader recognises and a 200-character key full of
braces is a lookup nobody can type.

Checks hold the catalogs to the consequences:

- the same ICU keys in every served locale, or one locale gets a pluralised
  sentence and the other gets the key
- every message renders at **every category boundary** — 0, 1, 2, 3, 11, 100,
  103, 111 — because a message missing its `few` branch renders correctly for 1
  and 2 and falls through at 3, which is a grammatical error that only appears
  on a particular day
- every plural node declares every category the locale has, and `other`
  specifically, which ICU fails at render time without
- no branch for a category the locale does not have: a `few` branch in English
  is a translation nobody will ever select, and it reads as coverage

That third rule is checked **per plural node**, not per message, and the first
version was not. A sentence with two plurals — `{rows, plural, …} في {entries,
plural, …}` — satisfied a `str_contains($pattern, 'few{')` test from either one.
Measured: deleting the `few` branch from the first plural of exactly that
sentence left the check passing.

## Digits travel with the locale

An ICU message containing `#` or `{n, number}` renders the digits of whatever
locale it is given, and a bare `ar` renders Arabic-Indic. So `Message` routes
the locale through `Support\Lang\NumberingSystem`, the same single place money
and quantities use — a count beside a total cannot be written in different
digits. See [`standards-registries.md`](standards-registries.md) for why `latn`
has to be written out explicitly.

## How this is checked

`bin/verify lang` — eight rules, eleven bites:

| Bite | Fired |
|------|-------|
| The four-form range selector restored | The six-forms rule, at 100 and 101 |
| The category computed by hand instead of asked of ICU | The same, plus the ICU-agreement rule |
| Arguments matched by regex instead of parsed | The parser rule, on `{bad, plural, one{has} other{have}}` |
| The missing-argument guard removed | The refusal rule, on two of five cases |
| The `few` branch deleted from the first plural of a two-plural sentence | The per-node rule — *after* it was fixed from per-message |
| A `trans_choice` call added | The pipe-syntax rule |
| A translated string concatenated with a value | The concatenation rule |
| A translated string used as a `sprintf` format | The same rule, by its other arm |
| The `other` branch deleted | The render rule, with ICU's own error |
| An English message using `few` | The category rule |
| An ICU key present in one catalog only | The agreement rule |
