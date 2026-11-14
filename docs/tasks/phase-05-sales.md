# Phase 05 — Quotes, orders, invoices and credit notes

**21 tasks · 110 points**

The first phase that produces something a customer sees. It depends on the ledger and the tax engine being finished, because an invoice is a document **plus a posting plus a frozen tax treatment**, and building the document first produces a system where invoices exist and the books do not reconcile.

Two tasks in this phase carry disproportionate risk. `PF-093` is gapless numbering, where a gap is a finding in a tax audit. `PF-095` is the issuing transaction, which must be atomic across five separate concerns or leave the system in a state nobody can describe.

---

### PF-089 · Quotes
`Sales` · **5 pts** · depends: PF-065, PF-082 · standards: —

- [ ] Draft, send, accept, decline, expire, with a validity date and automatic expiry
- [ ] Versioning: a revised quote is a new version with the previous one retained and comparable
- [ ] No posting and no number from a legal series - a quote is not an accounting document
- [ ] Accepting produces an order, or an invoice directly where the tenant has no order step
- [ ] A customer-facing view by signed link, with accept and decline, and no login

### PF-090 · Sales orders
`Sales` · **5 pts** · depends: PF-089 · standards: —

- [ ] Order from a quote or from scratch; confirmed orders show against the party's credit exposure
- [ ] **Partial invoicing**: an order may be invoiced across several invoices, with the uninvoiced balance tracked per line and not merely per order
- [ ] Over-invoicing an order is refused; cancelling an order with invoiced lines is refused with an explanation
- [ ] Optionally posts a commitment for budget visibility, never a financial posting

### PF-091 · Invoice schema with a frozen snapshot
`Sales` · **8 pts** · depends: PF-082, PF-071 · standards: EN 16931 · `risk:high` `type:schema`

- [ ] `invoices`, `invoice_lines`, `invoice_tax_breakdown`, `invoice_allowances_charges`, `invoice_references`
- [ ] The document type is a UNCL 1001 code, stored as the code
- [ ] **Frozen at issue**: both parties' legal names, addresses and tax registrations; the entity's own legal identity; every applied tax rate; the FX rate; the payment terms; the derived due dates. An issued invoice is a historical statement and carries its own copies rather than foreign keys to things that can change
- [ ] A line carries the item reference *and* its own description, so changing an item's name later does not rewrite history
- [ ] Totals stored at the amount scale, with the presentation exponent recorded separately
- [ ] A test changes a party's name and a tax rate after issue, and asserts the issued invoice is unchanged in every respect

### PF-092 · Draft editing and validation
`Sales` · **5 pts** · depends: PF-091 · standards: EN 16931

- [ ] A draft is freely editable, has no number, no posting and no effect on any balance
- [ ] Validation runs continuously and shows **what would prevent issuing**, addressed by field, rather than failing at the end
- [ ] The internal rule set: totals consistent, party has a registration where the tax treatment requires one, period open, currency enabled, evidence present for the tax category, place-of-supply rule resolved
- [ ] A draft can be duplicated, and duplicating never copies the number or the posting

### PF-093 · Gapless number allocation
`Sales` · **8 pts** · depends: PF-071 · standards: — · `risk:high`

- [ ] Allocation by `SELECT ... FOR UPDATE` on the `number_series` row inside the issuing transaction
- [ ] A comment in the code records why a PostgreSQL `SEQUENCE` cannot be used: `nextval()` is deliberately non-transactional, so a rollback does not return the number and every validation failure, deadlock and crash leaves a gap
- [ ] **Allocate last** - after validation, tax computation and the posting rule have all succeeded - so the series lock is held for as short a time as possible
- [ ] **No I/O inside the lock**: no PDF render, no clearance call, no mail. A tax authority that takes thirty seconds to answer must not hold the invoice sequence for thirty seconds
- [ ] Lock scope is one series, so two entities or two document types never contend

### PF-094 · The gapless concurrency proof
`Sales` · **5 pts** · depends: PF-093 · standards: — · `risk:high`

The single most important test in the suite, and the one that fails if someone "optimizes" the allocation by moving it earlier.

