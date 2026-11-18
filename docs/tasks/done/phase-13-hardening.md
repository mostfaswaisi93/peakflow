# Phase 13 — Security, observability, performance and release — finished

The tasks of [phase-13-hardening.md](../phase-13-hardening.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-235 · XML hardening
`Exchange` · **3 pts** · depends: PF-114 · standards: OWASP · `risk:high` `type:security`

- [x] XXE, external entity resolution, DTD loading and network access **disabled explicitly on every reader**, not assumed off by default
  - `XmlCanonicalizer` was a second implementation of the same hardening and now delegates to `SafeXmlReader`, so there is one place to get it right. `schemaValidate()` was a third, unguarded parser: libxml opens the `.xsd` itself and follows every `xs:import` through whatever entity loader is installed. It now runs under a loader confined to `resources/schemas`, and a refused reference is reported as `EN16931-SCHEMA-REFERENCE` rather than thrown - PHP raises a warning there that Laravel turns into an exception, so it would otherwise have escaped the pipeline instead of being reported.
- [x] Entity-expansion and depth limits configured, so a billion-laughs payload fails fast
  - The refusal used to run **after** the parse, which is not a defence: libxml expands an internal general entity while building the tree, and `LIBXML_NOENT` only decides whether the result is flattened into text nodes. A 347-byte payload parsed cleanly and came back a million characters long, all allocated before anything objected. Refused on the bytes now; the post-parse check stays as a second layer with its own message.
- [x] Size limits before parsing, since an inbound document from a trading partner is untrusted input
  - `config/exchange.php` declared `max_bytes`, `max_depth` and `max_entity_expansion` and **nothing read any of them**: a document 250 elements deep was accepted while the file said 100, and 100 could never have fired anyway because libxml stops at 256 either way. A check now asserts the configured depth limit is one that can actually apply, so a number that looks like a limit and is not cannot come back.
- [x] A test fires XXE, billion-laughs, a deeply nested document and an oversized one at every parser the application has
  - Including `OfxParser`, which was safe **by accident**: `asXml()` truncates everything before `<OFX>` for the SGML tag-closer's benefit, which happens to delete a DOCTYPE, which happens to leave the entity references undefined - so the payload was refused with `Entity 'e5' not defined` shown to somebody holding a file their bank sent them. It rested on a regular expression written for another purpose and nothing said so. It has its own byte guards now, on the content as received.

