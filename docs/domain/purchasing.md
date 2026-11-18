# The payable side

Purchasing is structurally the mirror of selling, with two things selling does not have.

**Documents arrive from outside.** An invoice we issue is composed: we choose its number, its dates and its lines, and we can refuse to issue one that does not make sense. A supplier's bill is handed to us. Its number is theirs, its date is theirs, and the only two decisions we make about it are which period to post it in and whether to pay it. Everything in this module follows from that asymmetry.

**Money leaving needs a second person.** Money arriving needs a correct invoice; money leaving needs somebody other than the person who arranged it to agree. Approval is therefore not a workflow feature bolted on at the end - it is the control the whole module exists to serve, and the segregation-of-duties engine is what makes it a control rather than a formality.

---

## Requisitions: the request before the commitment

An order commits the business to a supplier at a price. A requisition does not even name the supplier: it says somebody needs something, roughly what it will cost, and **why**.

The justification is the reason the step exists. Strip it out and a requisition is a purchase order with extra clicks; what makes it worth having is that somebody wrote down why and somebody else read it, which is the only thing a budget holder can act on three months later when asked why the department spent what it did. `purchase_requisitions_justification_says_something` refuses "needed".

It routes through the **same** approval engine as a bill, as a subject of type `requisition`. That buys the limits, the delegation record, the fingerprint check that returns an edited document to the start of the chain, and the refusal when the requester tries to approve their own request. A workflow of its own would mean a second set of limits to keep in step with the first, and the one that drifts is the one nobody is looking at. The fingerprint covers the justification deliberately: changing *why* after somebody approved it is a different request.

The step is **optional** (`purchasing.require_requisition`, default off). A three-person business forced through it approves its own requests to itself all day and learns to click past them — and an approval somebody has learned to click past is worse than none, because in an audit it looks like a control. Where it is on, `PurchaseOrders::raise()` refuses an order that names no requisition, which is the only place the rule can be enforced: the requisition is the document that would otherwise simply not exist.

Conversion carries the **dimensions** forward, line first then header, so the cost centre that approved the spend is the one the expense lands against. It takes the quoted price where one is given and keeps the estimate where it is not — with the order's note saying which lines carry an estimate. Refusing would be wrong, because stationery at a catalogue price needs no re-quoting; copying an estimate in silently would be worse, because somebody will later read it as an agreed price.

---

## The three documents, and why all three

| Document | Says | Answers |
|----------|------|---------|
| Purchase order | what was agreed | what price did we negotiate, and how much did we authorise |
| Goods receipt | what arrived | did we get it, and did we keep it |
| Bill | what is being charged | what does the supplier say we owe |

Any two of the three can agree while the third does not, and each pairing hides a different error:

- **Order against bill, with no receipt.** Pays for goods that never came. This is the invoice from a supplier who was given an order and never delivered against it, and the only document that would have caught it is the one nobody filled in.
- **Receipt against bill, with no order.** Pays a price nobody negotiated. The goods arrived, somebody signed for them, and the rate on the invoice is whatever the supplier decided it was.
- **Order against receipt, with no bill.** Not an error at all. This is the accrual, and it is the subject of its own section below.

A system that matches only two of them is a system with one of those two holes in it.

---

## An order posts nothing

There is no journal entry anywhere in the purchase order code, and a check asserts it by counting journal entries with `source_type = 'purchase_order'` after an order is raised.

An order is a promise. Nothing has been delivered, nobody is owed anything, and no expense has been incurred. Systems that post an "encumbrance" or a "commitment" to a ledger account end up with a balance sheet carrying a liability nobody owes - it reconciles to nothing, it has to be excluded from every report that matters, and it has to be explained to an auditor every year.

Budget visibility is a **report**:

```
remaining commitment on a line = (quantity_ordered - quantity_billed) x unit_price
```

Against what has been **billed**, not what has been received. A receipt without a bill is money already spent and not yet invoiced - that is the accrual's job - and a line that released its commitment on delivery would release it before the obligation existed. A check receives the goods and asserts the commitment has not moved.

### Everything is per line

`quantity_received` and `quantity_billed` are columns on the **order line**. An order for ten of which six arrived and four were billed is the ordinary case:

- "60% received" says nothing about which six, and the warehouse needs to know which.
- The three-way match compares a bill line against the quantity received for *that* line, so an order-level figure makes the match impossible.
- A second bill against the same line needs to know how much of the order the first one used. A bill-level counter cannot tell it.

### A closed order says why, and one with uninvoiced receipts does not close at all

