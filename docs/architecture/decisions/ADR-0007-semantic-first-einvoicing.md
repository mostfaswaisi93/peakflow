# ADR-0007 — One semantic model, many syntaxes

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-110, PF-112, PF-113, PF-118

## Context

PeakFlow must issue legally valid invoices in several jurisdictions. A tenant selling into the EU and to Saudi Arabia needs Peppol BIS Billing 3.0 and ZATCA's UBL subset, which disagree about namespaces, element sets, identifier schemes, code lists, validation rules, transport, sealing and QR encoding.

They agree about almost everything that matters: an invoice has a number, an issue date, two parties with tax registrations, lines with quantities and prices, a tax breakdown per rate, and a set of totals that must reconcile.

## Decision

**One internal semantic invoice model aligned to EN 16931**, addressed by business terms (`BT-*`) and business groups (`BG-*`). Serializers translate that model into each required syntax. Everything jurisdiction-specific lives behind a `JurisdictionProfile`.

```php
interface JurisdictionProfile
{
    public function documentTypes(): array;            // permitted UNCL 1001 codes
    public function numberingRules(): NumberingRules;
    public function taxEngine(): TaxRuleSet;
    public function roundingPolicy(): RoundingPolicy;
    public function serializer(): DocumentSerializer;
    public function validators(): array;               // ordered chain
    public function transport(): ?Transport;
    public function sealing(): ?SealingPolicy;
    public function archivePolicy(): ArchivePolicy;
    public function qrPolicy(): ?QrPolicy;
}
```

**Adding a country is a new profile class plus fixtures, and never a change to `Sales`.** That claim is testable, and it is tested: the diff surface of adding a profile is measured, and Deptrac forbids `Sales` from depending on `Exchange`.

EN 16931 was chosen as the semantic anchor rather than inventing one because it is a published standard with a numbered term for every concept, a formal rule set, an official test corpus, and it is already the basis of Peppol, Factur-X, and most national CIUS profiles - including ones that do not advertise the fact.

## Alternatives considered

**Build for the first jurisdiction, generalize when the second arrives.** The fastest path to a shipped invoice, and the reason most products end up with two invoice systems. The generalization never happens as a refactor; it happens as a parallel implementation, because by then the first one has customers and cannot be changed. The second jurisdiction then costs what the first one did, and the third costs twice.

**A per-jurisdiction document model, with shared value objects.** Avoids forcing one shape onto different legal regimes. Rejected because the shapes are 90% identical, and the 10% is expressible as profile data. Three models would mean three tax computations and three total calculations - which is three places for `BR-CO-14` to be subtly wrong.

**UBL as the internal model.** Tempting: the primary output format, already a complete model, with schemas to validate against. Rejected because UBL is a *syntax*. Its element ordering, its `schemeID` plumbing and its optionality are serialization concerns, and letting them into the domain means the domain carries XML's shape. It also makes the CII serializer a syntax-to-syntax translation, which is strictly harder than model-to-syntax.

**A third-party e-invoicing service for everything.** Would remove the whole phase. Rejected because the semantic model, the hash chain and the archive are the parts that must not be outsourced: the archive is the legal record, the chain is the tamper evidence, and a provider's outage must not stop a business issuing invoices. Third-party access points *are* used for Peppol transport, which is the part where being a network participant is the whole job.

## Consequences

**Easy:** a second jurisdiction is a profile; a shared validation pipeline and a shared golden-file corpus catch regressions across all of them; the tax engine and the totals exist once; the API's JSON shape is another serialization of the same model, so it cannot drift from what gets filed.

**Hard:** the semantic model must be built before any jurisdiction ships, so the first invoice takes longer; EN 16931's conditional cardinality is intricate and must be expressed once in the model rather than per serializer; a jurisdiction that genuinely does not fit - an entirely different legal concept of an invoice - would need a real extension point rather than a profile.

**Ruled out:** a quick tactical fix for one country that bypasses the model. There is no shortcut path, and that is the point.

## Revisit when

A required jurisdiction cannot be expressed as a profile. The likely shape of that is a regime whose document is not an invoice in the EN 16931 sense - a real-time transaction report rather than a document. That is an additional model alongside this one, not a replacement.
