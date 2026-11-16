# Phase 06 — EN 16931, serializers, validation and jurisdiction profiles

**20 tasks · 116 points**

The heaviest phase, and the one where the architecture either pays off or does not.

The mistake it exists to avoid is building for one country's XML. PeakFlow holds **one internal semantic model aligned to EN 16931** and serializes it into whatever syntax a recipient needs. A tenant selling into the EU and to Saudi Arabia needs Peppol BIS and ZATCA, which disagree about almost everything at the XML level and agree about nearly everything at the meaning level. Two serializers over one model is a week's work; two invoice systems is a product.

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

- [ ] Invoice and CreditNote documents, namespace-correct, with the EN 16931 customization and profile identifiers
- [ ] Element ordering per the schema sequence - UBL is order-sensitive and a validator will say so unhelpfully
- [ ] Code lists emitted with their `listID`, and identifiers with their `schemeID`
- [ ] Amounts carry `currencyID`; the tax breakdown emits one `TaxSubtotal` per rate group
- [ ] Canonicalized output is deterministic and stable, so the archive hash does not move

### PF-113 · The CII D16B serializer
`Exchange` · **5 pts** · depends: PF-112 · standards: UN/CEFACT CII D16B · `type:compliance`

- [ ] `CrossIndustryInvoice` from the same semantic model, sharing nothing with the UBL serializer but the model
- [ ] Both syntaxes validated against the same semantic test corpus, proving the model is genuinely syntax-independent
- [ ] A test asserts a document serialized to both and parsed back yields the same semantic model

### PF-114 · XSD validation
`Exchange` · **3 pts** · depends: PF-112 · standards: XML Schema 1.0 · `type:compliance`

- [ ] Schema validation as stage 2 of the pipeline, against vendored UBL 2.1 and CII schemas
- [ ] **XXE, external entity resolution and entity expansion are disabled explicitly** on every reader, not assumed off
- [ ] A schema failure reports the element and the rule, mapped back to a business term where possible

### PF-115 · Schematron compilation and vendoring
`Exchange` · **5 pts** · depends: PF-114 · standards: ISO/IEC 19757-3 · `risk:high` `type:compliance`

- [ ] Schematron rule sets compiled to XSLT 2.0 and **committed**, with the source version recorded
- [ ] Artefacts are vendored and version-pinned, not fetched at runtime: a government updating its Schematron on a Tuesday must not silently change what PeakFlow accepts on Wednesday
- [ ] A documented update procedure: bump the version, run the corpus, review the test-suite diff, merge. A newly failing rule is visible before it reaches a customer
- [ ] Regenerating the XSLT needs Java; the compiled artefacts mean a normal checkout does not

### PF-116 · EN 16931 semantic validation
`Exchange` · **5 pts** · depends: PF-115 · standards: EN 16931-1 · `risk:high` `type:compliance`

- [ ] Stage 3 runs the EN 16931 rule set: the `BR-*` content rules and the `BR-CO-*` calculation rules — **blocked on PF-115**, which cannot vendor the official Schematron without fetching it
- [x] `BR-CO-10` (line net sum), `BR-CO-13` (document net), `BR-CO-14` (tax total equals breakdown sum), `BR-CO-15` (gross), `BR-CO-16` (amount due) each have a dedicated failing-case test - and so do `BR-CO-11`, `BR-CO-12` and `BR-CO-17`. Asserted directly in PHP on `BigDecimal`, never on floats: `0.1 + 0.2 !== 0.3` in binary floating point, and a tax total compared that way fails on a document that is correct
- [x] The rules are tampered with in the **model**, never in the stored document: the database refuses to change an issued invoice, and asking it to permit what it exists to prevent would be testing the wrong thing
- [x] `BR-CO-17` carries EN 16931's stated tolerance of 0.02 and no more, because implementations round the rate, the base or the product and all three are defensible - but 0.02 is where defensible stops
- [x] A rule that the application's own validation should have caught earlier is reported as an internal error, not as a customer-facing validation message - reaching stage 3 with a calculation error is a bug in `Tax`. The document was built from stored figures the ledger has already posted: if `BT-110` disagrees with the breakdown then the document and the journal entry disagree, and asking the customer to correct it is asking them to fix our arithmetic
- [x] The conditional rules whose EN 16931 wording is certain are implemented and each has a failing case: `BR-CO-3`, `BR-CO-9`, `BR-CO-19` to `BR-CO-26`, `BR-53`, `BR-62` to `BR-65`, and the exemption-reason rules for `E`, `AE`, `K`, `G` and `O`
- [ ] The per-category calculation rules (`BR-S-08`, `BR-Z-08`, …) and the rate rules - deliberately **not** implemented from a guess at their numbers, because a rule that fires with an identifier the recipient's validator does not use sends whoever looks it up to a different rule. They arrive with the vendored rule set

