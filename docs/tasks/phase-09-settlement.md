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

- [x] On allocation, the difference between the document's booked functional value and the payment's functional value is a **realized** gain or loss, posted to `fx_gain_loss`
- [x] The rate used is the one on the payment's **value date**, and it is recorded on the allocation
- [x] A partial allocation realizes a proportional difference, which is the case that is easy to get wrong
- [x] Distinct from the unrealized revaluation of `PF-070`, posted to a different account, and a test asserts the two never double-count
- [x] A worked three-currency example is committed as a fixture with expected postings to the cent

### PF-171 · Rounding differences
`Settlement` · **3 pts** · depends: PF-168 · standards: — · `type:posting`

- [x] A payment of 999.99 against 1,000.00 posts the 0.01 to `rounding_difference` as **its own journal line**
- [x] It does not quietly shrink the receivable - the receivable is closed and the difference is named
- [x] Tolerance is a per-entity setting with a platform-enforced maximum
- [x] Above tolerance it is not a rounding difference and the flow routes to classification instead

### PF-172 · Write-offs
`Settlement` · **5 pts** · depends: PF-168, PF-033 · standards: IFRS 9 · `risk:high` `type:posting` `type:security`

- [x] Permissioned, with a mandatory reason code, posting to `bad_debt`
- [x] Separated by SoD from both invoicing and reconciliation
- [x] Above a threshold it requires a second approver
- [x] A written-off document is closed, not deleted, and a later recovery is a new receipt against a recovery account - not an un-write-off
- [x] A write-off report by reason, period and customer

### PF-173 · Expected credit loss
`Settlement` · **5 pts** · depends: PF-172, PF-062 · standards: IFRS 9 · `type:posting`

- [x] A provision matrix: loss rate per aging bucket per risk grade, maintained as data
- [x] A periodic posting to `ecl_provision`, **reversed and recomputed** each period rather than accumulated
- [x] A **provision is an estimate and a write-off is a conclusion** - they live in different accounts and a test asserts a write-off releases the related provision rather than double-charging
- [x] A roll-forward report: opening provision, charge, release, utilization, closing

### PF-174 · The payment run
`Settlement` · **8 pts** · depends: PF-144, PF-136 · standards: — · `risk:high`

- [x] States: `draft` → `prepared` → `released`, with a defined set of allowed transitions and nothing else - as a trigger, so a single `UPDATE` cannot walk past it
- [x] Selection from approved payables by due date, supplier, entity, currency and discount opportunity, with a preview of the cash impact that writes nothing
- [x] Grouping per beneficiary where the beneficiary accepts it (`group_payments`), and splitting where a limit requires it, with `sequence`, `of` and the reason recorded
- [x] **Immutable once released**; a mistake is corrected by a new run and a recall request, never by editing - the header and both line tables are frozen against insert, update and delete
- [x] Early-settlement discounts evaluated and shown, frozen at preparation so release pays what was approved; an expired window is **not** taken and the item says why

### PF-175 · Maker-checker and step-up
`Settlement` · **5 pts** · depends: PF-174, PF-032, PF-033 · standards: OWASP ASVS 5.0, SOC 2 · `risk:high` `type:security`

The single highest-consequence control in the product.

- [x] The preparer and the releaser must be different users, enforced against the audit trail rather than against a role list - `prepared_by` is one column on one row, and a repaired row or a restored backup leaves the obvious check comparing against null and passing
- [x] Release requires **step-up re-authentication at the moment of the act**, so a stolen session is not enough. Asserted inside the service, because this method is reached from a console command, a queued job and an API endpoint that does not exist yet; and **object-scoped**, so re-proving yourself to release Tuesday's run does not authorise releasing Thursday's in the same five minutes
- [x] The releaser is shown exactly what changed since preparation, and any change invalidates the preparation. `beforeRelease()` answers before the button is pressed, because a release refused at the last step is one somebody has already decided to make
- [x] A beneficiary account changed since the payable was approved **blocks the release** until re-verified (`PF-061`) - and changed *and re-verified* still invalidates the preparation, because the colleague who verified it knew nothing of this pending run
- [x] Release is audited with the file hash, so the file that was released is identifiable afterwards - the hash is of the **instruction set** rather than of a file PF-176 has not generated yet, which proves what was authorised and is what the file must then match
- [x] This pair cannot be overridden by the SoD override mechanism, and granting one is refused outright
- [x] **Found while checking this task:** `release()` asserted the duty once for the run with no party id, so the party-scoped `beneficiary_then_release` pair - the one PF-061 exists for - could not be evaluated at all. The engine refused outright rather than skipping, which is right; nothing had noticed because no check had signed a real user in. The assertion now runs per distinct beneficiary, carrying both keys
- [x] **And:** "has the destination changed" was a clock comparison, and both timestamps are stored to the second, so an account changed in the same second as the preparation reported nothing. Each instruction now stores a fingerprint of the destination's content

### PF-176 · PAIN.001 generation
`Settlement` · **8 pts** · depends: PF-175 · standards: ISO 20022 PAIN.001.001.09, EPC SEPA SCT · `risk:high`

