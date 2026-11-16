# Phase 07 — Purchase orders, bills, approvals and three-way match

**15 tasks · 67 points**

The payable side. Structurally the mirror of `Sales`, with two things sales does not have: **documents that arrive from outside** and must be parsed rather than composed, and **approval**, because money leaving needs a second person in a way money arriving does not.

The expensive failure here is paying the same invoice twice, and the dangerous one is paying the right invoice to the wrong account. Both get dedicated tasks.

---

### PF-130 · Purchase requisitions
`Purchasing` · **3 pts** · depends: PF-026 · standards: —

- [ ] An internal request before a commitment: requester, cost centre, justification, estimated value
- [ ] Routed for approval by the same engine as bills, so there is one approval mechanism and not two
- [ ] Optional per entity - a small tenant should not be forced through a requisition step
- [ ] Approved requisitions convert to purchase orders, carrying their dimensions forward

### PF-131 · Purchase orders
`Purchasing` · **5 pts** · depends: PF-130, PF-064 · standards: —

- [ ] Supplier, lines with item, quantity, unit price, tax code, delivery date, dimensions
- [ ] Sent to the supplier as a PDF, and by Peppol where the supplier is reachable
- [ ] A commitment is tracked per line for budget visibility, and is never a financial posting
- [ ] Partially received and partially billed orders are tracked **per line**, not per order
- [ ] Closing an order with uninvoiced receipts is refused with an explanation

### PF-132 · Goods and service receipts
`Purchasing` · **3 pts** · depends: PF-131 · standards: — · `type:posting`

- [ ] Receipt against a purchase order, full or partial, with over- and under-delivery tolerances per supplier
- [ ] Optionally posts a **goods-received-not-invoiced accrual**, which is what makes a period-end cutoff honest
- [ ] The accrual is reversed by the bill that matches it, and an aged accrual report surfaces receipts never billed
- [ ] A receipt for a service is a confirmation of performance, which is what a three-way match needs for a service line

### PF-133 · Bills
`Purchasing` · **5 pts** · depends: PF-082, PF-047 · standards: EN 16931 · `type:posting` `type:schema`

- [x] Supplier invoice with lines, tax breakdown, the supplier's own document number and date, and the derived due date
- [x] **Three dates, and they are not interchangeable.** The supplier's document date is theirs and is what their statement says; the tax point is the jurisdiction's and decides which return the input tax falls in; the posting date is ours and decides the period. A bill dated the 28th of March that arrives on the 3rd of April belongs to the supplier's March and our April, and a system with one date gets two of the three wrong. `Bill` deliberately has **no** `date()` accessor: a method that picked one would be read by whoever came next as "the date of the bill"
- [x] A tax point after the posting date is refused, because it would claim input tax in a return that has not opened. The other direction is normal and is the reason the two dates exist
- [x] The payment terms are frozen and **counted from the supplier's date**, not ours. "Thirty days" means thirty days from their invoice date, which is what they will chase on - counting from our posting date would quietly extend every bill that arrived late, and the supplier would be right
- [x] Posts accounts payable, input tax, and expense or asset - per **line**, because one bill routinely carries a repair and a machine and a document-level choice forces the business to split a supplier's invoice for an accounting reason
- [x] With the **reverse-charge case posting both input and output tax**, which is the half most systems forget. The supplier charges nothing and the recipient accounts for both sides: an output because they owe it, an input because they may claim it. A system that posts only the input side files a return whose input box is right and whose output box understates the liability by exactly that amount - and because the two net to nothing in the accounts, nothing looks wrong until the authority compares the return against the supplier's own declaration
- [x] And reverse-charge tax is **not part of the payable**. The supplier charged nothing: they are owed the net. A gross that included it would overpay them by the tax and leave the authority's side unpaid
- [x] Non-recoverable input tax posts to expense rather than to the input account, keeping the input account reconcilable to the return - and to the **line's own** expense account, because the cost of a purchase includes the tax that cannot be recovered and it belongs to the cost centre that bought it. Recovery is a percentage rather than a flag, because partial recovery is the normal case for entertainment, fuel and mixed-use assets
- [x] The rate group is keyed by category, rate **and** the reverse-charge flag: the same rate under the reverse charge is a different box on the return, and merging the two would make the return's two sides indistinguishable
- [x] Found on the way: `journal_lines` refuses a tax point with no tax code, which is right - a tax point that cannot be attributed to a code is a figure in a return nobody can trace - so the bill's rate group carries the code, and the posting passes both or neither
- [ ] A bill in a foreign currency books at the rate on its tax point date - the columns are there and the rate is not yet fetched; PF-068's rate service is wired on the sales side and this call site arrives with PF-138, which needs it for the approval limit test as well

