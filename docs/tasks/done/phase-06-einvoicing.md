# Phase 06 — EN 16931, serializers, validation and jurisdiction profiles — finished

The tasks of [phase-06-einvoicing.md](../phase-06-einvoicing.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-110 · The semantic invoice model
`Exchange` · **8 pts** · depends: PF-091 · standards: EN 16931-1 · `risk:high` `type:compliance`

- [x] A syntax-independent representation addressed by EN 16931 business terms and groups: `App\Exchange\Semantics\BusinessTerm` (BT-1 to BT-161, with the standard's real gaps - there is no BT-4, and a registry that invented one to close the sequence would be a registry nobody could check against the standard) and `BusinessGroup` (BG-1 to BG-32, each declaring the group it nests in)
- [x] Every term the application populates is named by its `BT`/`BG` identifier in the code, so a mapping question has one answer. The serializers reference enum cases, never element paths
- [x] Cardinality and conditionality enforced in the model, not in each serializer - a term that is mandatory in UBL and forgotten in CII is a document that validates in one syntax and not the other, where the recipient sees a rejection, the supplier sees a document that looked fine, and the engineer looks in the wrong serializer because the last failure was in the other one
- [x] The cardinality is a property of the **group**, which is why the model is a tree and not a flat map of BT values. `BT-84` is mandatory - inside `BG-17`, a credit transfer, which is itself optional: flattened to "the IBAN is optional" the rule that a credit transfer without an account identifier is not a credit transfer disappears, and that is the rule the recipient's validator enforces
- [x] A missing mandatory term reports the rule a recipient's own validator would quote - `BR-09` for a missing seller country, not "a required field is empty" - so one defect reads as one defect wherever it is reported
- [x] A term set on the wrong group **throws** rather than failing validation: nobody entered the delivery address into the payment instructions, the mapping code put it there, and the person who can fix it is reading a stack trace rather than an invoice
- [x] Identifiers carry their scheme beside the value rather than inside it, because `0088` and `9930` are both electronic addresses and mean different registries, and `BR-62`/`BR-65` reject a bare one
- [x] The model is built from the stored document, never from a draft, so what is serialized is what was issued. A draft is refused by name: it has no number, no frozen supplier and no hash, and the document produced would look issuable and not be
- [x] Built from the **frozen snapshots**, so a customer renamed in March does not change January's XML - the recipient has archived the January copy and the two would no longer hash the same. A check renames the party after issue and requires the old name
- [x] Amounts are emitted at the currency's own exponent, not at the stored `numeric(21,4)`: `BR-DEC-*` rejects an amount with more decimals than the currency has, and a 3-decimal dinar and a 0-decimal yen share one column by design
- [x] A term with no `BT` number and no business reason cannot be added - the registry is checked against BT-1..BT-161 and against the mapping document in both directions, so an invented term fails the build
- [x] Found on the way: the first credit note built through this path was refused by `BR-CO-25` - an amount was due and the document stated neither a due date nor payment terms, because `Crediting` carried no terms snapshot at all. The recipient's own validator would have found it; the model found it one step earlier. A credit note now inherits the credited document's payment terms, **re-resolved** on its own date and amount rather than copied, because a partial credit against a three-instalment invoice would otherwise state the invoice's instalment figures

### PF-111 · The mapping document and its tests
`Exchange` · **5 pts** · depends: PF-110 · standards: EN 16931-1 · `type:compliance`

- [x] [`../standards/en16931-mapping.md`](../standards/en16931-mapping.md) maps every business term to its internal source, stating mandatory, conditional (and on what) or deliberately unpopulated
- [x] A check asserts the document and the code agree: every term in the registry appears in the document, and every term in the document exists in the registry. Cardinality is compared for every row that names a single term; a row covering `BT-75`–`BT-79` states "0..1 each" and is more readable than five rows, and the registry is where a term's exact cardinality lives
- [x] Deliberate omissions are listed **with a reason**, so an omission is a decision rather than a gap - and the check counts a term in the omissions table as documented, because an omission with a reason is documentation
- [x] Found on the way, by running it: `BG-24` (the supporting documents, `BT-122` to `BT-125`) was absent from the document entirely - four terms the registry held and the mapping never mentioned
- [x] Found on the way: three cardinalities in the document were the **group's** rather than the term's. `BT-22` is `1..1` inside `BG-1`, which is `0..n`; the document said `0..n`, which reads as "a note may be omitted" and is the opposite of the rule. `BT-84` said `0..1` where `BR-50` makes it mandatory inside a credit transfer
- [x] Found on the way: two rule descriptions were wrong. `BR-CO-9` is the ISO country-prefix rule, not "a seller VAT identifier is present when VAT is charged" - that is `BR-CO-26` and the per-category rules. And `BR-Z-10` does not exist: zero-rated is a rate, not an exemption, and the reason is required for `E`, `AE`, `K`, `G` and `O`. A mapping document that has drifted is worse than none, because it is consulted and it is wrong

### PF-112 · The UBL 2.1 serializer
`Exchange` · **8 pts** · depends: PF-110 · standards: OASIS UBL 2.1, ISO/IEC 19845 · `risk:high` `type:compliance`

- [x] Invoice and CreditNote documents, namespace-correct, with the EN 16931 customization and profile identifiers. They are **different documents**, not one document with a different type code: separate root elements, separate line elements (`cac:InvoiceLine` against `cac:CreditNoteLine`), separate quantity elements (`cbc:InvoicedQuantity` against `cbc:CreditedQuantity`) and a different element order. A debit note (383) and a corrected invoice (384) are `Invoice` documents - the correction is in the type code and the preceding reference
- [x] Element ordering per the schema sequence - UBL is order-sensitive and a validator will say so unhelpfully, naming the element it did not expect rather than the one out of place. The sequences are **data**, in `UblSequences`, so they can be read against the schema by a person and diffed when a profile adds a term
- [x] And the order is not left to the control flow of whichever method emitted the element: the writer sorts children into the declared sequence, and an element with no declared position is an **error** rather than something appended at the end - a name the sequence does not know is either a typo or a term somebody added without deciding where it goes
- [x] The `CreditNote` sequence puts `cbc:TaxPointDate` **before** its type code and its note, where the `Invoice` puts it after both. That difference is what makes a shared "emit a document" method wrong, and a check asserts both orders and exercises the credit-note one on a document that carries a tax point
- [x] Code lists emitted with their `listID`, and identifiers with their `schemeID`: `S` is a tax category in UNCL 5305 and something else in other lists, and a code without its list is a string the recipient has to guess at
- [x] Amounts carry `currencyID`; quantities carry `unitCode`; the tax breakdown emits one `TaxSubtotal` per rate group, and a check requires the subtotals to sum to `cbc:TaxAmount` - `BR-CO-14`
- [x] Canonicalized output is deterministic and stable, so the archive hash does not move: the same model serializes byte-identically, the canonical hash is stable, and it survives a parse-and-reserialize round trip - which is what every access point does to a document in transit
- [x] Text goes in through a text node, not `createElement`'s second argument, which does not escape: `&` and `<` in a customer's legal name would otherwise produce a document that is not well-formed, and the recipient sees a parse error on an invoice nobody can read
- [x] Found on the way, by reading the output: the first document carried `<cbc:CompanyID>SA:VAT:310000000000003</cbc:CompanyID>` - the snapshot's internal qualified reference, which appears on no document anywhere. Every check passed, `BR-CO-9` included, because the string does begin with `SA`. A check now asserts the shape and not only the rule, and `PartyTaxRegistration::qualified()` says in its docblock that it is not a document value
- [x] Found on the way: a bite that collapsed the serializer to **one** `TaxSubtotal` left every check green, because the fixture's document had exactly one rate group. One per group is the entire reason tax is grouped, so the fixture now produces a standard-rated and a zero-rated line
- [x] Found on the way: removing the writer's sort changed nothing, because both serializers happen to build their children in schema order. That makes the sort defence against code written later, and defence nothing exercises is defence nobody notices the loss of - so a check builds a document with its children deliberately reversed
- [x] Found on the way, in `Sales`: `InvoiceValidator` read `$line->invoice?->taxBreakdown()` to check a zero-rated line's exemption reason. Lazy loading is disabled, so that line **threw** instead of validating - and it had never run, because every fixture until now was standard-rated. The first zero-rated invoice to reach the validator would have failed with "attempted to lazy load" rather than with a finding

### PF-113 · The CII D16B serializer
`Exchange` · **5 pts** · depends: PF-112 · standards: UN/CEFACT CII D16B · `type:compliance`

- [x] `CrossIndustryInvoice` from the same semantic model, sharing nothing with the UBL serializer but the model - no helper, no base class, no shared "emit a party" method, and a check reads both files to require it. If one syntax could be produced from the other, the model would not be what makes them independent and this phase would prove nothing
- [x] The differences are real and not cosmetic: there is **no credit note document** in CII (one root, type code 381); the **tax point** is document-level in UBL and sits inside each `ram:ApplicableTradeTax` here; **dates** are `20260518` inside a `udt:DateTimeString format="102"`; a **boolean** is a `udt:Indicator` element; a **line** is split into agreement, delivery and settlement; the note's **subject code** is its own element where UBL folds it into the text as `#code#text`; the tax registration's scheme is `VA` rather than a nested `cac:TaxScheme`
- [x] Both syntaxes validated against the same semantic test corpus, proving the model is genuinely syntax-independent
- [x] A check asserts a document serialized to both and read back yields the same semantic model - through **two independently written path tables**, one per syntax, held in the tests rather than the application. If the serializers were generated from those tables the check would be asserting that one table equals itself
- [x] And the round trip cannot quietly shrink: a term the model populates with no path in either table is reported as a gap, and the check refuses to pass if fewer than fifteen terms were actually read back out
- [x] Found on the way: `ram:TaxPointDate` is a `udt:DateType`, whose child is a `udt:DateString` - not a `DateTimeString`, and not the element's own text. The first version emitted the ISO date as text, which is a third wrong answer, and the behavioural check caught what PF-114's schema validation would have

### PF-118 · The jurisdiction profile interface
`Exchange` · **5 pts** · depends: PF-116 · standards: — · `risk:high`

- [x] `JurisdictionProfile` declaring: permitted document types, numbering rules, tax rule set, rounding policy, serializer, ordered validator chain, transport, sealing policy, archive policy, QR policy
- [x] And **clearance is not reporting**. A cleared document is the authority's copy - the one with legal effect and the one that must be delivered and archived - where a reported document is delivered first and reported within a window. The interface makes a profile say which, because conflating them produces a system that delivers a document the authority has not seen, and that is the common failure in this area
- [x] A registry gated by `config/jurisdictions.php`; a disabled profile cannot be selected by a tenant, and a key that is enabled with no implementation **fails the build** - otherwise a tenant selects it and the first they know is a rejection on a real invoice
- [x] But a disabled profile is still **resolvable**. A document issued last year under a profile since turned off still has to serialize, still has to validate and still has to be reprintable: a registry that refused one would make those documents unreadable the day it was disabled
- [x] An entity declares the profiles it operates under, and a document's profile is **frozen on it at issue** - `ProfileRegistry::forDocument()` reads the frozen value, so a tenant who moved to Peppol in March does not have January's documents reserialized under Peppol's rules
- [x] Adding a country is a new profile class plus fixtures, and never a change to `Sales` - proven by Deptrac and by a check that reads the profile directory and refuses any reference to `Sales`, `Ledger`, `Tax` or `Parties`, or any database access. A profile that needs something the document does not carry means the document is missing something, not that the profile may reach into the issuer
- [x] A validator chain naming a stage nothing implements is **refused by name**, because a profile that declares validation it does not get is worse than one that declares none
- [x] And stage 4 runs even when a chain forgets to name it: a profile with rules in `check()` and no `cius` in its chain would otherwise silently not enforce them, which is the quietest possible compliance failure

### PF-119 · The generic EN 16931 profile
`Exchange` · **3 pts** · depends: PF-118 · standards: EN 16931-1 · `type:compliance`

- [x] The baseline every other profile specializes: UBL 2.1, stages 1-3, file delivery, no sealing, no transport
- [x] Usable end to end with no third-party credentials, which is what makes the rest testable. No access point, no participant identifier, no onboarding, no signing key - a fresh clone can issue, validate, serialize and write a document to a file
- [x] It is a **real implementation**, not the absence of one. A system whose "no profile" path is a different code path from its profile path has two behaviours to maintain, and the one nobody uses in production is the one every test runs against
- [x] It adds **no rules of its own**. EN 16931 is the rule set; a CIUS is what a jurisdiction adds, and inventing a restriction at the baseline would mean rejecting documents the standard permits - which is the opposite of a baseline
- [x] It populates no term it has no reason to: `BT-23` is absent because EN 16931 defines no business process, and a value invented for the baseline would be a term populated for nothing - which the mapping document forbids and the agreement check would catch