### PF-117 · Business-term-addressed errors
`Exchange` · **3 pts** · depends: PF-116 · standards: EN 16931-1, RFC 9457

- [ ] Every validation failure is reported as `BT-112`, not as `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount`. Users do not read XPath
- [ ] A message carries the rule identifier, the business term, the offending value and a bilingual explanation
- [ ] The API surfaces them in the RFC 9457 `errors` array keyed by business term
- [ ] A translation table from rule identifier to bilingual explanation, tested for completeness against the vendored rule sets

### PF-118 · The jurisdiction profile interface
`Exchange` · **5 pts** · depends: PF-116 · standards: — · `risk:high`

- [ ] `JurisdictionProfile` declaring: permitted document types, numbering rules, tax rule set, rounding policy, serializer, ordered validator chain, transport, sealing policy, archive policy, QR policy
- [ ] A registry gated by `config/jurisdictions.php`; a disabled profile cannot be selected by a tenant
- [ ] An entity declares the profiles it operates under, and a document's profile is **frozen on it at issue**
- [ ] Adding a country is a new profile class plus fixtures, and never a change to `Sales` - proven by Deptrac and by a test that counts the diff surface of adding a profile

### PF-119 · The generic EN 16931 profile
`Exchange` · **3 pts** · depends: PF-118 · standards: EN 16931-1 · `type:compliance`

- [ ] The baseline every other profile specializes: UBL 2.1, stages 1-3, file or email delivery, no sealing, no transport
- [ ] Usable end to end with no third-party credentials, which is what makes the rest testable

### PF-120 · Peppol BIS Billing 3.0
`Exchange` · **8 pts** · depends: PF-119 · standards: Peppol BIS Billing 3.0 · `type:compliance`

- [ ] The CIUS applied: restricted code lists, mandatory elements beyond EN 16931, the Peppol customization identifier
- [ ] Stage 4 runs the `PEPPOL-EN16931-*` rule set
- [ ] Buyer and seller electronic addresses with their EAS scheme codes, which is what routing needs
- [ ] The official Peppol test corpus passes in full, and is committed as a golden-file suite

### PF-121 · Peppol participant lookup
`Exchange` · **5 pts** · depends: PF-120 · standards: Peppol SML/SMP

- [ ] SML DNS lookup and SMP metadata retrieval to resolve a participant's capabilities and endpoint
- [ ] Results cached with a sane TTL; a lookup failure is a retryable transmission error, not a document error
- [ ] A pre-send check tells the user whether a recipient is reachable **before** they issue, because "issued but undeliverable" is a worse state than "not yet issued"
- [ ] Participant identifiers validated against their scheme

### PF-122 · Peppol AS4 transport
`Exchange` · **8 pts** · depends: PF-121 · standards: OASIS AS4, ebMS 3.0

- [ ] Transmission through a configured access point, with message signing and the AS4 receipt retained as proof of delivery
- [ ] Inbound documents received, validated and queued as draft bills for `Purchasing`
- [ ] Retry with exponential backoff, a circuit breaker per endpoint, and a dead-letter queue that a human can inspect and re-drive
- [ ] The receipt is part of the archive, because it is the evidence that the document was delivered
- [ ] `EXCHANGE_DRIVER=log` records what would have been sent and marks it succeeded, so the state machine is exercised without a partner

### PF-123 · The ZATCA profile
`Exchange` · **8 pts** · depends: PF-119 · standards: ZATCA e-invoicing · `risk:high` `type:compliance`

