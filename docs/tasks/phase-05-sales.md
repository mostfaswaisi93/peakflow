# Phase 05 — Quotes, orders, invoices and credit notes

**21 tasks · 110 points**

The first phase that produces something a customer sees. It depends on the ledger and the tax engine being finished, because an invoice is a document **plus a posting plus a frozen tax treatment**, and building the document first produces a system where invoices exist and the books do not reconcile.

Two tasks in this phase carry disproportionate risk. `PF-093` is gapless numbering, where a gap is a finding in a tax audit. `PF-095` is the issuing transaction, which must be atomic across five separate concerns or leave the system in a state nobody can describe.

---

### PF-096 · The hash chain
`Sales` · **5 pts** · depends: PF-095 · standards: SHA-256, XML C14N, RFC 8785 · `risk:high` `type:compliance`

- [x] `canonical_hash`, `previous_hash` and `chain_index` per issued document, per series, with a CHECK that the three are present or absent together - a document with a hash and no position in the chain cannot be walked to
- [x] The hash covers the legally significant fields only, canonicalized, so a cosmetic change cannot invalidate a chain and a material one cannot avoid it. Asserted in both directions: the frozen bytes must contain the number, the totals, both parties, the lines, the breakdown and the tax decision, and must **not** contain the note, the timestamps, the presentation exponent or who issued it. A chain that breaks when somebody fixes a typo is a chain people learn to ignore
- [x] The canonical form is **stored**, not only hashed. A verifier holding a hash alone can say "this is not what it was", which is true and unhelpful; holding the bytes it can say the first field that differs, its old value and its new one
- [x] `invoices:verify-chain` re-walks a series and reports the **first** divergence, with the document and the field that differs. The checks are ordered deliberately: the index gap first, then the previous-hash link, then whether the stored bytes hash to the stored hash - the frozen bytes having been edited is a different and louder fact than the document having changed - and only then the field-by-field comparison
- [x] A document removed from the middle of a series is caught as a gap, not as a content mismatch. "Index 3 is missing" is the finding an auditor acts on; "document 4 does not match its bytes" is not
- [x] Documented clearly: the chain proves internal consistency, **not** authorship. Anyone who can write to this database can recompute a chain from a tampered document onward and the result verifies. A check asserts both the verifier and the command say so, because a system trusted for something it cannot do is worse than no system. The cryptographic seal is PF-124
- [ ] Runs nightly and alerts on divergence - the command is here and exits non-zero; the schedule belongs with the rest of the scheduled work in Phase 13

### PF-102 · Invoice PDF and PDF/A
`Sales` · **8 pts** · depends: PF-095 · standards: ISO 19005-3 (PDF/A-3) · `type:compliance`

- [ ] Rendering through Gotenberg from the print template, then Ghostscript conversion to PDF/A-3
- [ ] **veraPDF verifies conformance** and a non-conformant output fails the job rather than being delivered - a PDF that merely claims PDF/A is not an archive copy
- [ ] Fonts fully embedded, including the Arabic face, with correct text extraction and shaping - a PDF whose Arabic cannot be selected or searched is a scan with extra steps
- [ ] Bilingual layouts: an RTL document with Latin identifiers, bidi-isolated so an IBAN and an invoice number read in the right order
- [ ] Deterministic output: the same invoice renders byte-identically, so the archive hash is stable
- [ ] `PDF_DRIVER=stub` renders an HTML placeholder so a fresh clone works without the services

### PF-103 · The print stylesheet
`Sales` · **5 pts** · depends: PF-102 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] A4 and Letter, correct margins, page breaks that never split a table row or orphan a total
- [ ] Repeated table headers across pages, `page x of y`, and the entity and document identity on every page
- [ ] No interactive chrome, no colour-only meaning, and a `draft` watermark that is also a word
- [ ] Tested by rendering and diffing the PDF, not by eyeballing a browser preview
- [ ] The print view is what gets attached to an email, so it is the same artefact that is archived

### PF-104 · Delivery
`Sales` · **5 pts** · depends: PF-102, PF-063 · standards: —

- [x] Email with the PDF and, where the profile requires, the XML attached, rendered in the **recipient's** language
  - `EmailChannel` implements the same `MessageChannel` as WhatsApp, so the attempt numbering, the duplicate reference, the consent check and the delivery log are **one code path** rather than two. A second sending path would have its own answers to all four, and the first time they differed would be a customer with two copies of one invoice because only one path deduplicated.
  - `OutboundMessage` carries attachments as `name => path` rather than as contents: an invoice PDF is already on disk, and holding a second copy of each in memory is how a delivery run exhausts a worker on the one day somebody sends a thousand.
  - Every template carries both languages and a check asserts it. A channel with one language is a channel that silently sends the issuer's rather than the recipient's, and the bite removes `en` from a template to prove the check notices.
  - The templates are in configuration, not in the adapter, so adding a document type to the delivery path is a configuration change rather than a change to a provider's code.
- [ ] A delivery log per document: channel, address, attempt, outcome, provider message id
- [x] Bounce and complaint handling that marks the address and surfaces it, rather than silently retrying forever
  - A hard bounce means the mailbox does not exist. **Retrying does not eventually work** - it burns the sending domain's reputation, and the business discovers that when the invoices with the *right* addresses stop arriving. Silent and ever-widening is the worst shape a failure has.
  - So the address is suppressed, `isReachable()` refuses it from then on, and the refusal **names the address** - "surfaces it" means somebody reading the delivery log can see which one stopped, not that something did. Both halves are bitten.
  - **A bounce and a complaint are stored as different facts** because the remedies are opposite: a bounce is fixed by correcting the address, a complaint by asking the customer whether they want these at all - and certainly not by sending from a different address, which is how a domain goes from a complaint to a blocklist.
  - Soft bounces are deliberately *not* suppressed. A full mailbox is temporary, and suppressing on one would stop a customer's invoices because their inbox was full on a Tuesday; the transport's own retry handles those.
  - The list is per tenant: an address that bounced for one company may be fine for another that typed it correctly, and a shared list would let one tenant's typo stop another tenant's invoice.
- [ ] A signed customer-facing view with a payment instruction, and no login
- [ ] Delivery failure never changes the document's issued state - it is issued, and undelivered
