# Canonicalization and hash chains

> `App\Support\Hashing` · PF-009 · `bin/verify canonical`

A hash is only meaningful if the bytes going into it are deterministic. Not
"deterministic for us" - deterministic for everyone, because a document hash is
checked by a tax authority, a counterparty's accounting system and an auditor's
tool, none of which run this code.

So the claim these checks make is **agreement with the specifications**, and the
cases they test are the ones where a plausible implementation quietly diverges.

## JSON: RFC 8785

### Members sort by UTF-16 code unit, which is not byte order

RFC 8785 §3.2.3 sorts object members on their UTF-16 code unit sequence. On
ASCII keys that is the same as sorting the UTF-8 bytes, which is exactly why the
difference is easy to miss:

```
UTF-8 byte order     U+FB33,  U+1F600
UTF-16 code units    U+1F600, U+FB33
```

U+1F600 is encoded in UTF-16 as the surrogate pair `D83D DE00`, so it sorts
*before* U+FB33. Any key above U+FFFF puts a byte-sorting implementation in a
different order from a conformant one - and nothing in an ASCII test suite shows
it. The check asserts the RFC's own example *and* asserts that the two orders
still disagree, because if they ever agreed the conversion would be dead code
and the rule would be testing nothing.

### The escaping is written out, because `json_encode` is not conformant

The implementation was `json_encode($value, JSON_UNESCAPED_SLASHES |
JSON_UNESCAPED_UNICODE)`, which is nearly right. Measured:

```
U+2028  json_encode →  " "      RFC 8785 →  literal UTF-8
U+2029  json_encode →  " "      RFC 8785 →  literal UTF-8
```

PHP escapes those two regardless of `JSON_UNESCAPED_UNICODE`, because they
terminate JavaScript string literals. RFC 8785 permits escaping only `"`, `\`
and U+0000–U+001F.

So a document whose description contained a LINE SEPARATOR produced a canonical
form that a conformant implementation would not produce, and therefore a
different hash. **That failure surfaces as a chain a counterparty cannot
verify** - which is the exact class of problem the chain exists to detect,
arriving from the thing meant to detect it.

The escaper is now written out: the five short forms (`\b \t \n \f \r`), `\"`,
`\\`, and `\u00xx` with **lower-case** hex for the remaining control
characters - `\u001F` and `\u001f` are different bytes and therefore different
hashes. Everything else, including `/`, U+007F, U+2028, U+2029 and all non-ASCII,
is emitted as literal UTF-8.

It walks bytes rather than characters, deliberately: everything that needs
escaping is ASCII and every byte of a multi-byte UTF-8 sequence is ≥ 0x80, so a
byte walk cannot mistake part of a character for one needing an escape, and it
passes multi-byte sequences through without decoding them.

Writing it out also answers the other half of PF-009 - *stable across PHP
versions* - because PHP's JSON extension is no longer involved.

### Two kinds of number are refused rather than serialized

**Floats**, which was already the case. RFC 8785's number rules exist to pin down
IEEE-754 serialization, and PeakFlow has no float in its money path at all, so a
shortest-round-trip formatter would only ever be exercised by a mistake.

**Integers beyond ±(2⁵³−1)**, which was not. RFC 8785 processes numbers as
ECMAScript doubles, so `9007199254740993` is outside what the canonical form can
carry: a conformant implementation reading it serializes `9007199254740992`.
Measured - PHP emitted the odd value unchanged - so the previous
`(string) $value` produced a canonical form no conformant implementation would
agree with.

Both refusals are in the safe direction. They cannot produce a wrong hash, only
a loud one. And nothing here needs an integer that large: money is a decimal
string and identifiers are ULIDs.

#### The message's arithmetic, and the hang underneath it

The refusal names the integer a conformant implementation *would* serialize, so
the message is convincing rather than merely correct. `(float) $value` is the
obvious way to compute it and is forbidden here: the architecture rules allow no
float anywhere in the money path, and a cast that is harmless in one place is a
cast somebody copies to one where it is not. So it is integer arithmetic -
above 2⁵³ the doubles are spaced `2^(e-52)` apart, where `e` is the position of
the highest set bit, and the answer is the nearest multiple of that spacing with
ties to even.

A check compares it against the cast across 2,000 random values plus the edges,
because `(float) $value` **is** the IEEE-754 rounding being reimplemented - it is
the reference, not a second opinion. 20,014 values agreed on the first full
sweep.

