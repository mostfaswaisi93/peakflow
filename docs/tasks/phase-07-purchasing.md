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

- [x] Supplier, lines with item, quantity, unit price, tax code, delivery date, dimensions. Tax on an order is **indicative**: a buyer wants to know roughly what it will cost including tax, and the figure is never posted and never claimed, because the authority's figure comes from the supplier's tax invoice and an order's estimate that found its way into a return would be a claim supported by a document that does not exist
- [ ] Sent to the supplier as a PDF, and by Peppol where the supplier is reachable. **Open, and blocked on two things that are not code.** The PDF needs the rendering toolchain PF-102 to PF-104 are blocked on - Gotenberg, Ghostscript and veraPDF are not installable in this environment - and sending by Peppol needs the AS4 transport and SMP lookup of PF-121 and PF-122, which need a network and an accredited access point. The order document itself is a UBL `Order`, which is a different document type from the invoice the Exchange plane serializes today
- [x] A commitment is tracked per line for budget visibility, and is never a financial posting. **There is no journal entry anywhere in `PurchaseOrders`**, and a check asserts it: an order is a promise, not a transaction - nothing has been delivered, nobody is owed anything and no expense has been incurred. Systems that post an "encumbrance" to a ledger account end up with a balance sheet carrying a liability nobody owes, which reconciles to nothing and has to be explained to an auditor every year. The remaining commitment is `(ordered - billed) x price`, computed per line
- [x] And it is released by the **bill**, not by the receipt. A receipt without a bill is money already spent and not yet invoiced, which is the accrual's job - releasing the commitment on delivery would release it before the obligation existed. A check receives the goods and asserts the commitment has not moved
- [x] Partially received and partially billed orders are tracked **per line**, not per order. `quantity_received` and `quantity_billed` are columns on the order line: an order for ten of which six arrived and four were billed is the ordinary case, "60% received" says nothing about which six, and the three-way match has to compare a bill line against the quantity received for *that* line
- [x] Closing an order with uninvoiced receipts is refused with an explanation, and the explanation names the lines and quantities. That quantity has a GRNI balance behind it - a liability for goods the business is holding and has not paid for - and closing the order would strand it in the control account with no open document pointing at it, so the only way to find out what it was would be to read the ledger backwards. "Cannot close" without the figures sends somebody hunting through three documents
- [x] A closed order says **why**, in the service and in a CHECK constraint. An order closed with nineteen units never delivered and no reason is what somebody finds in a year while asking what happened to them, and by then the buyer has left. Cancelling an order that has received lines is refused outright: something arrived, and the supplier either keeps it or takes it back - both are events that need recording

### PF-132 · Goods and service receipts
`Purchasing` · **3 pts** · depends: PF-131 · standards: — · `type:posting`

- [x] Receipt against a purchase order, full or partial, with over- and under-delivery tolerances per supplier. Over-delivery beyond the tolerance is **refused**, not warned: accepting a quantity nobody agreed to buy commits the business to paying for it, because the supplier will invoice what they delivered and by the time the bill arrives the goods are in the warehouse and the argument is lost
- [x] A rejection is **recorded, not subtracted**. Ten arrived and two were damaged: the receipt says ten with two rejected and the order line moves by eight. Both figures are kept, because "we received eight" loses the fact that the supplier shipped ten and owes a replacement, which is the conversation the warehouse needs to have with them. A rejection with no reason is refused by a CHECK constraint
- [x] Optionally posts a **goods-received-not-invoiced accrual**, which is what makes a period-end cutoff honest. Goods arrive on the 29th and the invoice arrives on the 4th: without the accrual the expense lands in the wrong month and the balance sheet omits a liability that exists. `goods_received_not_invoiced` is a **control role** in the chart rather than a journal somebody posts each month, which is what makes "what have we received and not been billed for" a balance instead of a note. Optional per entity (`purchasing.accrue_receipts`), because a business that bills within days of receiving does not need it and one that closes a period between the two cannot do without it
- [x] The accrual carries **no tax**, and a check asserts it. A delivery note is not a tax document: the tax point may not have arrived, no tax invoice exists, and nothing can be claimed from an authority on the strength of it. Putting input tax into a return before the document that supports it exists is the one thing a tax authority reliably notices
- [x] The accrual is reversed by the bill that matches it, on the **bill's** posting date - the accrual belongs to the month the goods arrived in and its release belongs to the month the invoice arrived, and reversing it in the original month would reopen a closed period, which the ledger refuses. Only for receipts now fully billed: a receipt of a hundred against which sixty have been invoiced still has forty units of goods accrued, and releasing the whole entry would understate a liability that exists
- [x] And an aged accrual report surfaces receipts never billed. This is what the control account is for: a balance that has not cleared in ninety days is an invoice that was never sent, a receipt recorded for a delivery that never came, or a bill entered without matching it to the order. All three are worth finding, and none of them is visible in a month-end accrual that reverses itself on the 1st whether or not the invoice ever came
- [x] A receipt for a service is a confirmation of performance, which is what a three-way match needs for a service line. There is nothing on a loading bay to count, so `confirmed_by` and the confirmation text are required on a service line at the moment of receipt rather than left to the match to complain about later - and a service bill with no confirmation gets its **own** kind of exception, because "received 0" would read as a delivery that never came

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

