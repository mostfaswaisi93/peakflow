# Phase 14 — Stock, serials and the operational edges

**11 tasks · 58 points**

Everything so far answers *what happened to the money*. This phase answers *what happened to the things* — and the two have to agree, because a stock figure that does not reconcile to the cost of sales is a stock figure nobody can close a year on.

It comes from a gap analysis against [hawalaty](https://github.com/mostfaswaisi93/hawalaty-laravel), which runs real businesses and therefore knows what an invoicing core alone cannot do. The tasks below are the parts of it that belong in a product whose subject is invoices and banks. The industry-specific parts are [Phase 15](phase-15-verticals.md), which is a different decision.

> **Why these are not optional extras.** An invoice line that sells a tracked item and does not move stock leaves two records of one event. The first symptom is a margin report nobody believes; the second is a stock count that writes off the difference every quarter and calls it shrinkage.

---

### PF-247 · Items that carry stock
`Catalog` · **5 pts** · depends: PF-073, PF-047 · standards: IAS 2 · `type:schema` `risk:high`

- [ ] A catalog item declares whether it is **stocked**, and a stocked item cannot be sold without a stock movement - the two records of one event are the whole problem this phase exists to prevent
- [ ] Quantity on hand is **derived from movements**, never a column somebody updates. A stored quantity is a second place the truth lives, and it drifts the first time a movement is reversed
- [ ] Cost method per item - weighted average or FIFO - declared and immutable once a movement exists, because changing it restates every cost of sale already posted
- [ ] Negative stock is **refused by default** and permitted only by an explicit setting, because a business that allows it is choosing to; one that allows it by accident discovers the cost of sales is a guess
- [ ] Fractional quantities where the unit of measure permits them, with the precision declared on the unit rather than assumed - selling 0.25 of a licence is a different error from selling 0.25 of a litre

### PF-248 · Stock movements and the cost of sales
`Catalog` · **8 pts** · depends: PF-247 · standards: IAS 2 · `risk:high` `type:posting`

- [ ] Every movement is typed: receipt, issue, transfer, adjustment, count, return - and carries its reason, because "adjustment" with no reason is where shrinkage hides
- [ ] Issuing stock posts **Dr cost of sales / Cr inventory** at the item's own cost, computed from the movements before it and frozen on the line
- [ ] A reversal reverses the cost that was posted, not the cost today - the cost moved between the two, and recomputing would leave the margin on a cancelled sale depending on when it was cancelled
- [ ] Asserted in `ledger:verify`: the inventory control account equals the sum of quantity-on-hand times cost, per entity. A control account that does not reconcile to its own subsidiary record is the same class of fault as a receivables control that disagrees with the customer ledger
- [ ] Movements are immutable; a correction is a new movement, because a stock ledger somebody can edit is a stock ledger nobody can audit

### PF-249 · Product variants
`Catalog` · **5 pts** · depends: PF-247 · standards: — · `type:schema`

- [ ] An item may have axes (size, colour, strength) and a variant per combination, each with its own code, price and stock - and the axes are **data**, because a product with a hard-coded size enum is a product that cannot sell shoes
- [ ] A variant inherits what it does not override, so changing the parent's tax code changes forty variants and changing one variant's price changes one
- [ ] A combination that does not exist is **absent**, not disabled: a matrix that generates every combination produces stock records for shoes nobody makes
- [ ] Deleting an axis value with stock or history is refused, naming what holds it

### PF-250 · Batches, expiry and lot tracking
`Catalog` · **5 pts** · depends: PF-248 · standards: IAS 2 · `risk:high`

- [ ] A batch carries its number, its received date and its expiry, and an issue **names the batch** - a stocked item under batch control that moves without one is a traceability gap, which for food or medicine is a regulatory one
- [ ] First-expiry-first-out is offered and never silently applied; the suggestion is shown and somebody confirms, because the right batch is sometimes the one in the van
- [ ] Expired stock is reported before it expires, with lead time, since a report of what expired yesterday is an accounting entry rather than a decision
- [ ] Issuing from an expired batch needs an explicit, recorded acknowledgement naming the person

### PF-251 · Serial numbers and warranty
`Catalog` · **5 pts** · depends: PF-248 · standards: — · `risk:high`

- [ ] A serial is unique per item across the tenant, and its whole history - received, sold, returned, replaced - reads as one list, because "where is this unit" is the question a warranty claim starts with
- [ ] A serial can be sold once. Selling it twice is refused by a **unique index**, not by a service check, because the second sale usually arrives from an import or a second till
- [ ] Warranty terms per item or per sale, with the start date derived from the sale and the end computed, so a claim is answered by a date rather than by an argument
- [ ] A claim records the fault, the decision and the outcome, and a replacement moves the new serial out and the old one in - the stock has to be right either way

### PF-252 · Returns and credit
`Sales` · **5 pts** · depends: PF-248, PF-096 · standards: — · `risk:high`

- [ ] A return names the invoice it reverses, the lines and the quantities, and **refuses more than was sold** - the database holds that, because a part return of a part return is where a service check is wrong
- [ ] The credit note and the stock movement are one transaction: a return that credited the customer and did not restore the stock is the error nobody notices until a count
- [ ] Restocking is a **decision**, not an assumption. Faulty goods come back into a quarantine location rather than into sellable stock, and the two have different accounting
- [ ] A restocking fee or a partial credit is recorded as such, so the figure on the credit note is explainable from the return

### PF-253 · Stock counts
`Catalog` · **3 pts** · depends: PF-248 · standards: — · `type:posting`

- [ ] A count is a document with a scope, a state and two people where the variance is material - and counting against a figure the counter can see is not a count, so the expected quantity is hidden until the count is entered
- [ ] The variance posts as an adjustment with its reason, per line, because one total write-off tells nobody which items go missing
- [ ] A count in progress freezes nothing and blocks nothing: movements during a count are recorded against it, since stopping a business to count is how counts stop happening
- [ ] The variance report is by value and by quantity, and names the items that account for most of it

### PF-254 · Price history, and the price somebody was quoted
`Catalog` · **3 pts** · depends: PF-074 · standards: — · `risk:high`

- [ ] Every price change is kept with its date and the person, because "what did this cost in March" is asked by a customer holding an invoice
- [ ] A document uses the price **as of its own date**, not today's, and the snapshot is on the line - which the sales side already does for tax and must do for price
- [ ] A scheduled change takes effect on its date without anybody running anything, and is visible before it does
- [ ] A price list may be derived from another by a rule (a margin, a discount), and the derivation is re-run rather than copied

### PF-255 · Offline-first sync and devices
`Platform` · **8 pts** · depends: PF-010, PF-035 · standards: — · `risk:high` `type:security`

- [ ] A device is registered, named and revocable, and its token is scoped to one tenant - a till that keeps working after it is stolen is the whole risk here
- [ ] Writes made offline carry a client-generated id, so replaying them twice creates one record. **Idempotency is the contract**, because a network that drops a response is indistinguishable from one that drops a request
- [ ] Conflicts are **surfaced, never merged silently**: two tills selling the last unit is a fact somebody has to decide about, and a last-write-wins rule decides it by latency
- [ ] A sync event log says what each device sent and when, because "the till says 400 and the server says 380" is otherwise unanswerable
- [ ] Clock skew is handled by ordering on the server's receipt, not on the device's clock - a till with the wrong date must not be able to post into a closed period

### PF-256 · Payroll payments
`Settlement` · **5 pts** · depends: PF-174, PF-141 · standards: — · `risk:high`

- [ ] A salary payment is a payable like any other and goes out through the **same payment run**, maker-checker and all - a separate path for payroll is a separate path round the controls
- [ ] Net pay, deductions and employer contributions post to their own accounts, because a single net figure hides the liability the business still owes
- [ ] Payroll amounts are visible only to those permitted, enforced at the row rather than hidden on a screen, and a read of them is audited - the same decision bank details already carry
- [ ] A recurring salary is a schedule, not forty copies, and changing it changes the next payment rather than the history

### PF-257 · Delivery by WhatsApp and the channels a customer actually reads
`Exchange` · **6 pts** · depends: PF-102, PF-130 · standards: — · `risk:high`

- [ ] A document can be delivered by WhatsApp Business, with the template, the language and the variables declared as data - and a template the provider has not approved is **refused before sending**, not after
- [ ] Delivery, read and failure are recorded per attempt, so "did they get the invoice" has an answer that is not a guess
- [ ] Inbound replies land somewhere a person sees them, because a channel that only sends is a channel customers learn to ignore
- [ ] A number is verified before anything is sent to it, and the consent is recorded with its date - sending an invoice to a wrong number is a data breach with a timestamp
- [ ] The provider is behind an interface, with one implementation and one fake, because a product wired to a single vendor's SDK is a product that cannot change vendor