### PF-134 · Supplier credit notes
`Purchasing` · **3 pts** · depends: PF-133 · standards: UNCL 1001 (`381`) · `type:posting`

- [ ] Full or partial credit against a bill, with a mandatory reference
- [ ] Cannot exceed the bill's remaining uncredited value
- [ ] Allocatable against other open bills from the same supplier, or refundable
- [ ] Reverses through a posting rule, not by negating rows

### PF-135 · Three-way match
`Purchasing` · **8 pts** · depends: PF-133, PF-132 · standards: — · `risk:high`

- [ ] Bill against purchase order against receipt, on **quantity and price**, per line
- [ ] Tolerances per supplier and per line type, in both absolute and percentage terms, with a platform ceiling
- [ ] A mismatch routes to an exception queue **naming the specific discrepancy** - "billed 120 units, received 100" - rather than a generic hold
- [ ] Matching handles partial receipts, partial bills, and one bill spanning several orders
- [ ] A matched bill records what it matched against, so the match is auditable afterwards

### PF-136 · The approval engine
`Purchasing` · **8 pts** · depends: PF-133, PF-033 · standards: SOC 2 · `risk:high` `type:security`

- [x] Rules on amount, account, dimension, supplier and document type, evaluated into an ordered chain of approvers. Every condition is nullable and an absent one matches everything, so a rule with none is "every document" - which is what a small tenant's single rule looks like and should not need four fields set to express
- [x] The chain is **compiled once and written down**, not re-evaluated per decision. A rule changed halfway through would move the goalposts under the people already in it, and nobody could answer "who was supposed to approve this" about a document from last month. A check changes a rule mid-chain and requires the approver who was assigned to still be able to act
- [x] Sequential and parallel steps; a quorum where a policy requires two of three. Steps sharing a `sequence` open together and the chain advances only when all of them are satisfied - a chain that could express only "all" or "any" would make the business pick the wrong one
- [x] And a quorum of two cannot be met by **one person pressing approve twice**, which is the obvious way to clear a step alone. The unique index on `(step, user)` is the guarantee; the refusal is the message
- [x] SoD enforced: a user cannot approve a bill they created (`PF-033`), and the refusal **names who can** - "you cannot do this" sends somebody to ask around, and on a purchase the person they ask is frequently the one who created it. Enforced twice: the requester is refused directly, and the SoD engine catches the person who created the bill through another route
- [x] An approval records the exact document state it approved - a bill edited after approval **returns to the start of the chain**, and this is the hole that matters. Approved at 1,000, changed to 100,000, paid: every system that stores approval as a boolean has it, and it is invisible because the document looks approved and is. The request carries a canonical hash of the document when the chain started, each decision records what its approver saw, and a stale request is marked stale rather than updated - rewriting its fingerprint would make the earlier decisions look like decisions about the new version
- [x] To the **start**, not to the step it was on: the earlier approvers also approved something else
- [x] And the fingerprint covers what an approver was deciding about - the supplier, the amounts, the dates and the lines - not every column. A chain that restarted when somebody added an internal note would make approval a thing people work around, and what they stop doing is writing notes. A check adds one and requires the chain to survive
- [x] Through the RFC 8785 canonicalizer rather than `json_encode`, so a document whose columns come back in a different order hashes the same. Without that a chain would go stale on a document nobody touched, which is worse than not checking at all
- [x] Every decision is audited with actor, time, rule and comment, and a rejection needs a reason - an approval may be silent, because the chain is the reason, and a rejection that is not returns the document to somebody with no idea what to change
- [x] Found on the way: the audit actions were passed through a shared helper, so `SodCoverageRules` could not find them and reported the `bill.approve` duty as unmapped. It was right to: the check greps for `action: '...'` because a typo in that string disables a control silently, which is the one failure mode a control must not have. The two actions are now literals at their call sites, and `bill.create` and `bill.approve` are no longer declared gaps
- [x] An overdue step is reported for escalation, which PF-137 routes

