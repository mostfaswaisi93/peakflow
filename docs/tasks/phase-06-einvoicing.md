# Phase 06 — EN 16931, serializers, validation and jurisdiction profiles

**20 tasks · 116 points**

The heaviest phase, and the one where the architecture either pays off or does not.

The mistake it exists to avoid is building for one country's XML. PeakFlow holds **one internal semantic model aligned to EN 16931** and serializes it into whatever syntax a recipient needs. A tenant selling into the EU and to Saudi Arabia needs Peppol BIS and ZATCA, which disagree about almost everything at the XML level and agree about nearly everything at the meaning level. Two serializers over one model is a week's work; two invoice systems is a product.

---

### PF-114 · XSD validation
`Exchange` · **3 pts** · depends: PF-112 · standards: XML Schema 1.0 · `type:compliance`

- [ ] Schema validation as stage 2 of the pipeline, against vendored UBL 2.1 and CII schemas
  - **The claim this criterion used to make was false, and PF-235's hardening is what made it false.** It said "vendoring them is the only thing needed to turn the stage on: no code change, no flag". The confined entity loader added on 2026-11-18 refused every relative `schemaLocation` - and every `xs:import` in the official UBL 2.1 and CII D16B distributions is relative. libxml hands the loader the schema's own absolute path and then hands it each reference **exactly as written, with no base URI**, so `realpath()` resolved it against the process working directory, wherever the queue worker happened to start. Reproduced end to end: a two-file schema set dropped at `resources/schemas/ubl/` reported `EN16931-SCHEMA-REFERENCE` on a document whose schema was entirely local. The first thing vendoring would have done is fail every invoice - the same outage the hardening was written to prevent, arriving from the other direction. Fixed, and bite 2 of that sweep is the regression proof.
  - **Still open on the artefacts.** `docs.oasis-open.org` and `unece.org` both answer 403 at this environment's egress proxy. A Maven redistribution IS reachable and was deliberately not used: it carries the 2013 Committee Specification rather than the OASIS Standard the EN 16931 artefacts name, and a schema stage validating against a draft reports failures the recipient's validator does not - green here, rejected there. Everything needed to finish it in one commit is in `schematron/PROVENANCE.md`.
- [x] And the stage **reports that it could not run**, with the reason, rather than silently passing. A schema stage that quietly does nothing is a pipeline calling a document valid when nobody has checked its syntax, and the first person to find out is the recipient
- [x] What the stage does do today is real: it parses the produced bytes through the hardened reader, so a serializer that emitted malformed XML, or a document carrying a DOCTYPE, fails here
- [x] **XXE, external entity resolution and entity expansion are disabled explicitly** on every reader, not assumed off. `LIBXML_NOENT` reads as "no entities" and in libxml means *substitute* entities, so passing it is what turns `<!ENTITY x SYSTEM "file:///etc/passwd">` into a file read; it is not passed, and the external entity loader is replaced with one that returns nothing while the reader runs
- [x] Any **DOCTYPE at all** is refused, which is the defence that matters: entity expansion needs no external reference, so refusing the network does not stop it. Ten nested entities expand to a gigabyte from a few hundred bytes and the parser is doing exactly what the specification says - libxml's limit is a tuning parameter somebody can raise, and a document with no DOCTYPE cannot do it at all. Both attacks have a dedicated failing-case check
- [x] A parse failure reports the parser's own message with the **line and column**, because "the document could not be parsed" is not something the sender of a 4,000-line invoice can act on
- [x] A schema failure reports the element and the rule, mapped back to a business term where possible
  - Closed with a fixture schema rather than waiting for the official ones: the element is parsed out of libxml's own message, the libxml rule code is carried, the line and column are asserted, and `cbc:TaxInclusiveAmount` maps to `BT-112` - verified against what the serializers actually emit, and bitten by mapping it to `BT-109` instead.

### PF-115 · Schematron compilation and vendoring
`Exchange` · **5 pts** · depends: PF-114 · standards: ISO/IEC 19757-3 · `risk:high` `type:compliance`