- [ ] 200 invoices issued from 20 parallel workers against one series; the resulting numbers are exactly `1..200` with no gaps and no duplicates
- [ ] A variant where a third of the transactions fail validation after the lock point, asserting the successful ones remain gapless
- [ ] A variant with two series under concurrent load, asserting they do not block each other
- [ ] A variant asserting a crashed worker does not consume a number
- [ ] Runs in the `concurrency` suite against real PostgreSQL

### PF-095 · The issuing transaction
`Sales` · **8 pts** · depends: PF-093, PF-047, PF-009 · standards: — · `risk:high` `type:posting`

Five concerns, one transaction. It either all happens or none of it does.

- [ ] In order: validate, compute and freeze totals, apply the posting rule, allocate the number, write the hash-chain link, commit - then dispatch the post-commit jobs
- [ ] The journal posting is synchronous and inside the transaction; the PDF render, the delivery and the clearance submission are queued after commit
- [ ] A failure at any point leaves no number consumed, no posting and no document in an issued state
- [ ] The issued event carries enough for every downstream consumer without re-reading, so a consumer cannot observe a half-written document
- [ ] A test injects a failure at each of the five steps and asserts the system state afterwards in each case

### PF-096 · The hash chain
`Sales` · **5 pts** · depends: PF-095 · standards: SHA-256, XML C14N, RFC 8785 · `risk:high` `type:compliance`

- [ ] `canonical_hash`, `previous_hash` and `chain_index` per issued document, per series
- [ ] The hash covers the legally significant fields only, canonicalized, so a cosmetic change cannot invalidate a chain and a material one cannot avoid it
- [ ] `invoices:verify-chain` re-walks a series and reports the **first** divergence, with the document and the field that differs
- [ ] Runs nightly and alerts on divergence
- [ ] Documented clearly: the chain proves internal consistency, **not** authorship. A cryptographic seal is a separate step (`PF-124`)

### PF-097 · Credit notes
`Sales` · **5 pts** · depends: PF-095 · standards: UNCL 1001 (`381`) · `type:posting`

- [ ] Full or partial credit against an issued invoice, line-selectable, with its own number from its own series
- [ ] Carries a mandatory reference to the invoice it credits (`BT-25`), and the invoice shows its credits
- [ ] Cannot credit more than the invoice's remaining uncredited value, enforced in the database
- [ ] Reverses the original posting through a posting rule, not by negating rows
- [ ] SoD: a user cannot credit an invoice they issued (`PF-033`)

### PF-098 · Debit notes and corrected invoices
`Sales` · **3 pts** · depends: PF-097 · standards: UNCL 1001 (`383`, `384`) · `type:compliance`

- [ ] Debit note (`383`) for an additional charge against an existing invoice
- [ ] Corrected invoice (`384`) where a jurisdiction requires a replacement rather than a credit-and-reissue, with a mandatory reference to what it corrects
- [ ] Which mechanism is legal is a **jurisdiction profile decision**, not a user choice, and the UI offers only what the profile permits

### PF-099 · Prepayment invoices
`Sales` · **5 pts** · depends: PF-095 · standards: UNCL 1001 (`386`), IFRS 15 · `type:posting`

- [ ] A prepayment invoice posts to a **liability**, not to revenue - cash received before performance is not revenue
- [ ] Settled against a later final invoice, which shows the prepaid amount as a deduction and carries the EN 16931 prepaid-amount term (`BT-113`)
- [ ] Tax on a prepayment follows the jurisdiction's rule, which is frequently "tax now", and that is a profile decision
- [ ] A test takes a prepayment, issues a final invoice, and proves revenue, the liability and the receivable all end correct

### PF-100 · Self-billed invoices
`Sales` · **3 pts** · depends: PF-095 · standards: UNCL 1001 (`389`) · `type:compliance`

- [ ] The buyer issues on the supplier's behalf under an agreement, which must be recorded on the party before the type is available
- [ ] Numbered from its own series and clearly marked as self-billed on the document
- [ ] Posts as a purchase, not a sale, which is the part that is easy to get backwards