Closing requires a reason, in the service and in a CHECK constraint. An order closed with nineteen units never delivered and no explanation is what somebody finds in a year while asking what happened to them, and by then the buyer has left.

And closing is **refused** where something has arrived and has not been billed. That quantity has a goods-received-not-invoiced balance behind it, and closing the order would strand it: the balance would sit in the control account with no open document pointing at it, and the only way to find out what it was would be to read the ledger backwards. The refusal names the lines and quantities, because "cannot close" without them sends somebody hunting through three documents.

Cancelling an order that has received lines is refused outright. Something arrived: the supplier either keeps it or takes it back, and both are events that need recording.

---

## The accrual is what makes a period-end cutoff honest

Goods arrive on the 29th and the invoice arrives on the 4th.

Without an accrual the expense lands in the wrong month and the balance sheet omits a liability that exists: somebody has delivered and will be paid. So a receipt may post

```
Dr  the order line's expense or asset account    net of what arrived, at the order's price
Cr  goods_received_not_invoiced                  the same total
```

and the bill that matches it reverses that entry.

### Why it is a control account and not a monthly journal

A hand-posted accrual is reversed in full on the 1st whether or not the invoice ever came. It forgets. A control account does not, which is what makes **an aged GRNI report** meaningful: a balance that has not cleared in ninety days is one of three things, and all three are worth finding.

1. An invoice the supplier never sent.
2. A receipt recorded for a delivery that never came.
3. A bill entered without matching it to the order.

None of them is visible in an accrual that reverses itself.

### Three properties of the accrual, each with a check behind it

**It carries no tax.** A delivery note is not a tax document: the tax point may not have arrived, no tax invoice exists, and nothing can be claimed from an authority on the strength of it. Putting input tax into a return before the document that supports it exists is the one thing a tax authority reliably notices.

**It is released on the bill's posting date, not the receipt's.** The accrual belongs to the month the goods arrived in; its release belongs to the month the invoice arrived. Reversing it in the original month would reopen a closed period, which the ledger refuses - correctly.

**It is released only for receipts now fully billed.** A receipt of a hundred against which sixty have been invoiced still has forty units of goods accrued, and releasing the whole entry would understate a liability that exists.

### It is optional

`purchasing.accrue_receipts` is an entity setting, defaulting to off, and `receive()` takes an override. A business that bills within days of receiving does not need it and the entries are noise in their ledger; a business that closes a period between the two cannot do without it. Per receipt as well as per entity, because adopting it halfway through a period means accruing the receipts that are still open and leaving the ones already billed alone.

---

## Receipts

A receipt is recorded against a **confirmed** order. Against a draft it would record an arrival nobody authorised; against a closed one, an arrival whose commitment was already released.

### A rejection is recorded, not subtracted

Ten arrived and two were damaged. The receipt says ten with two rejected, and the order line moves by **eight**.

Both figures are kept. "We received eight" loses the fact that the supplier shipped ten and owes a replacement, and that is the conversation the warehouse needs to have with them. A rejection with no reason is refused by a CHECK constraint, because "two rejected" with nothing behind it is what nobody can take back to the supplier.

### Over-delivery is refused, not warned

Beyond the supplier's tolerance, a receipt is refused. Accepting a quantity nobody agreed to buy commits the business to paying for it: the supplier will invoice what they delivered, and by the time the bill arrives the goods are in the warehouse and the argument is lost.

A delivery of 102 against an order of 100 is routine in bulk goods and is theft in serial-numbered equipment, which is why the tolerance is per supplier and per line type rather than a constant.

### A service is confirmed, not counted

There is nothing on a loading bay to count. A service line's receipt carries `confirmed_by` and a confirmation text, both required at the moment of receipt rather than left to the match to complain about later. That confirmation is the only evidence a three-way match will ever have for a service, which is why a service bill with no confirmation gets its **own** kind of exception - "received 0" would read as a delivery that never came.

---

## The three-way match

Quantity **and** price, per line.

Price as well as quantity because a bill for the right quantity at the wrong price passes a quantity-only match, and that is the common form of supplier overcharging: the units are right and the rate crept up. Only an **increase** is an exception - a supplier billing less than agreed is their mistake, and raising one would hold a payment the business owes.

### Eight kinds, each answering a different question

| Kind | The question |
|------|--------------|
| `no_order` | did this purchase bypass procurement? (only where the entity requires orders) |
| `no_receipt` | are we paying for goods that never arrived? |
| `quantity_over_received` | billed 120, received 100 - where are the other 20? |
| `quantity_over_ordered` | the excess arrived and was accepted; who authorised buying it? |
| `price_above_order` | the units are right and the rate is not |
| `order_closed` | the bill arrived after the order was closed |
| `wrong_supplier` | this bill is matched to another supplier's order |
| `service_not_confirmed` | nobody has said the work was done |

