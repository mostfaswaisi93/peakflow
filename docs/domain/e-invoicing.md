# Electronic invoicing

One semantic model, serialized into whatever syntax a recipient needs.

A tenant selling into the EU and into Saudi Arabia needs Peppol BIS and ZATCA, which disagree about almost everything at the XML level and agree about nearly everything at the meaning level. **Two serializers over one model is a week's work; two invoice systems is a product**, and the second one is always a year behind the first. Everything below exists to keep the first shape and refuse the second.

---

## The model

`App\Exchange\Semantics\BusinessTerm` holds EN 16931's business terms, BT-1 to BT-161, and `BusinessGroup` holds BG-1 to BG-32 with the nesting. Every value the application emits is addressed by its BT number and by nothing else, so "where does BT-112 come from" has one answer and a serializer references a term rather than an element path.

The registry has the standard's real gaps. **There is no BT-4.** A registry that invented one to close the sequence would be a registry nobody could check against the standard, and the first person to look up BT-4 would find a term we made up.

### Why it is a tree and not a flat map

EN 16931's cardinality is mostly a property of a *group*. `BT-84`, the payment account identifier, is mandatory — inside `BG-17`, a credit transfer, which is itself optional and repeatable. Flattened to "the IBAN is optional", the rule that a credit transfer without an account identifier is not a credit transfer disappears, and that is the rule the recipient's validator enforces.

A flat model also cannot express three invoice lines each with their own price details and item attributes, so each serializer would invent its own nesting — which is how two syntaxes of one document come to disagree.

### Built from the stored document

`SemanticInvoiceBuilder` reads an **issued** invoice and its frozen snapshots. Never a draft, which has no number, no frozen supplier and no hash; never the live party or today's tax rate. A customer renamed in March must not change January's XML: the recipient has archived the January copy, and the two would no longer hash the same.

A term set on the wrong group **throws**. It is not a validation failure — nobody entered the delivery address into the payment instructions, the mapping code put it there, and the person who can fix it should be reading a stack trace rather than an invoice.

### Amounts

Stored as `numeric(21,4)` so a 3-decimal dinar and a 0-decimal yen share one column; emitted at the currency's own exponent, because `BR-DEC-*` rejects an amount with more decimals than the currency has.

---

## The syntaxes

| | UBL 2.1 | CII D16B |
|---|---|---|
| Credit note | A **different document**: own root, `cac:CreditNoteLine`, `cbc:CreditedQuantity`, and `cbc:TaxPointDate` **before** the type code | The same document with type code 381 |
| Tax point | Document level | Inside each `ram:ApplicableTradeTax` |
| Dates | `2026-05-18` | `20260518` inside a `udt:DateTimeString format="102"` — and a plain date is a `udt:DateString`, not a `DateTimeString` |
| Booleans | The text `true` | A `udt:Indicator` element |
| A line | Price, quantity and tax beside each other | Split into agreement, delivery and settlement |
| Note subject code | Folded into the text as `#code#text` | Its own `ram:SubjectCode` element |
| VAT registration | `cbc:CompanyID` in a `cac:PartyTaxScheme` with `cac:TaxScheme/cbc:ID` = `VAT` | `ram:SpecifiedTaxRegistration/ram:ID` with `schemeID="VA"` |

The two serializers **share nothing but the model** — no helper, no base class, no shared "emit a party" method, and a check reads both files to require it. If one could be produced from the other, the model would not be what makes them independent and the whole arrangement would prove nothing.

### Element order is not a style question

Every UBL and CII complex type is an `xsd:sequence`. An element in the wrong position fails schema validation, and the message names the element the parser did not expect rather than the one that is out of place — which is why `cvc-complex-type.2.4.a` is a familiar and unhelpful sight.

So the sequences are **data**, in `UblSequences` and `CiiSequences`, and the writer sorts children into them. An element with no declared position is an error, not something appended at the end: a name the sequence does not know is either a typo or a term somebody added without deciding where it goes.

### Determinism

The same model serializes byte-identically — no pretty-printing, no generated ids, no timestamps — and the canonical hash survives a parse-and-reserialize round trip, which is what every access point does to a document in transit. The archive hash has to be stable or a stored hash proves nothing.

---

## The four stages