- [ ] Schematron rule sets compiled to XSLT 2.0 and **committed**, with the source version recorded
- [ ] Artefacts are vendored and version-pinned, not fetched at runtime: a government updating its Schematron on a Tuesday must not silently change what PeakFlow accepts on Wednesday
- [ ] A documented update procedure: bump the version, run the corpus, review the test-suite diff, merge. A newly failing rule is visible before it reaches a customer
- [ ] Regenerating the XSLT needs Java; the compiled artefacts mean a normal checkout does not

### PF-116 · EN 16931 semantic validation
`Exchange` · **5 pts** · depends: PF-115 · standards: EN 16931-1 · `risk:high` `type:compliance`

- [ ] Stage 3 runs the EN 16931 rule set: the `BR-*` content rules and the `BR-CO-*` calculation rules
  - **The artefact is no longer the blocker** - the official CEN/TC 434 abstract rule set (1.3.17) is vendored with its checksum and provenance, and all 201 of its rules are now accounted for. Running it is: the syntax binding is XSLT **2.0**, and PHP's `XSLTProcessor` is libxslt, which is 1.0.
  - Java is installed here and Saxon is on Maven Central, so it could be made to run - and that is PF-115's decision, not a commit. PF-115's own criterion says "regenerating the XSLT needs Java; the compiled artefacts mean a normal checkout does not", and spawning a JVM per document from a queue worker is the opposite of that design. It wants an ADR.
- [x] `BR-CO-10` (line net sum), `BR-CO-13` (document net), `BR-CO-14` (tax total equals breakdown sum), `BR-CO-15` (gross), `BR-CO-16` (amount due) each have a dedicated failing-case test - and so do `BR-CO-11`, `BR-CO-12` and `BR-CO-17`. Asserted directly in PHP on `BigDecimal`, never on floats: `0.1 + 0.2 !== 0.3` in binary floating point, and a tax total compared that way fails on a document that is correct
- [x] The rules are tampered with in the **model**, never in the stored document: the database refuses to change an issued invoice, and asking it to permit what it exists to prevent would be testing the wrong thing
- [x] `BR-CO-17` carries EN 16931's stated tolerance of 0.02 and no more, because implementations round the rate, the base or the product and all three are defensible - but 0.02 is where defensible stops
- [x] A rule that the application's own validation should have caught earlier is reported as an internal error, not as a customer-facing validation message - reaching stage 3 with a calculation error is a bug in `Tax`. The document was built from stored figures the ledger has already posted: if `BT-110` disagrees with the breakdown then the document and the journal entry disagree, and asking the customer to correct it is asking them to fix our arithmetic
- [x] The conditional rules whose EN 16931 wording is certain are implemented and each has a failing case: `BR-CO-3`, `BR-CO-9`, `BR-CO-19` to `BR-CO-26`, `BR-53`, `BR-62` to `BR-65`, and the exemption-reason rules for `E`, `AE`, `K`, `G` and `O`
- [x] The per-category calculation rules (`BR-S-08`, `BR-Z-08`, …) and the rate rules
  - Forty-five rules across nine families - `S`, `Z`, `E`, `AE`, `IC`, `G`, `O`, `IG`, `IP` - with every identifier and every condition read **out of the vendored artefact** rather than from a guess, which is what this criterion was waiting for. The tolerances come from the compiled binding rather than from the prose: ±1 exclusive for `S`/`IG`/`IP`, exact equality for the zero-tax families, `TaxAmount = 0` for the `-09` rules.
  - Reported at stage 3 and marked `internal`, because a summary that disagrees with the lines it summarises is not something the customer can fix. A check ties each family's declared shape to the standard's own sentence, so a family cannot be re-declared wrongly and stay green.
  - Found on the way, and the most useful line the completeness check produced: **`BR-48` is named by both `CalculationRules` and `CategoryRules` as the rule that should refuse a VAT breakdown with no rate, and is implemented by neither** - so a rateless breakdown is at present refused by nothing.