`quantity_over_ordered` is gated on the excess having actually arrived. Without that gate, billing 101 against an order of 100 that was fully received raised **both** quantity kinds with identical figures - expected 100, actual 101 - because received and ordered were the same number. Two rows for one problem doubles the queue's length and makes somebody answer the same question twice. Where the goods never arrived, chasing the order is the wrong question about the same unit.

### The exception names the discrepancy

> billed 120 units, received 100 (20 of which was billed earlier). The tolerance is 2.

rather than

> the bill is on hold pending review.

A generic hold makes somebody open three documents to find out what is wrong, and the person who opens them is not the person who knows.

`expected`, `actual` and `tolerance` are columns of their own as well as prose, so the queue is **ordered by how far out each row is**. A queue ordered by date puts a two-cent rounding difference above a thousand-unit over-billing, and whoever works it has to open every one to find out which matters.

### Tolerances

Per supplier and per line type, in both absolute and percentage terms, with the **larger** of the two applying.

Neither alone works. Two per cent of a fifty-unit order is one unit, which is reasonable; two per cent of a fifty-thousand-unit order is a thousand units, which is a theft. A flat tolerance of one unit, meanwhile, flags every bulk delivery that arrived a kilogram light. So the percentage carries scale and the absolute carries the small orders where a percentage rounds to nothing.

Specificity is scored rather than ordered: supplier-and-type beats supplier beats type beats the entity's default. Taking the first match would make the answer depend on insertion order, which is how a tolerance set for one supplier silently applies to all of them.

Where nothing is configured, the tolerance is **zero**. A tolerance nobody configured is a tolerance nobody decided on, and the safe reading of "nobody decided" is "ask a person". A permissive default would mean a business that never opened the settings screen was silently accepting price differences, which is the opposite of what the match is for.

**Ten per cent is the platform's ceiling**, enforced by a CHECK constraint rather than only by the service. A tenant who sets fifty has turned the match off, and the thing a three-way match exists for is the invoice that is quietly twenty per cent too high. A limit enforced only in a service is a limit an import does not have.

### The match runs twice, and only the second run consumes

At **entry**, to tell the person typing what is wrong while they still have the supplier's document in front of them - the only moment at which "billed 120, received 100" is cheap to resolve. At **posting**, which is the run that advances the order line's `quantity_billed`.

A draft bill that advanced the counter and was then abandoned would leave a commitment released against a bill that never existed, and an order nobody could receive against again. Posting is the point at which the obligation is real, so posting is the point at which the order line moves.

### Where the two runs sit relative to the transaction

The checking run is **outside** the posting transaction and the consuming run is **inside** it, and both positions are deliberate.

Raising the exceptions inside the transaction that the refusal rolls back would hand the caller a message and keep no record of it - the one failure mode an exception queue cannot have. Advancing the order line outside the transaction would leave the lines consumed against a bill that never posted, so the next bill for those goods would match against a quantity already spoken for.

A mismatch found by the second run is a race rather than a discrepancy - somebody posted against the same line in between - and it refuses, which is the right answer to a race.

### An open exception refuses the posting

And the refusal names the figures rather than counting them. The person who posts is frequently not the person who can answer, and the message is what they will forward. A match exception cleared by posting through it is a control that exists only on paper.

### What a matched bill keeps

`bill_lines.purchase_order_line_id` and `goods_receipt_line_id`, written by the match and kept afterwards. Which means "was this bill ever checked against anything" has an answer six months later, and the answer is not "somebody says so".

### One bill across several orders

Nothing in the match assumes a bill maps to one order. Each bill line carries its own order-line link, so a bill covering three orders matches each line against its own - and the quantities it consumes are the ones already billed **on that line**, which is what catches the second invoice for the same goods. The duplicate detector cannot see that case at all, because the numbers differ.

---

## Bills

### Three dates, and they are not interchangeable

| Date | Whose | Decides |
|------|-------|---------|
| `supplier_document_date` | theirs | what their statement says, and what the payment terms count from |
| `tax_point` | the jurisdiction's | which return the input tax falls in, and which FX rate applies |
| `posting_date` | ours | the accounting period |

A bill dated the 28th of March that arrives on the 3rd of April belongs to the supplier's March and our April, and the input tax belongs to whichever period the tax point names. A system with one date gets two of the three wrong.

`Bill` deliberately has **no** `date()` accessor. A method that picked one would be read by whoever came next as "the date of the bill".