Two edges broke the first version, and the second is the interesting one:

- `PHP_INT_MAX` rounds up to 2⁶³, which no `int64` holds, so the multiplication
  overflowed into a float and the message read `9.2233720368548E+18`.
- `PHP_INT_MIN` **hung the process**. Negating it overflows into a float, and
  shifting a float never reaches zero, so the exponent loop ran until the
  process was killed. A wrong hash is bad; a request that never returns is
  worse, and it would be a request from a worker holding a transaction open.

Both are handled by writing 2⁶³'s decimal form down as a constant. The exponent
loop is *also* bounded at 62, so deleting those guards can no longer produce a
hang - and the check that compares against the cast wraps the call, because an
unguarded call turns the rule into a crash rather than a finding.

**Invalid UTF-8** is refused too. A byte walk cannot detect it, and a hash over
invalid UTF-8 is a hash of bytes nobody else reproduces - every other
implementation either refuses the input or substitutes U+FFFD, and both give a
different answer.

### Stability, asserted three ways

| Property | How |
|----------|-----|
| Insertion order | The same payload shuffled 200 times must give one hash |
| Locale | No locale-sensitive function may appear anywhere in `Support/Hashing` - `strcoll`, `setlocale`, `localeconv`, `strftime` and the rest are named and forbidden by a rule |
| PHP version | A golden vector: a fixed payload and its SHA-256 written down as a literal |

Only the `C` and `C.UTF-8` locales are installed in this environment, so a
matrix over `tr_TR` and `ar_SA` is not available. A check that silently skips is
worse than one that asserts something else, so the structural claim is made
instead - the functions that could introduce locale dependence are forbidden by
name rather than tested for absence of effect.

The golden vector deliberately contains the three things that would move it:
Arabic text, a U+2028 LINE SEPARATOR, and keys whose sorted order is not their
insertion order. If it moves, every stored document hash in every tenant was
computed with the old rules.

## XML: Canonical XML

### Why canonicalization is not formatting

Two XML documents can be byte-different and information-identical: attribute
order is not significant, `<c/>` and `<c></c>` are the same element, an unused
namespace declaration changes nothing. A signature over the raw bytes breaks the
first time the document passes through any processor that reserializes it -
which every one of them does.

Asserted both directions. These hash the **same**: attributes reordered, extra
whitespace between attributes, single-quoted attribute values, an XML
declaration added, a comment added, CDATA around the same text, a numeric
character reference. These hash **differently**: a changed amount, a trailing
zero added to an amount, two elements swapped, a different namespace, whitespace
added between elements, a leading space inside text.

### C14N 1.1 is not reachable from PHP, and the code says so

PF-009 names C14N 1.1. PHP's `DOMNode::C14N()` takes a boolean `exclusive` flag
and nothing else: libxml 2.9.14 implements Canonical XML 1.0 and Exclusive C14N
1.0, and the 1.1 mode is not exposed through the PHP DOM API at all.

What 1.1 changed is narrow. Its normative differences are `xml:base` fixup and
the treatment of inherited `xml:id`, `xml:lang` and `xml:space` when
canonicalizing a **document subset** whose ancestors are excluded from the node
set. PeakFlow canonicalizes whole documents, where no ancestor is excluded and
no fixup arises, so for the inputs it accepts the two coincide.

That statement is derived from the specifications' difference list, **not
verified against a 1.1 implementation**, because none is available here. So
rather than rely on it, a document carrying `xml:base` or `xml:id` is refused:
those are exactly the attributes the two algorithms disagree about, and
producing 1.0 output while a signature claims 1.1 is a signature that verifies
nowhere else.

`xml:lang` and `xml:space` are *not* refused - both algorithms treat them the
same way for a whole document, and refusing them would rule out every Arabic
document that declares its language. A check asserts that too, so the refusal
cannot quietly widen.

### The DOCTYPE is refused, and this is the part that matters

Measured three ways, with a marker file written for the occasion:

| Configuration | Result |
|---------------|--------|
| `LIBXML_NOENT`, default entity loader | The canonical output **contained the file's contents** |
| `LIBXML_NOENT`, entity loader returning nothing | Blocked; output `<r></r>` |
| No `LIBXML_NOENT` | Entity not substituted; output `<r></r>` |

Two things follow.