- [x] Payment instruction file with correct message, payment-information and transaction-level grouping. One `PmtInf` block per (debtor account, execution date, service level), which is what a run is - built as a block rather than flattened, so a run paying from two accounts is two blocks and nothing else changes. The element order comes from a declared sequence table and not from the control flow that emitted it, because a bank rejecting a misplaced element names the one it did not expect rather than the one out of place
- [x] **SEPA rulebook constraints validated before the file is produced**, not after it is rejected: reachability, the restricted Latin character set, the remittance limit, amount ceilings, execution-date rules. Every problem is reported at once with the beneficiary named, because throwing on the first means three rounds of fix-and-retry and the third is where somebody starts editing XML by hand
- [x] An Arabic beneficiary name must be transliterated for a SEPA payment, and **the user is shown exactly what will be sent** rather than having it silently mangled - as the name's consonants, declared lossy, with the original beside it, because Arabic does not write short vowels and a guessed one is a different name that the beneficiary's bank will not match
- [x] Non-SEPA schemes produce their own variants, declared by the bank profile. The scheme is on the **account**, because it is the bank that accepts the file - and there is deliberately no default: a file built to the wrong rulebook passes every check here and is rejected by the bank
- [x] The generated file is hashed, archived and downloadable once; regenerating produces a new file with a new hash and an audit entry. Both are kept and the superseded one points at its replacement with a reason, because "which file did we actually send" is the question an investigation starts with
- [x] **And:** the file carries the run's `released_hash`, so "is this the file for what was approved" is a string comparison rather than a reconstruction - and the database refuses a file whose hash is not the run's own
- [x] **Found while checking this task:** reading a beneficiary's IBAN needed `bank.manage`, which is the permission that lets somebody **change** supplier bank details - so requiring it to produce a file would have handed every treasury clerk the permission `beneficiary_then_release` exists to keep away from them. A control that forces the conflict it guards against is worse than no control; the file path asks for `payment.release` instead, under its own audit action

### PF-177 · Direct debit
`Settlement` · **5 pts** · depends: PF-176 · standards: ISO 20022 PAIN.008, EPC SEPA SDD

- [x] Mandate management: reference, signature date, scheme, sequence type, and the first-versus-recurring distinction. The sequence type is **derived** from the mandate's own collection history and is not a column anybody can set - a field would be edited, and the first edit produces a file the bank rejects at the scheme level
- [x] PAIN.008 collection files with the correct sequence type per mandate, since getting it wrong is a scheme-level rejection. The file groups by sequence type **as well as** date, because `SeqTp` sits on the block and a block holding both `FRST` and `RCUR` cannot say so - and a unique index makes a second `FRST` impossible even by direct insert
- [x] Pre-notification lead times enforced per scheme - and the notification has to cover *this* collection: collecting more than was notified is refused, because the notice promised a figure and the debtor's refund right runs for thirteen months either way. Less is allowed, since nobody disputes being charged less than they were told
- [x] Mandate cancellation and expiry stop collections rather than failing them at the bank - in the service and in a trigger, because a collection against a revoked mandate comes back days later after the customer has seen it on their statement
- [x] Returns and refunds ingested with the reason code retained, and a disputed-mandate code (`MD01`, `MD02`, `MD06`, `MD07`) cancels the mandate, because presenting again would be returned again and a creditor that does not read its returns is one whose identifier gets suspended. An insufficient balance deliberately does **not** cancel it: one short week should not end an arrangement somebody has to ask the customer to sign again
  - The **posting** half of this criterion was deferred to PF-179, which defines the in-transit account. PF-179 has landed, and the collection side posts through the same three rules - released, cleared, refused - with a collection's return taking the refused path.
- [x] **Found while checking this task:** the creditor's own IBAN was briefly in the debtor's account element - the two have the same shape in the schema, and a substitution landed on the wrong one. The file would have collected money from our own account. The check that walks the produced document caught it on the first run

### PF-178 · PAIN.002 status ingestion
`Settlement` · **5 pts** · depends: PF-176 · standards: ISO 20022 PAIN.002

- [x] Status reports ingested and applied down to the **individual instruction**, not just the batch. The group status is recorded beside the per-transaction statuses rather than instead of them: a product that read only the group would mark a partly-refused batch pending, or worse accepted, and a supplier would go unpaid with nothing in the system saying so
- [x] A rejected instruction reverses its in-transit posting and returns the payable to open, with the bank's reason code attached - posted for that instruction's own amount, because reversing the release entry would unwind the instructions the bank accepted and those are going to clear
- [x] A partially rejected batch is handled correctly - the accepted instructions stay accepted, and the in-transit position after ingestion equals exactly their total
- [x] Unmatched status reports are surfaced rather than discarded, with the three usual causes named: a file submitted twice, a reference the bank mangled, or a report for another company routed to us. A status naming an instruction we cannot find posts nothing, because there is no payable to return
- [x] **And:** the same report cannot be ingested twice. A bank that resends one is ordinary, and applying it again would reverse an in-transit posting that was already reversed - so the payable would come back owed twice, which the supplier discovers when they are paid twice