A tax point **after** the posting date is refused: it would claim input tax in a return that has not opened. The other direction is normal and is the reason the two dates exist.

### The posting

```
Dr  the line's expense or asset account    each line's net, plus its non-recoverable tax
Dr  vat_input @ the jurisdiction           the recoverable tax
Cr  accounts_payable                       the gross the supplier is owed
```

and under the reverse charge, additionally:

```
Dr  vat_input @ the jurisdiction           the self-assessed tax
Cr  vat_output @ the jurisdiction          the same amount
```

**The reverse charge posts both sides, and this is the half most systems forget.** The supplier charges no tax and the recipient accounts for it as if they had charged themselves: an output because they owe it, an input because they may claim it. A system that posts only the input side files a return whose input box is right and whose output box understates the liability by exactly the reverse-charge tax - and because the two net to nothing in the accounts, nothing looks wrong until the authority compares the return against the supplier's own declaration.

It also means reverse-charge tax is **not part of the payable**. The supplier is owed the net; the tax is owed to the authority and claimed back from them in the same breath.

**Non-recoverable tax goes to expense, not to the input account** - and to the line's own expense account, because the cost of a purchase includes tax that cannot be recovered and it belongs to the cost centre that bought it. Recovery is a percentage rather than a flag: partial recovery is the normal case for entertainment, fuel and mixed-use assets, and a boolean would force the business to split a line the supplier did not split.

### A bill in a foreign currency

**It books at the rate on its tax point date** (PF-133, IAS 21). Every line above is denominated in the supplier's currency and carries that one rate, so `journal_lines.amount` is what the document says and `functional_amount` is what the books carry. The rate is taken when the bill is entered and frozen on it as `fx_rate`, `fx_source` and `fx_rate_date` - the rate's **own** date, which an effective-dated lookup can legitimately put earlier than the tax point.

The tax point rather than the posting date, because that is when the transaction happened; the posting date is only when we wrote it down. A bill dated 28 March that arrives on 3 April belongs to our April and to March's rate.

**A missing rate does not stop the bill being recorded, and does stop it posting.** Entering a supplier's invoice is writing down something that has already happened, and a feed that has not run is our problem rather than theirs. The posting is where there is no safe answer: a rate of 1 asserts the two currencies are worth the same and balances while doing it, and today's rate values the purchase at a figure the document cannot explain. So the posting refuses and names the date a rate has to be loaded for.

Rounding each line to four places does not generally add up to the rounded total, and the difference cannot be hidden inside a translated line - `journal_lines` re-derives every functional amount from that line's own rate and refuses one that does not follow from it. So the residual is a line of its own:

```
Dr/Cr  rounding_difference    the residual from translating at the tax point's rate
```

in the functional currency and with no rate, because that is what it is: an amount that exists only because the books are kept to four places. Keeping it in one reconcilable account is also the only way anybody notices if it stops being pennies, which is how a wrong rate announces itself.

What this replaces is worth stating, because it was invisible: the rule previously labelled the document's own amounts with the entity's functional currency. A 1,000 EUR purchase posted 1,000 to expense and credited the supplier 1,150. The entry balanced - every line was wrong by the same factor - and the translation trigger on `journal_lines`, which refuses a foreign line with no rate, saw lines claiming to be functional and had nothing to object to.

### Duplicates: a hard half and a soft half

The **hard** half is the same supplier sending the same number twice, and it is a unique index on `(entity, supplier, normalised number)`. In the database rather than the application because an import, a second worker or a retried job does not go through the form, and because this is the most expensive error in accounts payable: it is usually discovered by the supplier, after the money has gone.

Normalisation keeps letters and digits and upper-cases them, so `INV-001`, `inv 001` and `INV001` are one document. **Leading zeros are kept**: `INV-001` and `INV-1` are frequently different documents from the same supplier, and collapsing them would refuse a real invoice. A false refusal is worse than a near-match warning, because the warning asks a person and the refusal does not.

The **soft** half is the same invoice arriving under a different number - a re-issue, a statement line keyed as an invoice, a scan of a copy. No constraint can refuse those, because sometimes they are genuinely two purchases. So they are warnings, shown side by side, and acknowledging one needs an explanation that is kept.

A separate warning covers the same number against a **different** supplier, which the unique index cannot see: that is a bill entered under the wrong supplier, which pays the right amount to the wrong account, and it is the error that is hardest to get back.

Detection runs at entry **and** at payment time, because the duplicate frequently arrives after the first copy was approved and by then nobody is looking at the entry screen.

---

## Credit notes