### PF-137 · Delegation and continuity
`Purchasing` · **3 pts** · depends: PF-136 · standards: —

- [x] Delegation for a date range, with the delegate **recorded on the approval** rather than silently substituted. The naive implementation writes the absent person's id on the decision, which is a forged approval: the record says somebody approved a document they never saw, and the only way to discover it is to notice they were on holiday that week. The decision carries both - who pressed the button, and whose step it was
- [x] A window with an end on it, because a delegation with none is a standing substitution nobody revisits: it outlives the holiday, the role and sometimes the employment. A delegation that has expired or been revoked is refused by name
- [x] And overlapping delegations between the same two people are refused by an exclusion constraint. Two of them are two answers to "may they act today", and the code would read whichever came back first
- [x] A delegate **cannot receive an approval that SoD forbids them**, which is the obvious way to launder a conflict. The SoD engine is asked about the person *acting* rather than about the delegator, so the conflict is caught where it arrives rather than where it was arranged - and the database refuses a delegation to oneself, which is the same trick from the other direction
- [x] A delegation may carry a **lower cap** than the delegator's own limit - "you may approve for me, up to 5,000" - and the lower of the two binds. Taking the delegator's would make delegation a way to borrow authority
- [x] An escalation path when a step ages past a threshold: `escalate_after_hours` on the step, and `overdue()` reports every open step past its window. A document waiting on somebody on holiday is one nobody is looking at
- [x] A standing report of delegations in force, for the compliance pack, carrying the reason - which is the part an auditor reads
- [x] Found on the way: two of these checks passed for the wrong reason. The deputy acted with empty roles, so the step did not match and the refusal came from "this step is not yours" rather than from the delegation - and a bite that removed the delegation requirement altogether left both green. A delegate acts under the **delegator's** roles, which is what a delegation is, and the checks now say so

### PF-138 · Approval limits across currencies
`Purchasing` · **3 pts** · depends: PF-136, PF-068 · standards: IAS 21

- [x] Limits per entity, per role and per user, denominated in the entity's functional currency. A user's own beats a role's - an individual arrangement is deliberate and a role's is a default - and where several roles apply the **highest** wins, because a person holding two roles has the authority of both and taking the lower would make granting somebody an extra role reduce what they can do
- [x] A foreign-currency bill is converted **at the approval date** for the limit test, so a limit cannot be dodged by invoicing in a weak currency. A check approves a 150,000 JPY bill worth 4,200 against a 1,000 limit and requires the refusal
- [x] The rate used for the limit test is recorded on the approval, with its date, because "why did this need a director" is answered by the rate on the day the chain started and not by today's
- [x] A limit change is permissioned, audited, and **never retroactive**. The limit that applied is the one in force when the chain started, so a change is a new row with its own window and the previous one is *closed* rather than replaced - "what was their limit in March" is the question an auditor asks about a March approval, and a row that was overwritten has no answer
- [x] The lowest of the three caps binds: the approver's own limit, the step's, and the delegation's

### PF-139 · Duplicate bill detection
`Purchasing` · **5 pts** · depends: PF-133 · standards: — · `risk:high`

The most common and most expensive AP error.

