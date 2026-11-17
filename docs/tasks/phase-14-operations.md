# Phase 14 — Stock, serials and the operational edges

**11 tasks · 58 points**

Everything so far answers *what happened to the money*. This phase answers *what happened to the things* — and the two have to agree, because a stock figure that does not reconcile to the cost of sales is a stock figure nobody can close a year on.

It comes from a gap analysis against [hawalaty](https://github.com/mostfaswaisi93/hawalaty-laravel), which runs real businesses and therefore knows what an invoicing core alone cannot do. The tasks below are the parts of it that belong in a product whose subject is invoices and banks. The industry-specific parts are [Phase 15](phase-15-verticals.md), which is a different decision.

> **Why these are not optional extras.** An invoice line that sells a tracked item and does not move stock leaves two records of one event. The first symptom is a margin report nobody believes; the second is a stock count that writes off the difference every quarter and calls it shrinkage.

---

### PF-247 · Items that carry stock
`Inventory` · **5 pts** · depends: PF-073, PF-047 · standards: IAS 2 · `type:schema` `risk:high`

- [x] A catalog item declares whether it is **stocked**, and only a `good` can be - a stocked service would demand a movement for something that has no units
- [x] Quantity on hand is **derived from movements**, never a column somebody updates. There is no such column, and a check asserts there is none: a stored quantity is a second place the truth lives, and it drifts the first time a movement is reversed or a job retries
- [x] Cost method per item - weighted average or FIFO - declared, with the constraint written against NULL explicitly
- [x] Negative stock is **refused by default**, in the service and in a trigger that takes the sum with the item row locked - because two tills selling the last unit in the same second is a race the service cannot see. Permitting it requires a recorded reason
- [ ] Fractional quantities where the unit of measure permits them, with the precision declared on the unit rather than assumed - the column carries six places, and the per-unit precision rule lands with PF-249's axes
- [x] **Found while checking this task:** the cost-method constraint was `NOT stocked OR cost_method IN (...)`, which evaluates to NULL for a null method - and PostgreSQL accepts a check that evaluates to NULL. The one row it existed to refuse was the one row it let through. Every `OR x IN (...)` in a check constraint has that shape
- [x] **And:** stock is not master data. The first draft put it in `Catalog`, and the layer rules refused it - Catalog may not post, deliberately, because a price list has no business writing journal entries. A movement is a document, so it lives in its own `Inventory` module beside Sales and Purchasing

### PF-248 · Stock movements and the cost of sales
`Inventory` · **8 pts** · depends: PF-247 · standards: IAS 2 · `risk:high` `type:posting`

- [x] Every movement is typed, and its sign must match its kind - a receipt that removes stock is somebody undoing one by entering another backwards, which makes the history unreadable. An adjustment carries its reason in the service **and** in a constraint, because "adjustment" with no reason is where shrinkage hides
- [x] Issuing stock posts **Dr cost of sales / Cr inventory** at the item's own cost, computed from the movements before it and frozen on the line. A receipt credits **goods received not invoiced**, not the payable: the goods are here and the invoice is not, and crediting AP would create a payable no supplier has sent and post it twice when the bill arrives
- [x] The cost is frozen, proved by receiving at a different price afterwards and asserting the earlier issue did not move - recomputing would make the margin on a sale depend on when somebody asked, and a reversal would reverse a different figure from the one it posted
- [x] Asserted in `ledger:verify`: the inventory control account equals what the movements are worth, per entity, as a **critical** finding. Proved by writing a movement without its posting - which is what a half-failed write leaves, and the reason the two go in one transaction
- [x] Movements are immutable and not deletable; a correction is a new movement, and the refusal says so
- [x] **Found while checking this task:** a unit cost is a **rate**, not an amount. `Money` is at four places and a weighted average of 10 at 4.00 and 5 at 6.00 is 4.666667 - which `Money::of` refuses, correctly, because silently rounding a cost makes the valuation disagree with its own movements by a fraction per unit. The cost is a `BigDecimal` at six places and only the value becomes `Money`

### PF-249 · Product variants
`Catalog` · **5 pts** · depends: PF-247 · standards: — · `type:schema`

- [x] An item may have axes and a variant per combination, each with its own code and barcode - and the axes are **data**, asserted by a check that there is no `size` or `colour` column anywhere: the next vertical always needs an axis nobody thought of, like grind for coffee or offset for wheels
- [x] A variant inherits what it does not override - `NULL` means "whatever the item says", so changing the item's tax code changes forty variants and changing one variant's price changes one. A variant that copied every field would be forty rows to update and thirty-nine chances to miss one
- [x] A combination that does not exist is **absent**, not disabled, proved by creating two of six and asserting two rows exist. The four that do not would need stock records, price lines and a reason for being inactive, and every report would have to remember to exclude them
- [x] Deleting an axis value in use is refused and **names what holds it** - "cannot delete" sends somebody looking; "1 variant is the large of the size axis" tells them where to go. An unused value still deletes, so a typo is not permanent
- [x] **And:** the same combination written with its keys in the other order is the same variant. `jsonb` normalises key order and compares by content, so the unique index is on the column itself - a signature column was built first, as a generated column with a subquery in it, which PostgreSQL refuses outright. The refusal was useful: the signature added nothing the check against `{}` did not already cover

### PF-250 · Batches, expiry and lot tracking
`Inventory` · **5 pts** · depends: PF-248 · standards: IAS 2 · `risk:high`

- [x] A batch carries its number, its received date, its expiry and **the supplier's own lot** - a recall notice names theirs, so ours has to be findable from it. An item under batch control cannot move without naming one, enforced by a trigger because an import is the other door; and a movement naming another item's batch is refused, because that makes a recall return the wrong customers, which is worse than returning none
- [x] First-expiry-first-out is offered and never applied: `suggest()` returns the order with its reasoning and writes nothing, asserted by counting movements before and after. The right batch is usually the one expiring first and is sometimes the one already in the van, and the person who loaded it is the one who knows
- [x] Expired stock is reported **before** it expires, with the lead time as a parameter - seven days is right for fresh food where ninety is right for medicine, so a constant is right for neither. The report says what can still be done, which is the only reason to send it early
- [x] Issuing from an expired batch needs a name and a reason recorded on the movement. It is sometimes right - a cosmetic best-before, a batch re-tested and released - and never right silently, so the check proves both directions: refused without, allowed and recorded with
- [x] **And:** a batch answers *who has the goods*, not how much is left. The trace follows the movement to the document to the party and excludes receipts, because "where did this come from" is a different question from the one a recall asks
- [x] **Found while checking this task:** `ORDER BY expires_on` puts nulls **first** in PostgreSQL, so the one batch with no deadline was suggested ahead of the one expiring on Friday - the exact opposite of the rule. `NULLS LAST` was missing, and the check that caught it had to ask for more than the first two batches held, because asking for less never reached the third one and asserted nothing

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