A credit note is a bill of another type, not a bill with a minus sign. `document_type` already distinguishes them (`380` an invoice, `381` a credit note, UNCL 1001), so a credit note carries **positive** figures and its own posting rule produces the opposite debits and credits:

```
Dr  accounts_payable                       the gross no longer owed
Cr  the line's expense or asset account    each line's net, plus its non-recoverable tax
Cr  vat_input @ the jurisdiction           the recoverable tax, given back
```

Storing it as a negative bill would make every query about "what do we owe this supplier" depend on remembering to sum rather than filter, and the first report that forgot would show a credit as a payable.

### A rule of its own, not the bill rule with the signs flipped

Because the two documents are not symmetrical in the one place it matters: **a credit note can be for less than the bill**. A negation of the original's rows credits everything the original debited in the original's proportions, and a partial credit is not in those proportions — a supplier who credits one line of a four-line invoice credits one expense account and the tax on that line.

Reversing the stored *entry* has the same problem from the other side: the ledger's reversal machinery exists for correcting an entry that should not have been made, and this entry should have been made. The credit note is a second real event, with its own date, its own period and its own number from the supplier.

Posting a credit note through the bill rule is refused outright. It would credit the payable and debit the expense, the entry would balance, and the supplier's balance would grow by the credit instead of shrinking.

### Three ways to raise one

In **full** — a return, a cancellation, a duplicate the supplier has accepted. By **line** — goods that came back, crediting the lines they came from and the tax on those lines. By **amount** — a settlement or a rebate, which has no lines behind it.

An amount-only credit is **apportioned, never assigned one rate**. A 1,000 settlement on a bill with 15% and 0% lines is not a 15% credit: choosing one rate would misstate both boxes of the return, and the error is in the input tax rather than in the expense, so the accounts still balance and nothing looks wrong until the authority compares the figures. Each share is split back into net and tax at its own line's rate, with the tax as the difference rather than a second rounded multiplication, so the two always add back to the share exactly.

### The reference is mandatory

A credit note either names the bill it credits or states the supplier's own reference in words, and `bills_a_credit_note_says_what_it_credits` agrees. A credit against nothing is a figure nobody can tie to a purchase, and it is the shape a supplier's balance takes when somebody has keyed a rebate twice. A volume rebate genuinely has no single invoice behind it, which is why the second form exists — but it still has to say what it is for.

### The ceiling

A credit cannot exceed the bill's remaining uncredited value. Checked in the service so the person gets a sentence that names what was charged and what is left, and enforced by a trigger so an import, a second worker or a retried job cannot get past it: two credit notes entered at the same moment against one bill would both pass a check that read the total before either was written. The same trigger refuses a credit note that crosses suppliers or currencies.

The ceiling counts **draft** credit notes; what the supplier is owed counts only **approved or posted** ones. Both are right and they answer different questions — two drafts could otherwise each pass the ceiling and together exceed the bill, while a draft counted as a reduction would make the aging report stop agreeing with the control account.

### Allocation posts nothing

A credit note and a bill are both in `accounts_payable` against the same party. Netting two items inside one control account for one counterparty changes no balance anywhere: the allocation row is what makes the open-item statement right, not an accounting event. A journal for it would write two entries that cancel.

The two dispositions are exclusive both ways round. A credit marked for refund cannot also be allocated, and an allocated credit cannot also be claimed back — either would reduce what we owe *and* collect the cash for the same credit. A credit note already referenced to a bill cannot be allocated to that same bill, which would reduce it twice. And allocation is bounded from both ends: not beyond the credit's own value, not beyond what the target bill still owes.

---

## Aging and statements

### The basis is a decision, and the report states it

The **due date** answers "what is overdue", which a payables clerk asks. The **document date** answers "how old is this balance", which an auditor asks about a supplier who has not been paid. Both are legitimate, they disagree by the payment terms, and a report that does not say which it used is a report two people read differently and both believe.

So `aging.basis` is an entity setting, every row carries the date it was aged from, and the header says which basis produced it.

### Instalments are aged separately

A bill on 30/60/90 terms has three due dates. Aging the whole bill from the last hides two months of overdue money; from the first, it makes two thirds of it look overdue when it is not due at all. On a large bill that is the difference between a supplier who is owed nothing yet and one who is about to stop delivering.

The amounts come from the **frozen** terms snapshot, so a report run next month ages the bill exactly as it aged it this month. A payment is applied to the earliest instalment first, which is how a supplier applies it unless told otherwise — spreading it proportionally would leave every instalment partly unpaid and each in a different bucket.

### The reconciliation is the report's own test