- [x] A hard uniqueness constraint on `(supplier, supplier_document_number, entity)`, enforced in the database. In the database rather than the application because an import, a second worker or a retried job does not go through the form - and the service asks first anyway, so the form can say "that invoice is already recorded" instead of showing somebody a database error
- [x] On the **normalised** number: `INV-001`, `inv 001` and `INV001` are one document to the supplier, and a constraint on the raw string catches only the lazy half of the problem. Leading zeros are kept, because `INV-001` and `INV-1` are frequently different documents and a false refusal is worse than a warning - the warning asks a person and the refusal does not
- [x] A **near-match warning** on same supplier, similar amount within a window, or the same amount and date with a different number - the case a hard constraint cannot catch, because sometimes they are genuinely two purchases
- [x] And the same number against a **different supplier**, which the constraint cannot see at all - numbers are unique per supplier. That is a bill entered against the wrong supplier: it pays the right amount to the wrong account, which is the error that is hardest to get back
- [x] The warning shows the suspected duplicate side by side and requires an explicit acknowledgement that is recorded. The detail names the figures - "same date 2026-05-18 and the same total SAR 1150.00, under a different number" - because a generic hold tells the reviewer to go and look where naming the figures lets them decide
- [x] An acknowledgement needs a reason of substance, enforced in the database as well as in the service: "not a duplicate" with nothing behind it is what somebody clicks at four in the afternoon, and whoever defends the payment six months later needs to know what was actually checked
- [x] An unanswered warning **blocks posting**. Either it is the same invoice, in which case paying it twice is the error this exists to prevent, or it is not, in which case somebody should say so and be answerable for it
- [x] Detection runs again at payment time, because the duplicate frequently arrives after the first was already approved and by then nobody is looking at the entry screen. Re-examination is idempotent per pair and reason, so an acknowledgement does not have to be given again every time anybody looks
- [x] An exception queue ordered by value, carrying both numbers, both dates and both statuses, so a reviewer compares the two without opening either

### PF-140 · Email intake
`Purchasing` · **5 pts** · depends: PF-073 · standards: — · `type:security`

- [ ] A per-entity intake address; attachments and bodies become inbox items, never directly bills
- [ ] Sender allowlisting per supplier, with unknown senders quarantined rather than discarded
- [ ] Attachments type-sniffed, size-capped and scanned before anything reads them
- [ ] The original email is retained and linked, because it is frequently the only evidence of what was agreed

### PF-141 · Inbound Peppol bills
`Purchasing` · **3 pts** · depends: PF-122, PF-133 · standards: Peppol BIS Billing 3.0

- [ ] Documents received by the `Exchange` plane become draft bills with every field already populated and nothing guessed
- [ ] The inbound document is **validated before it is parsed**, and a failing document is rejected with the standard's own response rather than half-imported
- [ ] The original XML is archived as received
- [ ] Supplier matching by electronic address, falling back to tax registration, then to a human decision - never to a name guess

### PF-142 · Document extraction
`Purchasing` · **5 pts** · depends: PF-140 · standards: —

- [ ] Embedded XML extracted where present - a Factur-X PDF is a structured document and must never be sent to OCR
- [ ] OCR as the fallback, which **proposes a draft rather than asserting values**, with per-field confidence shown and low-confidence fields highlighted for review
- [ ] No extracted bill posts without a human confirming it, and the confirmation is audited
- [ ] Extraction accuracy is tracked per supplier template, so a consistently misread format is visible

### PF-143 · Expense claims
`Purchasing` · **5 pts** · depends: PF-136 · standards: — · `type:posting`

- [ ] Employee claims with lines, receipts attached, per-diem and mileage rules, and a policy check
- [ ] Posts to a payable **to the employee**, which is a distinct control account from trade payables
- [ ] Input tax recovered only where a valid receipt is attached and the category permits it
- [ ] Approval through the same engine; a claimant cannot approve their own claim
- [ ] Reimbursement joins a payment run like any other payable

### PF-144 · AP aging and supplier statements
`Purchasing` · **3 pts** · depends: PF-133 · standards: —

- [ ] Aging by bucket, with the basis (due date or document date) configurable and stated on the report
- [ ] Instalment terms produce several due dates on one bill, and the aging must split them rather than aging the whole bill from the last one
- [ ] DPO, and a reconciliation of the aging total to the AP control account - a difference is a bug, and the report says so
- [ ] Supplier statements in open-item and balance-forward form, for sending to the supplier