### PF-120 · Peppol BIS Billing 3.0
`Exchange` · **8 pts** · depends: PF-119 · standards: Peppol BIS Billing 3.0 · `type:compliance`

- [x] The CIUS applied: restricted code lists, mandatory elements beyond EN 16931, the Peppol customization identifier and the business process. A CIUS is a **narrowing, not a dialect** - Peppol removes choices rather than changing meaning, so the profile is the baseline plus `check()` plus two identifiers, and **the serializer does not change at all**. A check asserts both profiles use the same serializer class and that the same model satisfies both, because a second country that needed a second serializer would mean PF-118's interface was the wrong shape
- [x] Stage 4 runs the `PEPPOL-EN16931-*` rules whose identifier and wording are certain, each with a dedicated failing case: `R001` (business process), `R002` (one note), `R003` (a reference the buyer can match), `R004` (the specification identifier), `R005` (the accounting currency differs), `R010` and `R020` (both electronic addresses), `R041` and `R042` (base amount and percentage travel together), `R046` (net price is gross less discount), `R110` and `R111` (line periods inside the invoice period), `R120` (the line net amount is recomputable), `R121` (a positive base quantity), `R130` (the price unit is the line's), and `PEPPOL-COMMON-R050` (a four-digit EAS scheme)
- [x] And a document type Peppol does not carry is refused **before the number is allocated**. A debit note (383) is conformant EN 16931 with no Peppol flow: a draft can be changed and a gap in a gapless series cannot be closed
- [x] Buyer and seller electronic addresses with their EAS scheme codes, which is what routing needs - the address is the participant identifier the network resolves to an access point, and "issued but undeliverable" is a worse state than "not yet issued"
- [x] Found on the way: **the seller had no electronic address at all.** `party_tax_registrations` has carried `eas_code` since PF-065, so the buyer side was always there, and the entity's own registrations never did. Every document PeakFlow could build would have failed `R020`, and the first time anybody found out would have been the first Peppol document. It is a column on `tax_registrations`, frozen into the snapshot, because PF-118's rule is that a profile needing something the document does not carry means the document is missing something - a profile reading a config value would make last year's documents reserialize with this year's address
- [x] Found on the way, in the EN 16931 rules: `BR-CO-11` said nothing about a document carrying allowances with **no** `BT-107`. The comparison treated a missing stated total as a cardinality question, which is right for a document with no allowances and wrong here: the sum of a non-empty set is not absent, and a document with a 40.00 discount and no stated total reads to the recipient as a document with no discount - while its net total has been reduced by one
- [x] The profile is **built and deliberately not selectable**. The CIUS and the identifiers are implemented and checked; the AS4 transport (PF-122) and the SMP lookup (PF-121) are not, and a tenant selecting it today would produce documents that validate and cannot be sent. A check asserts it stays off until they exist
- [ ] The official Peppol test corpus passes in full, and is committed as a golden-file suite - the corpus is a fetched artefact and this environment has no route to it (PF-128)
- [x] The complete EAS code list, for the membership check
  - The complete official OpenPeppol eDEC list (v9.7, 105 entries) is vendored, and the membership set is **derived** as active ∧ registrable rather than copied - which reproduces the official Schematron's own 83-code `$eaid` literal element for element. So the two official artefacts check each other and neither can be replaced without the other noticing.
  - A withdrawn scheme stays readable and is refused for a new document, naming the registry it used to be - which is the answer a supplier can act on, where "unknown scheme" is not.
  - The membership check is emitted as `PEPPOL-EN16931-CL008`, not `R050`: see PF-117's note.
  - Found by its own check before hand-off, and worth recording as a PHP trap: holding the code list as a map keyed by code silently casts `"9910"` to an integer, so `array_keys()` returned `9910` and a strict membership test failed for 60 of the 83 registrable schemes - while `has('0002')` worked, because a leading zero keeps the key a string.

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

- [x] The **exact bytes that were sent** - XML, signature, receipt and PDF - stored in object storage. A re-rendered PDF is not an archive copy: five years from now the font, the template and a rounding policy have changed, and the hash the recipient holds matches nothing a re-render produces. A check issues a real document, serializes it through the profile's own serializer, archives that string and requires the archived bytes to be identical
- [x] One artefact per `(subject, kind)`, and a **second set of bytes for the same artefact is refused**. A retried job archives the same thing twice, which is fine; two different documents would mean two candidates for "the bytes that were sent", and the recipient holds one of them
- [x] WORM retention per the profile's declared period, so the copy cannot be deleted before its retention expires **even by an operator**. A trigger refuses the delete, refuses any change to the bytes, the path or the kind, and refuses a retention date moved earlier - the delete rule is only as good as the date it reads. Six years under ZATCA, ten under the generic profile, with the basis in words beside it, because a date with no reason is a date somebody will argue with
- [x] And object-lock is declared rather than applied: it is a bucket setting that cannot be turned on after the bucket exists. `ProductionConfiguration` refuses a production deployment without it, because an archive an operator with credentials can delete is not a WORM archive whatever the application refuses
- [x] Retention **expires**, and then the row is deletable. The rule is a period, not immortality: a system that could never delete would hold every tenant's documents forever, which is its own compliance problem
- [x] A checksum manifest per entity per period; `archive:verify` re-checksums against storage and reports divergence, and exits non-zero so a scheduled run is an alert rather than a log line nobody reads
- [x] The manifest hashes the **list**, not the files. Each artefact's own hash proves its bytes; an artefact deleted from storage *and* from the table together would leave every remaining hash correct, and only a hash over the ordered list notices that something is gone. A dedicated check does exactly that - deletes both - and requires the removal to be reported
- [x] A sealed manifest is **immutable**, and a period cannot be sealed twice. One that can be resealed always matches whatever is in the archive now, which proves nothing about what was there
- [x] A **restore drill is a scheduled task**, not a hope: `archive:drill` restores a random sample, re-hashes it and records the outcome - passing or failing - so "when did we last prove the archive restores" has an answer that is not somebody's recollection. The sample is random, so a drill that passed last month does not mean it checked the same artefacts
- [x] Retention overrides a tenant's deletion request, and the product **says so** rather than pretending to delete. The notice names the date, the basis and whose obligation it is, in both languages, because deleting on request and saying nothing leaves the tenant believing the document is gone and the authority believing it is retained - and nobody finds out until one of them asks
- [x] Found on the way, and it would have made this whole suite vacuous: `.env` pointed `ARCHIVE_DISK` at `local` - a leftover from the initial scaffolding, duplicated later by this task's own block - so the archive wrote to the application's shared disk while the checks faked one nothing used. Every storage assertion passed by looking at an empty fake, and a restore drill reported `passed` on a file it had deleted from somewhere else. The suite now fakes **whatever the configuration names**, and a production guard refuses an archive that shares a disk with attachments
- [x] Found on the way: the first intentional refusal aborted the transaction and took the next fifteen checks with it - PostgreSQL fails every statement after an error until the transaction ends, so the report said the suite could not be built. The savepoint runner that the sales suites had was extracted into a trait rather than copied
- [ ] PDF/A conformance of a restored artefact, which the drill would check if there were a PDF to check - PF-102's toolchain is not installable here
- [x] The receipt and the cleared XML have no call site yet: they are artefacts PF-122 and PF-124 produce, and the archive holds the kinds and refuses the others
  - All seven declared kinds round-trip byte for byte with retention computed from the profile, `receipt` and `cleared_xml` included; a kind outside the list is refused by `store()` **and** by the database, and the two lists are asserted to be one list - read back out of `pg_constraint` rather than compared as two declarations that can drift.
  - Found on the way: **nothing in the application archives an outgoing document.** One writer, `received_xml`, and six declared kinds with retention, a WORM trigger and a manifest entry and no producer. That is a legitimate state - there is no transport yet - and it read as finished work, which is the problem. `Archive::AWAITING_A_CALL_SITE` names each kind with the task that will write it, and the check fails **both** ways: a registered kind that gains a call site fails, and a kind with neither fails.