| Stage | What it is | Who fixes a failure |
|-------|-----------|---------------------|
| 1 `model` | Cardinality and conditionality, before any XML exists | Whoever filled the document in |
| 2 `schema` | XSD, against the vendored UBL and CII schemas | Whoever wrote the serializer |
| 3 `en16931` | The `BR-*` and `BR-CO-*` rules | `Tax`, usually — a calculation rule here is our bug |
| 4 `cius` | The jurisdiction's own restrictions | The supplier, or the profile |

The pipeline **stops at the first stage that fails**, and not to save time: a rule set run over a document that does not parse reports the wrong problem, and a supplier asked to fix a CIUS restriction on a document whose totals do not add up spends an afternoon on the second-largest problem.

Stage 2 currently reports itself **skipped, with the reason** — the UBL and CII schemas are large vendored artefacts and are not in the repository yet (PF-114). A schema stage that silently passed would be a pipeline calling a document valid when nobody had checked its syntax, and the first to find out would be the recipient. What it does do is parse the bytes through `SafeXmlReader`, so a serializer that produced malformed XML fails here.

Stage 4 runs even when a profile's chain forgets to name it. A profile with rules in `check()` and no `cius` in its chain would otherwise silently not enforce them, which is the quietest possible compliance failure.

### Reading XML that came from outside

`App\Support\Xml\SafeXmlReader`. A received invoice is a file a stranger wrote, and the three ways an XML parser becomes a file reader, a network client or a memory bomb are all on by default in libxml or one careless flag away:

- **`LIBXML_NOENT` reads as "no entities" and means *substitute* entities.** Passing it is what turns `<!ENTITY x SYSTEM "file:///etc/passwd">` into a file read. It is not passed, and the external entity loader is replaced while the reader runs.
- **Any DOCTYPE is refused.** Entity expansion needs no external reference: ten nested entities expand to a gigabyte from a few hundred bytes, and the parser is doing exactly what the specification says. libxml has a limit, and a limit is a tuning parameter somebody can raise. A document with no DOCTYPE cannot do it at all.
- **`LIBXML_NONET`** refuses the network even if something else asked for it.

### Errors are addressed by business term

A validator says `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount`. The person reading it is a bookkeeper who has never seen UBL and never should. `BT-112` is the same fact in the language of the document in front of them — and it is the same string whether the failure came from UBL, from CII or from the model, which matters because a message quoting the syntax tells the reader which serializer ran and that is not information they can use.

Each message carries the rule identifier the recipient's own access point would quote, the offending value, and a bilingual explanation from `config/en16931.php`. The API surfaces them in an RFC 9457 problem document whose `errors` array is keyed by business term.

**A calculation failure is reported as internal, not customer-facing.** The document was built from stored figures the ledger has already posted: if `BT-110` disagrees with the breakdown then the document and the journal entry disagree, and asking the customer to correct it is asking them to fix our arithmetic.

---

## Jurisdiction profiles

A profile declares the permitted document types, the specification identifier, the serializer, the validator chain, the CIUS rules, the transport, the sealing policy, the submission mode, the retention period and the QR policy.

**Adding a country is a new profile class plus fixtures, and never a change to `Sales`.** Deptrac proves `Sales` cannot see `Exchange`; a check reads the profile directory to require that no profile reaches into `Sales`, `Ledger`, `Tax` or `Parties`, or touches the database. If a profile needs something the document does not carry, the thing is added to the document and frozen at issue — otherwise the second country's requirements appear as conditionals in the issuer, and by the fourth there is no issuer any more.

### ZATCA, as a national extension

Peppol is a CIUS and adds no elements. ZATCA adds `cbc:UUID`, a transaction type on the type code, and three `cac:AdditionalDocumentReference` entries — the invoice counter, the previous invoice hash and the QR payload — none of which EN 16931 has a business term for, because the standard models no hash chain and no QR code. So ZATCA has a serializer and Peppol does not, and the interface supports both.

The serializer **composes** the UBL element tree and adds to it. `extends UblSerializer` would let a national variant change anything, and the first country to override how parties are emitted would quietly have its own serializer under a shared name. A check asserts the ZATCA document carries every value the plain UBL one does: an extension that changed a total would be two documents for one invoice, both well-formed, disagreeing about what is owed.

Values EN 16931 does not model travel in a **closed** set of jurisdiction keys, each declared with a reason. An open extension map would be the easy answer and the end of the model: within a year every profile would read values nobody had reviewed, and the mapping document would describe half the document.

#### The QR code