The aging total must equal the accounts-payable control account's balance. If it does not, one of them is wrong and both are used to pay people, so the report computes both and says so in its own output:

| Cause | What it looks like |
|-------|--------------------|
| A bill approved and never posted | the aging counts it, the ledger knows nothing about it |
| A journal posted straight to the control account | the ledger moves, no document moved |
| A payment recorded against the ledger and not against the document | the document still looks unpaid |

And it says when it was **not** attempted: filtered to one supplier a partial total cannot reconcile to a whole balance, and with foreign-currency documents present, comparing them to a functional-currency balance would report an exchange rate as a difference.

### Currencies stay apart

Buckets and totals are per currency, because 1,000 USD and 1,000 EUR are not 2,000 of anything and a combined figure would be read as something somebody could pay. A balance-forward statement refuses outright to add two currencies into one running balance.

### DPO

`(payables / purchases in the window) x days`, with the window stated. **Null rather than zero** where there were no purchases: zero reads as "we pay immediately", which is the opposite of what an empty window means.

### Statements

**Open-item** lists what is unpaid, which is what a supplier wants when they are chasing. **Balance-forward** lists every movement from an opening balance, which is what a reconciliation needs — it shows the payments and credits too, so the two sides can find where they diverged. A supplier who disputes a balance is usually disputing something one side has and the other does not.

The opening balance is computed from the documents rather than carried as a stored figure. A stored opening balance is a number nobody can check: the first time it disagrees with the documents, there is no way to tell which is wrong.

---

## Approval

The approval engine is shared: bills, requisitions and expense claims route through one mechanism rather than three.

**A document edited after approval returns to the start of the chain.** The engine fingerprints the fields that matter - via canonical JSON, so key order and whitespace cannot change the hash - and compares the fingerprint before accepting a decision. Without it, "approve, then change the amount" is the simplest fraud in the product.

**A delegate acts, and the record says so.** The naive implementation of "approve on behalf of" writes the absent person's id on the decision, which is a forged approval: the record says somebody approved a document they never saw, and the only way to find out is to notice they were on holiday that week. So the decision carries both the actor and the person they acted for, the delegation that permitted it has an end date, and the database refuses a delegation to oneself.

**A delegate cannot receive what segregation of duties forbids them**, which is the obvious way to launder a conflict: delegate to the person who created the document. The engine asks about the **actor**, so the conflict is caught where it arrives rather than where it was arranged.

**A limit is tested on the converted amount**, so a limit of 10,000 cannot be dodged by invoicing in a weak currency, and the rate is recorded on the request - "why did this need a director" is answered by the rate on the day the chain started, not by today's. A limit change is never retroactive: a change is a new row with its own window.

---

## Documents that arrive

An invoice we issue is composed. A supplier's bill is handed to us, by email or over a network, by somebody who may not be a supplier, in a format that may not be valid. So it becomes an **inbox item** first.

Turning it straight into a bill means a stranger's attachment creates a payable, and the worst version of that is an invoice from a supplier who does not exist for goods nobody ordered.

### Read into the model, not into a bill

`UblReader` reads a document into the **semantic model** — the standard's own model, not a document of ours — and the validation pipeline runs there. Only a document that passes every stage is converted, in one transaction. There is no state in which half a supplier's invoice is in our ledger: a failed import that left a draft bill nobody raised would be approved by somebody who assumes a draft came from a colleague.

The reader is checked against our own serializer: a real invoice is serialized, read back, and the business terms compared. A term the serializer emits and the reader drops is a field an inbound bill arrives without; a term the reader produces that the document does not carry is a guess wearing a business term's name. That is not a substitute for the official corpus (PF-128) and does not claim to be — it is the strongest check available without one.

### Three things the reader gets right that a naive one does not

- **Prefixes are not namespaces.** Every expression matches on `local-name()`, because `ns2:Invoice` is as valid as `Invoice` and several large finance systems emit `ns0:` throughout.
- **A CreditNote is a different document.** `cac:CreditNoteLine`, `cbc:CreditedQuantity`, `cbc:CreditNoteTypeCode` — a reader that knew only the invoice forms reads every credit note as having nothing on it.
- **BT-110 is on the document, not in the monetary total.** Looking for it inside `cac:LegalMonetaryTotal` finds nothing and reports a calculation rule against a document that was right.

### A failing document gets the standard's own answer

A UBL `ApplicationResponse` with the UNCL 4343 code and one `cac:LineResponse` per violation, naming the rule and its explanation. The sender's system raised the document and the sender's system is what has to be told; an email reaches a person who then has to describe a rule number to a developer.

