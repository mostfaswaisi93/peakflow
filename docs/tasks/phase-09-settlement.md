# Phase 09 — Payments, allocation, FX and payment runs

**16 tasks · 81 points**

A **payment** is cash moving. An **allocation** is the act of saying which obligation it settles. They are separate objects in PeakFlow because they are separate events, often days apart and performed by different people - and forcing an allocation at receipt time is how payments get applied to the wrong invoice to make a screen close.

This phase also contains the single highest-consequence control in the product: the maker-checker boundary on releasing money.

---

### PF-166 · Payments
`Settlement` · **5 pts** · depends: PF-146, PF-047 · standards: UNCL 4461 · `type:schema` `type:posting`

- [x] Receipts and disbursements, each with a method coded per UNCL 4461, a bank or cash account, a value date, a currency and an amount
- [x] Posts against the bank or cash control account and a clearing position, never directly against a party balance - the party balance is a consequence of allocation
- [x] A payment in a currency other than the account's is refused rather than silently converted
- [x] Immutable once posted; corrected by reversal

### PF-167 · Unapplied cash
`Settlement` · **3 pts** · depends: PF-166 · standards: — · `type:posting`

- [x] Cash received with no allocation is a **liability** in `unapplied_cash`, and that is correct rather than an error state
- [x] Unapplied cash ages and is reported, because old unapplied cash usually means a misidentified payer
- [x] It appears on the customer's statement, so the customer can tell you what it was for
- [x] Clearing it is an allocation, never an adjustment

### PF-168 · Allocations
`Settlement` · **8 pts** · depends: PF-167 · standards: — · `risk:high` `type:posting`

- [x] Partial, across many documents, in either direction
- [x] An allocation cannot exceed either the payment's unallocated amount or the document's open balance - enforced in the database, because this is the invariant that keeps AR equal to its control account
- [x] Reversal is a new posting, not a delete, and re-allocation afterwards is normal
- [x] Automatic allocation strategies - oldest first, by reference, by explicit instruction - each inspectable and overridable
- [x] A property-based test: for any sequence of allocations and reversals, the document's open balance and the control account agree

### PF-169 · Over-payment, credits and refunds
`Settlement` · **5 pts** · depends: PF-168 · standards: — · `type:posting`

- [x] Over-payment becomes a customer credit balance, usable against future documents
- [x] A refund is its own document and its own payment, so it is traceable rather than being a negative receipt
- [x] A credit balance appears on statements and in the aging report, as a negative bucket rather than being netted invisibly
- [x] Refunding more than the credit balance is refused

### PF-170 · Realized FX
`Settlement` · **5 pts** · depends: PF-168, PF-068 · standards: IAS 21 · `risk:high` `type:posting`

- [ ] On allocation, the difference between the document's booked functional value and the payment's functional value is a **realized** gain or loss, posted to `fx_gain_loss`
- [ ] The rate used is the one on the payment's **value date**, and it is recorded on the allocation
- [ ] A partial allocation realizes a proportional difference, which is the case that is easy to get wrong
- [ ] Distinct from the unrealized revaluation of `PF-070`, posted to a different account, and a test asserts the two never double-count
- [ ] A worked three-currency example is committed as a fixture with expected postings to the cent

### PF-171 · Rounding differences
`Settlement` · **3 pts** · depends: PF-168 · standards: — · `type:posting`

- [ ] A payment of 999.99 against 1,000.00 posts the 0.01 to `rounding_difference` as **its own journal line**
- [ ] It does not quietly shrink the receivable - the receivable is closed and the difference is named
- [ ] Tolerance is a per-entity setting with a platform-enforced maximum
- [ ] Above tolerance it is not a rounding difference and the flow routes to classification instead

### PF-172 · Write-offs
`Settlement` · **5 pts** · depends: PF-168, PF-033 · standards: IFRS 9 · `risk:high` `type:posting` `type:security`

- [ ] Permissioned, with a mandatory reason code, posting to `bad_debt`
- [ ] Separated by SoD from both invoicing and reconciliation
- [ ] Above a threshold it requires a second approver
- [ ] A written-off document is closed, not deleted, and a later recovery is a new receipt against a recovery account - not an un-write-off
- [ ] A write-off report by reason, period and customer

### PF-173 · Expected credit loss
`Settlement` · **5 pts** · depends: PF-172, PF-062 · standards: IFRS 9 · `type:posting`

- [ ] A provision matrix: loss rate per aging bucket per risk grade, maintained as data
- [ ] A periodic posting to `ecl_provision`, **reversed and recomputed** each period rather than accumulated
- [ ] A **provision is an estimate and a write-off is a conclusion** - they live in different accounts and a test asserts a write-off releases the related provision rather than double-charging
- [ ] A roll-forward report: opening provision, charge, release, utilization, closing