**`LIBXML_NOENT` is the vulnerability, not the defence.** Its name reads as "no
entities"; it maps to `XML_PARSE_NOENT`, which *enables* entity substitution. The
flag whose name suggests the fix is the one that removes it. It is not passed.

**Not passing it is still not enough.** Without substitution the entity
reference canonicalizes to *nothing* - so a document whose content sits in an
entity hashes as though the content were absent, while a conformant processor
includes it. A silently different hash is the failure this whole class exists to
avoid.

Both behaviours are wrong, so neither is chosen: a DOCTYPE is refused outright.
No e-invoicing syntax needs one - UBL 2.1 and UN/CEFACT CII are schema-defined
and declare no entities. The same mechanism pointed at memory rather than at a
file - a nested entity expansion, `&c;` → `&b;`×10 → `&a;`×100 - is refused by
the same rule.

The external entity loader is also replaced with one that returns nothing for
the duration of the parse, and the table above shows that is a real second
defence rather than decoration: in the `NOENT` configuration it is what stopped
the read. It is restored in a `finally`, so a refusal cannot leave the process
unable to load a legitimate resource later.

One PHP detail worth recording: `libxml_set_external_entity_loader()` returns the
callable it replaced on PHP 8.4 and `true` on 8.3 and earlier. Restoring that
`true` is a `TypeError` - it was a fatal error on the first call - so the return
value is restored only when it is actually callable, and otherwise the loader is
reset to libxml's default.

### Exclusive canonicalization, and when it is right

Exclusive C14N omits namespace declarations the node set does not use. That is
what a signature over an embedded fragment needs - an enveloped invoice
signature, in Phase 06 - and it is the wrong default for a whole document, where
dropping an unused declaration loses information a recipient's schema validator
may want.

So `canonicalize()` defaults to inclusive and `canonicalizeFragment()` defaults
to exclusive. A check asserts that exclusive drops the *unused* declaration,
keeps the *used* one, and produces different bytes from inclusive - because if
the two agreed, one of them would not be doing what it claims.

Fragment selection refuses an XPath matching nothing (a signature over nothing
verifies), matching more than one node (which of the matches was signed is not
recoverable from the signature afterwards), or matching something that is not an
element.

## The chain

`ChainLink` stores the SHA-256 of a document's canonical form plus the hash of
the document before it in the same series, which makes three things detectable:
alteration (own hash no longer matches), deletion and insertion (the following
document's `previous` no longer matches). It is the mechanism behind ZATCA's
Previous Invoice Hash, and it is applied in every jurisdiction profile because
it costs nothing.

What it does **not** prove is authorship. A chain proves internal consistency;
anyone who can rewrite the whole chain can produce a consistent one. Proof of
authorship is a cryptographic seal with a key in a KMS (PF-124), and a separate
field.

`ChainWalker` reports the **first** divergence, which is the only actionable
fact on a series with forty thousand documents in it: everything before it is
intact, everything after it is *unverifiable rather than wrong*, and the
document at it is the one to investigate. A check breaks documents 2 and 4 of a
four-document chain and asserts the verdict names INV-2 **and does not name
INV-4** - reporting the later one as wrong is how four thousand documents get
investigated for one edit.

`ChainVerdict::describe()` exists because the class docblock claimed the verdict
was "in a form an operator can act on at 3am" and its only reader was
`jsonSerialize()`, which is a form a log aggregator can act on.

## How this is checked

`bin/verify canonical` - fourteen rules, ten of them proved by breaking what
they guard:

| Bite | Fired |
|------|-------|
| Keys sorted by UTF-8 bytes | The ordering rule, naming the two orders |
| Escaping delegated back to `json_encode` | The escaping rule on U+2028/U+2029, and the golden vector |
| The unsafe-integer guard removed | All three out-of-range integers |
| Upper-case hex in the escaper | Two control characters and the explicit lower-case rule |
| The UTF-8 validity check removed | All four malformed sequences |
| `LIBXML_NOENT` passed and the DOCTYPE refusal removed | The entity rule and the refusal rule |
| The `xml:base`/`xml:id` refusal removed | Three documents, including one where the attribute is on a descendant |
| Exclusive mode ignored | The namespace rule, both halves |
| The walker made to report the last divergence | The chain rule, both halves - it named INV-4 and failed to name INV-2 |
| The 2⁶³ overflow guard removed | The rounding sweep, naming `9.2233720368548E+18` |