Tag, length, value; concatenated; base64. A fixed binary format because a phone with no network has to read it — an inspector scans the code on a printed receipt and sees the seller, the registration, the time, the total and the tax without asking anything of anybody.

| Tag | Value |
|-----|-------|
| 1 | Seller name |
| 2 | Seller VAT registration number, **without** its country prefix |
| 3 | Invoice timestamp |
| 4 | Total including VAT |
| 5 | VAT total |
| 6–9 | The XML hash, the stamp's signature and keys — omitted until a document is sealed |

The tag numbers matter more than their names: a reader written to the specification looks for tag 4 and expects the gross total. Tags are emitted in tag order whatever order they were given, an absent tag is absent rather than empty (tag 7 with no bytes tells a reader the signature is there and is nothing), and a value that does not fit the one-byte length is refused rather than truncated — an Arabic trading name is two or three bytes per character, and half a seller's name is worse than a build that failed.

#### Two chains, one of them translated

The previous-invoice hash is PeakFlow's own chain (PF-096) rendered the way the regime asks: base64 of the hex digest. The genesis differs — PeakFlow starts at sixty-four zeros, the regime names base64 of `sha256("0")` — so the profile substitutes. Emitting ours would produce a first document rejected for a hash correctly computed over the wrong convention.

#### Arabic is not a translation

The regime requires the invoice *in* Arabic. A document whose seller name and item descriptions are English with an Arabic version elsewhere is an English invoice with an appendix, and the authority reads the field. Two fields are enforced because their requirement is not in doubt; the authoritative list is in the regime's data dictionary, which is a vendored artefact — and a rule that rejects a conformant document is worse than one that does not run.

### Clearance is not reporting

| | |
|---|---|
| `clearance` | The document is submitted **before** delivery, and the authority's copy — not the one that was submitted — is the legal document and the archive copy |
| `reporting` | The document is delivered first and reported within a window |

Conflating them produces a system that delivers a document the authority has not seen. It is the common failure in this area.

### Peppol BIS Billing 3.0, as the second regime

Peppol does not change EN 16931's meaning — it removes choices. `BT-23` becomes mandatory, a document may carry at most one note, both parties must have an electronic address, the base amount and percentage of an allowance travel together, and the line net amount has to satisfy an identity the standard states only loosely.

So the profile is the baseline plus `check()`, plus a different specification identifier and a business process, and **the serializer does not change at all**. A check asserts both profiles use the same serializer class and that one model satisfies both rule sets, because a second country that needed a second serializer would mean the interface was the wrong shape.

Each restriction is a document EN 16931 accepts and the Peppol network rejects. Without stage 4 the supplier issues a conformant invoice, the access point refuses it, and nobody can explain why from our side.

Implemented rules use **Peppol's own identifiers**, and only where the identifier and wording are certain. The supplier reads our message and then the access point's rejection: if the two quote different identifiers for the same defect they spend the afternoon deciding which of us is wrong. The rest wait for the vendored Schematron, and the EAS code list waits with it — checking scheme *membership* against a partial copy would reject valid addresses, so only the four-digit shape is asserted.

### Enabled, and implemented, are different questions

`config('jurisdictions.enabled')` gates what a tenant may select. A profile class existing does not make it selectable: a half-built ZATCA profile with no production CSID would otherwise be offered to a tenant who would issue documents the authority rejects, and the first they would know is a rejection on a real invoice. A check fails the build if a key is enabled and has no implementation.

A **disabled** profile is still resolvable, though. A document issued last year under a profile since turned off still has to serialize, still has to validate and still has to be reprintable from the archive — a registry that refused to resolve one would make those documents unreadable the day it was disabled.

---

## What is not built yet, and why

| | |
|---|---|
| Vendored XSD and Schematron (PF-114, PF-115) | They are fetched artefacts, and this environment has no route to them. Stage 2 reports itself skipped rather than passing |
| The per-category calculation rules (`BR-S-08`, `BR-Z-08`, …) | Deliberately **not** implemented from a guess at their numbers. A rule that fires with an identifier the recipient's validator does not use sends whoever looks it up to a different rule |
| Peppol, ZATCA, Factur-X, ETA, JoFotara | Each needs credentials, a sandbox or the PDF/A pipeline. The generic profile exists so that none of them is needed to run the pipeline end to end |
| An inbound parser | Arrives with PF-122, which needs one to turn a received document into a draft bill. The round-trip check reads documents through path tables instead, which for proving syntax-independence is better evidence and far less code |