- [x] Bill against purchase order against receipt, on **quantity and price**, per line. Price as well as quantity because a bill for the right quantity at the wrong price passes a quantity-only match, and that is the common form of supplier overcharging: the units are right and the rate crept up. Only an increase is an exception - a supplier billing less than agreed is their mistake, and raising one would hold a payment the business owes
- [x] Tolerances per supplier and per line type, in both absolute and percentage terms, with a platform ceiling. Both, because neither alone works: two per cent of a fifty-unit order is one unit and two per cent of a fifty-thousand-unit order is a theft, while a flat tolerance of one unit flags every bulk delivery that arrived a kilogram light. The **larger** of the two is allowed - the percentage carries scale and the absolute carries the small orders where a percentage rounds to nothing. Where nothing is configured the answer is zero, because a tolerance nobody configured is a tolerance nobody decided on, and the safe reading of "nobody decided" is "ask a person"
- [x] The ceiling is a CHECK constraint, not a service rule. Ten per cent is the most the platform permits: a tenant who sets fifty has turned the match off, and the thing a three-way match exists for is the invoice that is quietly twenty per cent too high. A limit enforced only in a service is a limit an import does not have
- [x] A mismatch routes to an exception queue **naming the specific discrepancy** - "billed 120 units, received 100" - rather than a generic hold. The figures are columns of their own as well as prose, so the queue is ordered by how far out each one is: a queue ordered by date puts a two-cent rounding difference above a thousand-unit over-billing, and whoever works it has to open every one to find out which matters
- [x] Eight kinds, each answering a different question: no order (where the entity requires one), nothing received, billed beyond received, billed beyond ordered, priced above the order, the order already closed, the wrong supplier, and a service nobody confirmed. A service bill with no confirmation is its own kind rather than "received 0", which would read as a delivery that never came
- [x] Found on the way: billing 101 against an order of 100 that was fully received raised **both** quantity kinds with identical figures, because received and ordered were the same number - two rows for one problem, which doubles the queue and makes somebody answer the same question twice. `quantity_over_ordered` is now gated on the excess having actually arrived, which is the case the receipt comparison cannot express. The duplicate pair had also hidden a defect: a check comparing the first two rows of the queue found the pair in both positions and passed with the ordering removed
- [x] And found with it: the queue's own query had no `ORDER BY` beneath the sort, so PostgreSQL was free to return the rows in a different order between runs on the same data - which makes a paginated queue skip rows and repeat others
- [x] Matching handles partial receipts, partial bills, and one bill spanning several orders. The running `quantity_billed` is on the **order line**, which is what lets a second bill be caught: the first consumed the receipt and the second has nothing left to match. A bill-level counter could not tell the second bill how much of the order the first one used, and the duplicate detector cannot see this case at all because the numbers differ
- [x] A matched bill records what it matched against, so the match is auditable afterwards. `bill_lines.purchase_order_line_id` and `goods_receipt_line_id` are written by the match and kept, so "was this bill ever checked against anything" has an answer six months later and the answer is not "somebody says so"
- [x] The match runs **twice**, and only the second run consumes. At entry, to tell the person typing what is wrong while they still have the supplier's document in front of them; at posting, which is the run that advances the order line. A draft bill that advanced the counter and was then abandoned would leave a commitment released against a bill that never existed, and an order nobody could receive against again
- [x] An open exception refuses the posting, and the refusal **names the figures** rather than counting them: the person who posts is frequently not the person who can answer, and the message is what they will forward. A match exception cleared by posting through it is a control that exists only on paper
- [x] Found on the way: the exceptions have to be raised **outside** the transaction the refusal rolls back. Raising them inside it would hand the caller a message and keep no record of it, which is the one failure mode an exception queue cannot have - so the checking run is outside and the consuming run is inside, where advancing the order line has to succeed or fail with the posting
- [ ] The receive-then-approve pair is **not** expressed as a segregation-of-duties rule. Whoever confirms a delivery should not approve the bill for it - the fictitious-receipt fraud is to confirm a delivery that never came and approve the invoice for it - but the duties sit on two different objects linked by an order, and PF-033's scopes are the same document, the same counterparty or the same period. Closing it means a chain scope in the SoD engine that expands a bill into the receipts behind it, which is a change to PF-033 rather than to this task

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