- [ ] The ZATCA UBL subset for standard and simplified invoices
- [ ] Previous Invoice Hash chained per the specification, reusing the chain from `PF-096`
- [ ] The **TLV-encoded QR** payload with the required tags, base64 in the document and rendered on the PDF
- [ ] Arabic is mandatory on specified fields, and a document missing them is refused at stage 1
- [ ] Standard invoices are **cleared before delivery**; simplified invoices are **reported within the reporting window** - two different flows, and conflating them is the common failure

### PF-124 · ZATCA sealing and the clearance API
`Exchange` · **8 pts** · depends: PF-123 · standards: ZATCA, eIDAS-equivalent sealing · `risk:high` `type:security` `type:compliance`

- [ ] Onboarding: CSR generation, compliance CSID, production CSID, and credential rotation
- [ ] The cryptographic stamp applied in the `Exchange` plane with the key held in a **KMS or HSM and never in the database**
- [ ] Clearance and reporting calls with their own retry, backoff and dead-letter handling; a rejection is surfaced with the authority's own message and the business term it relates to
- [ ] A cleared document stores the authority's response, its UUID and the cleared XML as the archive copy - the cleared document, not the one that was submitted, is the legal one
- [ ] Validated against the sandbox before any tenant is enabled, and enabling a tenant is an operator action with a recorded check

### PF-125 · Factur-X / ZUGFeRD 2.3
`Exchange` · **5 pts** · depends: PF-113, PF-102 · standards: Factur-X 1.0 / ZUGFeRD 2.3, ISO 19005-3 · `type:compliance`

- [ ] CII XML embedded in a PDF/A-3 with the correct attachment relationship and filename
- [ ] XMP metadata declaring the profile level
- [ ] **A test asserts the PDF and the XML agree** on every monetary total and every identifier - a hybrid document whose two halves disagree is the specific failure this format invites
- [ ] veraPDF verifies the PDF/A-3 conformance of the final artefact

### PF-126 · The Egypt ETA profile
`Exchange` · **5 pts** · depends: PF-118 · standards: Egypt ETA · `type:compliance`

- [ ] ETA JSON document structure with its own canonicalization and signing requirements
- [ ] Item coding against GS1 or the EGS scheme, with the scheme recorded
- [ ] Submission, status polling and rejection handling through the ETA API
- [ ] A sandbox-validated golden-file suite

### PF-127 · The Jordan profile
`Exchange` · **3 pts** · depends: PF-118 · standards: JoFotara · `type:compliance`

- [ ] The national UBL subset and portal submission
- [ ] `JOD` is a **three-decimal** currency, so the amount scale, the rounding policy and the presentation exponent are all exercised by this profile - which is part of why it is in scope early
- [ ] Arabic-mandatory fields enforced at stage 1

### PF-128 · The golden-file corpus
`Exchange` · **8 pts** · depends: PF-120, PF-123 · standards: — · `risk:high` `type:compliance`

- [ ] Documents from the **official** EN 16931 and Peppol test corpora, committed as fixtures with their expected serialized output
- [ ] Snapshot tests byte-compare the serializer output and run it through the full four-stage validation chain
- [ ] Negative fixtures: a document that must fail each major `BR-CO-*` rule, asserting it fails for the right reason
- [ ] The corpus is the regression net for every future profile, and a profile without one cannot be merged

### PF-129 · Archiving
`Exchange` · **8 pts** · depends: PF-128, PF-073 · standards: ISO 19005-3 · `risk:high` `type:compliance`

Archiving is part of compliance, not an afterthought. Most jurisdictions require the original electronic document retained, readable and unaltered, for 5-11 years.

- [ ] The **exact bytes that were sent** - XML, signature, receipt and PDF - stored in object storage. A re-rendered PDF is not an archive copy
- [ ] Object-lock / WORM retention per the profile's declared period, so the copy cannot be deleted before its retention expires even by an operator
- [ ] A checksum manifest per entity per period; `archive:verify` re-checksums against storage and reports divergence
- [ ] A **restore drill is a scheduled task**, not a hope: it restores a sample, verifies the hashes and the PDF/A conformance, and reports
- [ ] Retention overrides a tenant's deletion request, and the product says so in the UI rather than pretending to delete