### PF-174 · The payment run
`Settlement` · **8 pts** · depends: PF-144, PF-136 · standards: — · `risk:high`

- [ ] States: `draft` → `prepared` → `released`, with a defined set of allowed transitions and nothing else
- [ ] Selection from approved payables by due date, supplier, entity, currency and discount opportunity, with a preview of the cash impact
- [ ] Grouping per beneficiary where the beneficiary accepts it, and splitting where a limit requires it
- [ ] **Immutable once released**; a mistake is corrected by a new run and a recall request, never by editing
- [ ] Early-settlement discounts evaluated and shown, since taking them is usually the run's whole purpose

### PF-175 · Maker-checker and step-up
`Settlement` · **5 pts** · depends: PF-174, PF-032, PF-033 · standards: OWASP ASVS 5.0, SOC 2 · `risk:high` `type:security`

The single highest-consequence control in the product.

- [ ] The preparer and the releaser must be different users, enforced against the audit trail rather than against a role list
- [ ] Release requires **step-up re-authentication at the moment of the act**, so a stolen session is not enough
- [ ] The releaser is shown exactly what changed since preparation, and any change invalidates the preparation
- [ ] A beneficiary account changed since the payable was approved **blocks the release** until re-verified (`PF-061`)
- [ ] Release is audited with the file hash, so the file that was released is identifiable afterwards
- [ ] This pair cannot be overridden by the SoD override mechanism

### PF-176 · PAIN.001 generation
`Settlement` · **8 pts** · depends: PF-175 · standards: ISO 20022 PAIN.001.001.09, EPC SEPA SCT · `risk:high`

- [ ] Payment instruction file with correct message, payment-information and transaction-level grouping
- [ ] **SEPA rulebook constraints validated before the file is produced**, not after it is rejected: IBAN reachability, the restricted Latin character set, the 140-character remittance limit, amount ceilings, execution-date rules
- [ ] An Arabic beneficiary name must be transliterated for a SEPA payment, and **the user is shown exactly what will be sent** rather than having it silently mangled
- [ ] Non-SEPA schemes produce their own variants, declared by the bank profile
- [ ] The generated file is hashed, archived and downloadable once; regenerating produces a new file with a new hash and an audit entry

### PF-177 · Direct debit
`Settlement` · **5 pts** · depends: PF-176 · standards: ISO 20022 PAIN.008, EPC SEPA SDD

- [ ] Mandate management: reference, signature date, scheme, sequence type, and the first-versus-recurring distinction
- [ ] PAIN.008 collection files with the correct sequence type per mandate, since getting it wrong is a scheme-level rejection
- [ ] Pre-notification lead times enforced per scheme
- [ ] Mandate cancellation and expiry stop collections rather than failing them at the bank
- [ ] Returns and refunds ingested and posted, with the reason code retained

### PF-178 · PAIN.002 status ingestion
`Settlement` · **5 pts** · depends: PF-176 · standards: ISO 20022 PAIN.002

- [ ] Status reports ingested and applied down to the **individual instruction**, not just the batch
- [ ] A rejected instruction reverses its in-transit posting and returns the payable to open, with the bank's reason code attached
- [ ] A partially rejected batch is handled correctly - the accepted instructions stay accepted
- [ ] Unmatched status reports are surfaced rather than discarded

### PF-179 · In-transit and the three-way tie
`Settlement` · **5 pts** · depends: PF-178, PF-163 · standards: — · `risk:high` `type:posting`

- [ ] On release, payables move from AP to `payments_in_transit`; the statement line clearing against the bank account closes the in-transit position
- [ ] The in-transit account is what makes the tie between AP, the bank and the GL possible, and it ages
- [ ] An aged in-transit item means a payment that left the system and never appeared on a statement, which is a question worth asking - so it is reported and alerted
- [ ] Asserted in `ledger:verify`: in-transit equals released-and-unreconciled

### PF-180 · Duplicate payment detection
`Settlement` · **3 pts** · depends: PF-174, PF-139 · standards: — · `risk:high`

- [ ] Before release: same beneficiary, same amount, within a configurable window, across **all** runs and all entities of the tenant
- [ ] Also checks against already-released and already-reconciled payments, not only against open runs
- [ ] A warning requires an explicit, recorded acknowledgement naming the person who accepted the risk
- [ ] A test constructs the exact duplicate scenario across two runs and two entities and asserts detection

### PF-181 · Statements and open items
`Settlement` · **3 pts** · depends: PF-168 · standards: —

- [ ] Customer and supplier statements in open-item and balance-forward form, in the recipient's language and the document currency
- [ ] Includes unapplied cash, credit balances and disputed items, each labelled - a statement that silently omits them invites a dispute
- [ ] Reconciles to the control account, and the report says so explicitly rather than leaving it implied
- [ ] Deliverable by email on a schedule, with a delivery log
