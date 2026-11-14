# RTL and internationalization

Arabic-first, genuinely bilingual. That is a deeper requirement than a translated string file, and most of the work is in the four sections below rather than in the catalogs.

## Locale resolution

```
user preference → tenant default → Accept-Language → `ar`
```

`SetLocale` resolves it and sets `dir` accordingly. **A document is rendered in its recipient's locale, not its author's** — which is why locale lives on the contact, not only on the user.

| Locale | Direction | Default |
|--------|-----------|---------|
| `ar` | RTL | ✅ |
| `en` | LTR | Fallback |

## ICU MessageFormat, not placeholder interpolation

Arabic has **six plural categories**: `zero`, `one`, `two`, `few`, `many`, `other`. A format that cannot express them produces wrong grammar for most numbers.

```php
// lang/ar.json
"invoices_overdue": "{count, plural,
    zero {لا توجد فواتير متأخرة}
    one {فاتورة متأخرة واحدة}
    two {فاتورتان متأخرتان}
    few {# فواتير متأخرة}
    many {# فاتورة متأخرة}
    other {# فاتورة متأخرة}}"
```

English needs two forms; Arabic needs six, and `3` and `11` take **different** forms. A `:count فاتورة` template is wrong for almost every value.

### Never concatenate a translated label with a value

```php
// ✗ Wrong. Arabic adjectives agree in gender with their noun, so this is
//   broken for every masculine noun a tenant might configure.
__('إضافة') . ' ' . $label . ' ' . __('جديدة');

// ✓ Right. The translator controls agreement because they see the whole phrase.
__('إضافة :label', ['label' => $label]);
```

This is the single most common i18n bug in an Arabic application, and it renders perfectly — it is simply wrong, in a way a non-Arabic-speaking reviewer cannot see.

## Logical CSS properties only

| ✗ Never | ✓ Always |
|---------|----------|
| `left`, `right` | `inset-inline-start`, `inset-inline-end` |
| `margin-left`, `margin-right` | `margin-inline-start`, `margin-inline-end` |
| `padding-left`, `padding-right` | `padding-inline-start`, `padding-inline-end` |
| `border-left`, `border-right` | `border-inline-start`, `border-inline-end` |
| `text-align: left \| right` | `text-align: start \| end` |
| Corner-specific `border-radius` | `border-start-start-radius`, … |
| `float: left \| right` | `float: inline-start \| inline-end` |

**A single `left:` in a component is an RTL bug waiting for a customer to find it.** A stylelint rule fails the build on any physical property, and an architecture test fails on one in a template.

### Two exceptions, both deliberate

**Numbers do not flip.** A figure column is `text-align: end` in LTR and **still right-aligned** in RTL — digits read left-to-right in both directions, and a right-aligned numeric column is correct in Arabic too. Using `start` here would left-align figures in Arabic and break the thousands-place scan.

**Directional icons mirror; non-directional ones do not.** A "next" chevron mirrors. A clock, a checkmark, a logo and a play button do not. The icon set declares which is which.

## Bidi isolation — the subtle one

An identifier or a negative amount embedded in Arabic text **reorders** unless isolated:

```
Stored:    JO94-CBJO-0010-0000-0131-0003-02
Rendered:  02-0003-0131-0000-0010-CBJO-JO94     ← wrong, and plausible
```

```html
<!-- ✓ -->
<bdi class="font-mono">JO94-CBJO-0010-0000-0131-0003-02</bdi>
```

> **The user verifying that IBAN will not notice.** It is the right characters in the wrong order, it looks like an IBAN, and it is a different string from the one they must check. The same applies to invoice numbers, reference codes, hashes and negative amounts.

Everything in this list is `<bdi>`-wrapped or `unicode-bidi: isolate`:

- IBANs, BICs, domestic account identifiers
- Invoice, credit note, bill and payment reference numbers
- Tax registration numbers
- Hashes, ULIDs, API tokens
- Negative and parenthesized amounts
- Any mixed-script string

## ICU for numbers, dates and currency

Never `number_format` or `date('Y-m-d')` for display.

| Concern | Handling |
|---------|----------|
| Digit shape | Per-tenant `latn` / `arab` via ICU. **Display only** — stored values, exports, XML and API responses are always Western digits |
| Decimal and group separators | From the locale, not hard-coded |
| Currency symbol and position | Per currency **and** per locale — `ر.س ١٢٣` and `SAR 123` are both correct in their context |
| Negative format | **Accounting style** with parentheses, locale-aware |
| Exponent | From ISO 4217 — three decimals for `JOD`, zero for `JPY` |
| Dates | Gregorian, with **Hijri display** where a tenant asks. Both calendars shown side by side on documents where a jurisdiction requires it |
| First day of week | Per tenant — Saturday, Sunday and Monday are all correct somewhere, and the fiscal calendar depends on it |
| Relative time | ICU, with Arabic's dual form |
| Sorting | Locale-aware collation, not `ORDER BY` on bytes |

**None of this is derivable from the language alone.** An Arabic-speaking user in Riyadh and one in Casablanca want different calendars, different week starts and different digit shapes. Locale is not language.

## Translation completeness is tested

A missing key does not throw — **it silently prints the source string**, which is why this needs a test rather than a code review:

- Every `__()` key in the codebase exists in **both** catalogs
- Both files are **sorted** and have **identical key sets**
- **No English value has been left as Arabic text** — the commonest symptom of a hurried copy
- No key is unused (dead keys accumulate and get translated)

## Content that is not UI strings

| Content | Locale |
|---------|--------|
| Invoice, credit note, statement | **The recipient's**, from the contact |
| Email and dunning notice | The recipient's |
| Error shown to a user | The user's |
| API `type` and machine-readable fields | **Never localized** |
| API `detail` and `title` | By `Accept-Language` |
| Log lines and traces | **Never localized** — they are read by engineers and matched by tools |
| Legal notes on documents | **Per jurisdiction**, not per locale. A reverse-charge note has prescribed wording that is not a translation choice |
| Exported CSV and XLSX | The user's locale, with correct encoding, direction and digit shape; an XLSX opened in Excel must show RTL columns in the right order |
| Database content a tenant typed | Stored as entered; never machine-translated |

## Testing

| Layer | Asserts |
|-------|---------|
| Unit | ICU plural selection across all six Arabic categories; digit shaping round-trips |
| Architecture | No physical CSS property in any template; no untranslated literal string |
| Stylelint | No physical property in any stylesheet |
| Feature | Translation catalog completeness, sorting, identical key sets, no Arabic left in the English file |
| Visual regression | **Every screen in LTR and RTL, light and dark, at all three densities** |
| Browser | Keyboard navigation in RTL — arrow keys in a grid must follow visual order, not logical order |
| Print | A document rendered in Arabic with Latin identifiers, bidi-isolated, diffed as a PDF |
