# Vendored validation artefacts

`config('jurisdictions.validation.artefact_path')` points here. Everything in
this directory is a **fetched artefact committed verbatim**, never edited, and
pinned to the version named below — PF-115, PF-116, PF-117, PF-120.

The reason it is vendored rather than fetched at runtime is in
`config/jurisdictions.php`: a government updating its Schematron on a Tuesday
must not silently change what PeakFlow accepts on Wednesday. The reason each
file's origin and hash are written down here is narrower and just as
important: a standards artefact is only evidence if the next person can tell
where it came from. A file whose provenance is "somebody downloaded it" is a
file nobody can re-verify, and re-verifying is the whole point of pinning.

Re-fetch and compare with:

```bash
sha256sum -c schematron/SHA256SUMS
```

## The files

### `en16931/EN16931-model.sch`

The **abstract, syntax-independent EN 16931 rule set** — every `BR-*`,
`BR-CO-*`, `BR-DEC-*` and per-category rule with its official identifier and
its official assertion text.

| | |
|---|---|
| Publisher | CEN/TC 434, published through the European Commission's CEF eInvoicing building block |
| Repository | `https://github.com/ConnectingEurope/eInvoicing-EN16931` |
| Path | `ubl/schematron/abstract/EN16931-model.sch` |
| Branch | `master`, fetched 2026-11-18 |
| Release named by that branch's README | validation **1.3.17** (2026-10-09) |
| Licence | European Union Public Licence (EUPL) version 1.2, as stated in the file's own header |
| sha256 | `949095661fada023665f5d8589d85cc0543f8c50d47c0b214616622b05b3eb72` |
| Rule identifiers it carries | 201 |

**What it is used for here, and what it is not.** It is the authority for
*which rules exist and what they are called*: `EInvoicingCoverageRules` reads
it and fails the build when the `Exchange` sources can emit an identifier this
file does not contain, or when a rule we claim to implement is not in it. It
is **not** executed. Executing it needs the syntax binding compiled to XSLT
2.0, and PHP's `XSLTProcessor` is libxslt, which is XSLT 1.0 — so running the
official rule set is still PF-115's open work and this file does not close it.

The compiled artefact (`ubl/xslt/EN16931-UBL-validation.xslt`, 898 KB) was
read while implementing `App\Exchange\Validation\CategoryRules`, because the
abstract `.sch` states each rule in prose and only the compiled binding states
the **tolerance** the rule is actually evaluated with. It is deliberately not
committed: an artefact that cannot be run here would be a 900 KB file nothing
reads.

### `peppol/PEPPOL-EN16931-UBL.sch`

The **Peppol BIS Billing 3.0 CIUS rule set**, including the
`PEPPOL-EN16931-*`, `PEPPOL-COMMON-*` and national (`DE-R-*`, `DK-R-*`, …)
rules, and the `$eaid` code list that `PEPPOL-EN16931-CL008` tests against.

| | |
|---|---|
| Publisher | OpenPeppol AISBL |
| Repository | `https://github.com/OpenPEPPOL/peppol-bis-invoice-3` |
| Path | `rules/sch/PEPPOL-EN16931-UBL.sch` |
| Branch | `master`, fetched 2026-11-18 |
| Version named in the file's own header | `Last update: 2026 October hotfix 3.0.22` |
| Licence | the file's header states it reproduces CEN/EN 16931-1 business terms "with permission from CEN". No `LICENSE` file was found at the repository root at the paths probed, so no stronger claim is made here |
| sha256 | `f637444fab75a6da7e5004f94a6de8e71383ef5658dafe6c68925103b17ea148` |

Used for the same two purposes: the identifier completeness check, and as the
authority for `$eaid` — see below.

### `codelists/peppol-participant-identifier-schemes-v9.7.json`

The **Peppol eDEC Electronic Address Scheme (EAS) code list**: 105 entries,
each with its ISO/IEC 6523 four-digit code, its scheme id, its country, its
state (`active`, `deprecated`, `removed`) and whether it is registrable.

| | |
|---|---|
| Publisher | OpenPeppol AISBL, eDEC |
| Repository | `https://github.com/OpenPEPPOL/peppol-codelist` |
| Path | `publication/v9.7/Peppol Code Lists - Participant identifier schemes v9.7.json` |
| Branch | `master`, fetched 2026-11-18 |
| Version | **9.7**, as declared in the file's own `version` field |
| Also published at | `https://docs.peppol.eu/edelivery/codelists/` |
| sha256 | `69c9b351577219f3e7f108a56ab37a4f1a309721bf8820687389f5804727ec56` |

**The membership set is derived, not copied.** `PEPPOL-EN16931-CL008` is
evaluated against `$eaid` in the Schematron above — 83 codes — and this code
list holds 105. The difference is not a disagreement: every code in `$eaid` is
in the code list, and `$eaid` is exactly the codes whose `state` is `active`
**and** whose `registrable` is `true`. `App\Exchange\Profiles\Peppol\EasCodes`
derives the set that way and a check asserts the derivation reproduces
`$eaid` element for element, so neither file can be replaced without the other
noticing. Copying the 83 codes into PHP would have been one list nobody could
re-derive; deriving it from the published list means the two official
artefacts check each other.

A **deprecated** scheme is still a member. A document issued under a scheme
that was withdrawn afterwards was valid when it was issued and has to stay
readable, which is the same reason `ProfileRegistry` resolves a disabled
profile.

## What is NOT here, and why

**The UBL 2.1 and CII D16B XML Schemas.** `ValidationPipeline::schemaFor()`
looks for `resources/schemas/<syntax>/maindoc.xsd`, and the directory does not
exist, so stage 2 parses the produced bytes and reports itself as skipped —
PF-114's first criterion is open and stays open.

The official distributions are named by the CEF repository's own README:

- `http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.zip`
- `http://www.unece.org/fileadmin/DAM/cefact/xml_schemas/D16B_SCRDM__Subset__CII.zip`

Both hosts answer **403 at this environment's egress proxy** — a policy
denial, recorded in the proxy's own failure log, not a transport error. They
were not fetched and nothing resembling them has been written by hand.

A redistribution *is* reachable (`com.helger.ubl:ph-ubl21:10.3.0` on Maven
Central) and was deliberately not vendored. Its files are headed `OASIS
Universal Business Language (UBL) 2.1 **CS1**`, generated 2013-06-16, which is
the Committee Specification and not the OASIS Standard the CEF artefacts name;
and its `UBL-SignatureAggregateComponents-2.1.xsd` carries bare
`<xsd:import namespace="…"/>` elements for the W3C XML-Signature and ETSI
XAdES namespaces with no `schemaLocation` at all. Whether that is the original
or a patch cannot be settled without the OASIS bytes, and there is no second
independent copy reachable here to diff against. Vendoring it would have put
an unverifiable draft behind a criterion that says "UBL 2.1", and a schema
stage that validates against a draft reports failures the recipient's
validator does not — which is worse than a stage that says it could not run.

**The official Peppol and EN 16931 test corpora.** `OpenPEPPOL`'s own
`rules/unit-*` fixtures are addressed per Schematron rule rather than as
golden documents, and checking them means running XSLT 2.0, which this runtime
cannot. PF-120's corpus criterion and PF-128 stay open.
