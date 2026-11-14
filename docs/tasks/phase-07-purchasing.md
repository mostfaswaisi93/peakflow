# Phase 07 — Purchase orders, bills, approvals and three-way match

**15 tasks · 68 points**

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

- [ ] Supplier invoice with lines, tax breakdown, the supplier's own document number and date, and the derived due date
- [ ] Posts accounts payable, input tax, and expense or asset - with the **reverse-charge case posting both input and output tax**, which is the half most systems forget
- [ ] The supplier's document number and date are theirs, and the posting date is ours; all three are stored and the return uses the tax point
- [ ] Non-recoverable input tax posts to expense rather than to the input account, keeping the input account reconcilable to the return
- [ ] A bill in a foreign currency books at the rate on its tax point date

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

- [ ] Rules on amount, account, dimension, supplier and document type, evaluated into an ordered chain of approvers
- [ ] Sequential and parallel steps; a quorum where a policy requires two of three
- [ ] SoD enforced: a user cannot approve a bill they created (`PF-033`), and the refusal names who can
- [ ] An approval records the exact document state it approved - a bill edited after approval **returns to the start of the chain**, and this is the hole that matters
- [ ] Every decision is audited with actor, time, rule and comment

### PF-137 · Delegation and continuity
`Purchasing` · **3 pts** · depends: PF-136 · standards: —

- [ ] Delegation for a date range, with the delegate recorded on the approval rather than silently substituted
- [ ] Out-of-office reassignment, and an escalation path when a step ages past a threshold
- [ ] A delegate cannot receive an approval that SoD forbids them, which is the obvious way to launder a conflict
- [ ] A standing report of delegations in force, for the compliance pack

### PF-138 · Approval limits across currencies
`Purchasing` · **3 pts** · depends: PF-136, PF-068 · standards: IAS 21

- [ ] Limits per entity, per role and per user, denominated in the entity's functional currency
- [ ] A foreign-currency bill is converted **at the approval date** for the limit test, so a limit cannot be dodged by invoicing in a weak currency
- [ ] The rate used for the limit test is recorded on the approval
- [ ] A limit change is permissioned, audited, and never retroactive

### PF-139 · Duplicate bill detection
`Purchasing` · **5 pts** · depends: PF-133 · standards: — · `risk:high`

The most common and most expensive AP error.

- [ ] A hard uniqueness constraint on `(supplier, supplier_document_number, entity)`, enforced in the database
- [ ] A **near-match warning** on same supplier, similar amount within a window, or the same amount and date with a different number - the case a hard constraint cannot catch
- [ ] The warning shows the suspected duplicate side by side and requires an explicit acknowledgement that is recorded
- [ ] Detection runs again at payment time, because the duplicate frequently arrives after the first was already approved

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