`AP` accepted, `RE` rejected, `CA` conditionally accepted — the last for a document that passed every rule and whose supplier nobody recognises. Saying `AP` there would tell the sender their invoice is in our ledger when it is in a queue.

A rejection with no report is refused by the database: "rejected" is a verdict and a report is a reason.

### Supplier matching, and the match that does not exist

| Resort | Why it is one |
|--------|---------------|
| Electronic address (BT-34 **with its scheme**) | what the network routes on, registered by the supplier; the scheme is half the identifier, because the same digits are a company number in one registry and a VAT number in another |
| Tax registration (BT-31 or BT-32) | issued by an authority and unique within it |
| The sender allowlist | somebody deliberately associated an address with a supplier and is answerable for it |
| A person | the honest answer when the only remaining evidence is a name |

**There is no name match.** Two suppliers called "Gulf Trading" are two suppliers, a Latin transliteration of an Arabic name has at least four plausible spellings, and a bill paid to the wrong supplier is the hardest error in accounts payable to get back. A fuzzy match would be right most of the time, and "most of the time" on a payment instruction is not a standard anybody would defend out loud. The `match_method` column enumerates what is allowed, so the absence stays absent.

Note that BT-31 arrives with a country prefix — BR-CO-9 requires it — while `party_tax_registrations` holds the identifier as the authority issued it. The matcher tries both forms; storing the prefixed form instead would change what the tax engine, the returns and the printed invoice all read.

### Per-line tax is derived and reconciled

EN 16931 has **no per-line tax amount**. A line carries its category and its rate and the tax lives in the VAT breakdown per rate group. So each line's tax is computed from its own rate, and the group's total is then reconciled to the amount the document states, any remainder landing on the group's largest line.

Three lines of 1.03 at 15% derive 0.4635 where the group states 0.46. Without the reconciliation a bill built from a correct document would disagree with it, and the disagreement would be found by whoever reconciles the input tax to the return.

### Extraction, and the routing decision that matters most

A **Factur-X PDF is a structured document**. It carries the EN 16931 XML as an embedded file, so the figures are in it exactly — and an extraction pipeline that starts with OCR never asks. The result is a bill whose totals are nearly right, which is worse than one that is obviously wrong.

So the route is decided first, in a method of its own so it can be checked without an engine:

| The document is | The route | Why |
|-----------------|-----------|-----|
| XML | structured | nothing to extract; the inbound reader is exact |
| a PDF with an embedded invoice | embedded XML | the figures are in the file |
| a PDF without one | optical | the only way to read it, and it proposes rather than asserts |
| anything else | optical | as above |

The check for "never sent to OCR" uses a reader that records being called, because asserting on the proposal's source would pass even if the engine had been called and its answer thrown away. The cost of the call is the point: it is a guess where a fact was available.

### A proposal is not a value

An OCR reading is a machine's interpretation of a picture. It is recorded as a proposal with a confidence per field; fields below the threshold are flagged; a person confirms or corrects; and only then does a bill exist. `extraction_proposals_a_bill_was_confirmed_by_somebody` refuses a row that names a bill without a confirmation behind it — the point of the pipeline is to save typing, not to remove the decision.

A **confidence belongs to a guess**, and a trigger enforces it both ways: embedded XML carries none, because the figures were never in doubt and a 1.0 beside a fact is how a reviewer learns to ignore the column; an OCR field must carry one, because a reading stored as a plain value is a guess nobody can tell from a fact. A trigger rather than a CHECK, because the source is on the proposal and the confidence is on the field.

A correction is kept **beside** what was proposed. Overwriting loses the fact that it was misread, which is the input the accuracy figure needs — and that figure, per supplier template and worst first, is what makes a consistently misread format visible. It is a format to fix, by asking the supplier for Peppol or by teaching the engine, and a list ordered by name hides it among the ones that read perfectly.

### The engine is a declared absence

No OCR engine is installable here, so `extraction.driver` defaults to `none` and the reader **refuses** rather than returning nothing: "nothing could be read from this document" and "no engine is installed" look identical in a queue and mean opposite things — the first sends somebody to re-scan a legible invoice. There is deliberately no stub returning plausible figures, because a fake reading that looks real is how a suite comes to assert the behaviour of something that does not exist.

Everything around the engine is built and checked, which is where the risk is.

### Email intake

The intake address is `inbox+<entity code>@<domain>`, and **it is the only thing that decides whose queue a document joins** — not the sender, not the subject, not a header. A group with eight entities receives eight suppliers' invoices at eight addresses, and resolving the entity from anything the sender controls means the sender chooses which company's ledger their invoice lands in. An address nobody recognises resolves to nobody.