### PF-101 · Simplified and B2C invoices
`Sales` · **3 pts** · depends: PF-095 · standards: EN 16931, jurisdiction profiles · `type:compliance`

- [ ] A simplified variant with a reduced mandatory field set where a jurisdiction permits one, subject to a value threshold the profile declares
- [ ] A B2C document omits the customer registration and carries different place-of-supply treatment for digital services
- [ ] The threshold and the permitted field set are profile data; exceeding the threshold **upgrades the document type** rather than issuing an invalid simplified one

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

- [ ] Email with the PDF and, where the profile requires, the XML attached, rendered in the **recipient's** language
- [ ] A delivery log per document: channel, address, attempt, outcome, provider message id
- [ ] Bounce and complaint handling that marks the address and surfaces it, rather than silently retrying forever
- [ ] A signed customer-facing view with a payment instruction, and no login
- [ ] Delivery failure never changes the document's issued state - it is issued, and undelivered

### PF-105 · Recurring schedules
`Sales` · **5 pts** · depends: PF-095 · standards: RFC 5545

- [ ] A template plus an `RRULE`, so "the last working day of every quarter" is expressible rather than approximated
- [ ] Proration rules for mid-period starts and stops, with the proration basis visible on the generated line
- [ ] End conditions: a date, an occurrence count, or open-ended
- [ ] A **dry-run preview** showing the next twelve occurrences with their amounts and dates before anything is activated
- [ ] Price changes on the underlying items are picked up by effective date, and the schedule records which price list it resolves against

### PF-106 · Recurring generation idempotency
`Sales` · **3 pts** · depends: PF-105 · standards: — · `risk:high`

- [ ] Generation is idempotent on `(schedule_id, occurrence_date)` - a scheduler that fires twice cannot bill twice
- [ ] A Redis lock per `(task, tenant, occurrence)`, so a two-node scheduler cannot double-bill
- [ ] Runs in the **tenant's own timezone**: "daily at 01:00" means 01:00 where the business is, and a tenant in Riyadh and one in Casablanca do not share a moment
- [ ] A failed occurrence is retried without skipping the next, and a permanently failed one is surfaced rather than retried forever
- [ ] A test fires the same occurrence from two workers simultaneously and asserts exactly one invoice

### PF-107 · Revenue recognition
`Sales` · **8 pts** · depends: PF-099, PF-052 · standards: IFRS 15 · `risk:high` `type:posting`

- [ ] A recognition schedule per line: immediate, straight-line over a service period, on milestones, or on delivery
- [ ] Issuing posts to `deferred_revenue`; the recognition run releases to `revenue` on schedule
- [ ] `revenue:recognize {period}` posts one entry per period, is re-runnable without double-posting, and reports what it recognized and what it deferred
- [ ] A credit note against a partially recognized invoice unwinds the remaining deferral correctly, which is the case that breaks naive implementations
- [ ] A deferred revenue roll-forward report: opening, additions, recognized, closing - reconciling to the control account

### PF-108 · Dunning policies
`Sales` · **5 pts** · depends: PF-066, PF-062 · standards: —

- [ ] A policy is a sequence of steps, each with a days-past-due offset, a channel, a template and optional actions
- [ ] **Evaluated per invoice, sent per customer**: a customer with six overdue invoices gets one statement, not six emails
- [ ] A disputed invoice stops escalating and appears on a dispute report instead
- [ ] Policies are assignable per party and per party group, with a tenant default
- [ ] `dunning:run` is idempotent per `(invoice, step, day)` and respects the tenant's timezone and business calendar

### PF-109 · Late fees, credit hold and collections
`Sales` · **3 pts** · depends: PF-108 · standards: — · `type:posting`

- [ ] A late fee as a dunning action, posted as its own document so it is creditable and disputable
- [ ] Credit hold applied and released automatically by policy, with a manual override that is permissioned and audited
- [ ] A collections owner per party, a promise-to-pay date, and a collections worklist ordered by value at risk
- [ ] A fee is never applied twice for the same period, and the fee basis is shown on the document