### PF-179 · In-transit and the three-way tie
`Settlement` · **5 pts** · depends: PF-178, PF-163 · standards: — · `risk:high` `type:posting`

- [x] On release, payables move from AP to `payments_in_transit`; the statement line clearing against the bank account closes the in-transit position. Crediting cash on release would report money the business still has as money it has spent, so every balance sheet between the release and the statement would be wrong - and the reconciliation would have a difference nobody could name, because the ledger would already think the payment happened
- [x] The in-transit account is what makes the tie between AP, the bank and the GL possible, and it ages. The clearing is **pulled** from the matches rather than pushed by them, because `Banking` may not depend on `Settlement` - which costs an explicit step and buys one that is idempotent and re-runnable, where a push that failed halfway leaves a position nobody can reconstruct
- [x] An aged in-transit item means a payment that left the system and never appeared on a statement, which is a question worth asking - so it is reported with **which** of the three causes it is: the file was never handed over, or it went and no status report was ingested, or the bank accepted it and the statement line was never matched. "Aged in transit" alone is a figure; what somebody needs is the question to ask
- [x] Asserted in `ledger:verify`: in-transit equals released-and-unreconciled, as a **critical** finding - a control account that does not reconcile to its own subsidiary record is the same class of fault as a receivables control disagreeing with the customer ledger
- [x] **Found while checking this task:** PF-174's freeze on a released run's lines blocked the columns that record what the bank said - so a released instruction could never learn its own outcome and the in-transit position could never close. The trigger now names the columns that define the payment and freezes those, letting the facts *about* it still be recorded: the same distinction `payment_files` already drew between its bytes and its download
- [x] **And:** the posting is dated on the pay date, which means a run whose pay date falls in a period nobody has opened cannot be released. That refusal is correct rather than inconvenient, and it names the period

### PF-180 · Duplicate payment detection — done
`Settlement` · **3 pts** · depends: PF-174, PF-139 · standards: — · `risk:high`

- [x] Before release: same beneficiary, same amount, within a configurable window, across **all** runs and all entities of the tenant
- [x] Also checks against already-released and already-reconciled payments, not only against open runs
- [x] A warning requires an explicit, recorded acknowledgement naming the person who accepted the risk
- [x] A test constructs the exact duplicate scenario across two runs and two entities and asserts detection

`payment_duplicate_warnings`, `peakflow_similar_payments()` ·
`App\Tenant\Settlement\Services\DuplicatePaymentDetector` ·
`bin/verify settlement`

**The duplicate that gets paid is in another entity.** A group shares
suppliers, the same invoice reaches two companies, each pays it, and
neither run looks wrong from inside its own entity — both bank accounts
reconcile perfectly and the supplier says nothing. Every other query in
this product is scoped to one entity by row policy, deliberately; this
one cannot be.

So the scan is a `SECURITY DEFINER` function, and a narrow one. It
returns the entity code, the run reference, the date and the days apart
— nothing else. The alternative, clearing the entity setting in
application code, hands the caller everything through a path that is one
refactor from being reused; a function with a fixed projection cannot
be. A check asserts the projection has not grown.

**A warning blocks the release and is accepted by a person, with a
sentence.** Not refused outright: businesses really do pay the same
amount to the same supplier twice in a week — a retainer, two identical
deliveries — and a product that refused would be worked around by
changing an amount by a penny, after which nothing is checked at all.

Four defects, all found by the bite sweep rather than reasoned about:

- **The function compared the wrong two columns.** `pr.entity_id <>
  i.entity_id` compares the found run's entity against the found
  instruction's, and those agree by construction — so a sister
  company's run came back labelled as one of our own and the whole
  cross-entity value was lost. The asking entity is a parameter now.
- **Re-examining raised accepted warnings again.** `release()` looks a
  second time, the re-scan found the same pair and inserted a fresh
  unacknowledged warning beside the accepted one, and the run could
  never be released — every look added one. Matching is on the finding
  now, not the row.
- **The migration file was edited and never applied**, so the function
  under test was not the one in the file. Found when a payments-branch
  check failed against a clause that was already correct on disk.
- **The two-entity fixture leaked the entity context.** It switched,
  the insert threw on a column it had not filled, and the switch back
  never ran — so every later check failed with "that bank account does
  not belong to this entity", which says nothing about what went wrong.

The SQL-level behaviours — draft runs excluded, reversed payments
excluded, the asking entity compared — are bitten by rewriting the
stored function and re-applying it, because biting the service file
cannot reach them.

### PF-181 · Statements and open items
`Settlement` · **3 pts** · depends: PF-168 · standards: —

- [ ] Customer and supplier statements in open-item and balance-forward form, in the recipient's language and the document currency
- [ ] Includes unapplied cash, credit balances and disputed items, each labelled - a statement that silently omits them invites a dispute
- [ ] Reconciles to the control account, and the report says so explicitly rather than leaving it implied
- [ ] Deliverable by email on a schedule, with a delivery log