Attachments and bodies each become an item. Every attachment goes through the attachment store first — sniffed, capped and scanned — because the declared name and type come from the sender and are not evidence of anything, and scanning after parsing is scanning after the damage. An attachment the store refuses is **quarantined with the store's own sentence**, not dropped: "we would not open this file" and "we do not know this sender" need different answers, and a supplier told neither hears "we never received it".

The original is archived before anything is parsed, under `received_xml` — a kind of its own, because `sent_xml` answers "what did we send them" and this answers "what did they actually send us, as opposed to what our parser made of it". The same bytes delivered twice are one row: an access point retries, an email is forwarded twice, and the second row would become a second draft bill.

---

## Expense claims

The payable is to the **employee**, in `employee_payable` rather than `accounts_payable`:

```
Dr  the line's expense account              each line's net, plus its non-recoverable tax
Dr  vat_input @ the jurisdiction            the recoverable tax
Cr  employee_payable                        the gross owed to the claimant
```

An employee is not a supplier. The balance is reported differently in most frameworks, it is frequently settled through payroll rather than through the payment run, and an aging report that mixed the two would show a colleague's taxi fare beside a supplier whose terms are thirty days. It matters for fraud too: "who do we owe money to" with employees mixed into it is a list nobody reads closely, and an expense claim is the cheapest place in a business to take money out of it.

The claimant is a **user**, not a party, and the posting carries no party id. Modelling an employee as a supplier puts a colleague in the list every party-scoped control reads — the supplier bank-detail rule included, which is the highest-value fraud in the product.

### Input tax needs three things, and they fail differently

| Condition | What is missing without it |
|-----------|----------------------------|
| A receipt | evidence — the authority asks for the receipt, not for the spreadsheet |
| A category that permits recovery | entitlement, however good the evidence (client entertainment) |
| A policy in force on the day | a decision — "nobody decided" reads as "do not claim" on a tax return |

The decision and its reason are stored on the line, and a CHECK constraint refuses recovery without both a receipt and a stated reason. "Why was this claimed" has to be answerable a year later, when the policy has changed and the return has been filed; re-deriving it would answer with today's policy, which is not the question.

Non-recoverable tax rides with the line's own expense. Tax sitting in the input account that will never be claimed ties to no box on any form, and the difference is found during an audit by somebody who did not put it there.

### Rates are frozen, breaches are flagged

A mileage rate changes annually, so the rate in force on `incurred_on` is copied onto the line with the policy it came from. A claim re-derived at today's rate pays the wrong amount and cannot be reconciled to what was approved.

A line over its ceiling is **flagged with the figures, not refused** — a hotel over the ceiling because the conference hotel was the only one left is a real claim, and refusing it sends somebody to a spreadsheet and an email. But an unexplained breach cannot be submitted: a trigger on the claim's status refuses it, because the condition spans the claim and its lines and because the flag is raised by the system at entry while the explanation comes from a person afterwards.

---

## What is not built yet

| Gap | Why |
|-----|-----|
| Orders sent as a PDF, or by Peppol | The PDF toolchain (Gotenberg, Ghostscript, veraPDF) is not installable in this environment; Peppol transport needs the AS4 access point and SMP lookup of PF-121 and PF-122. The order document is a UBL `Order`, a different document type from the invoice the Exchange plane serializes today |
| An OCR engine. The pipeline around one is built: a proposal is not a value, a confidence belongs to a guess, a field below the threshold is flagged, no extracted bill posts without a confirmation, and a misread format is visible. The engine itself is a declared absence (PF-142) |
| Receiving a document over the network: the AS4 transport and SMP lookup | PF-121 and PF-122, which need an accredited access point. What happens after delivery is built |
| Reimbursing a claim through a payment run | The claim's balance sits in a control account a payment run can read, and the run exists (PF-174). What is missing is on the Settlement side: `PaymentRuns::selectPayables()` reads `bills` only and `payment_run_items` names a `bill_id`, so selecting a claim is a schema and selection change there rather than here |
| The receive-then-approve segregation-of-duties pair | Whoever confirms a delivery should not approve the bill for it, and the fictitious-receipt fraud is to confirm a delivery that never came and approve the invoice for it. But the two duties sit on different objects linked by an order, and the engine's scopes are the same document, the same counterparty or the same period. Closing it means a **chain** scope that expands a bill into the receipts behind it, which is a change to PF-033 rather than to this module |

See [`tasks/phase-07-purchasing.md`](../tasks/phase-07-purchasing.md) for the criterion-by-criterion state.
