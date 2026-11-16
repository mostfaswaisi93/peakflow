# The payable side

Purchasing is structurally the mirror of selling, with two things selling does not have.

**Documents arrive from outside.** An invoice we issue is composed: we choose its number, its dates and its lines, and we can refuse to issue one that does not make sense. A supplier's bill is handed to us. Its number is theirs, its date is theirs, and the only two decisions we make about it are which period to post it in and whether to pay it. Everything in this module follows from that asymmetry.

**Money leaving needs a second person.** Money arriving needs a correct invoice; money leaving needs somebody other than the person who arranged it to agree. Approval is therefore not a workflow feature bolted on at the end - it is the control the whole module exists to serve, and the segregation-of-duties engine is what makes it a control rather than a formality.

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

### Duplicates: a hard half and a soft half

The **hard** half is the same supplier sending the same number twice, and it is a unique index on `(entity, supplier, normalised number)`. In the database rather than the application because an import, a second worker or a retried job does not go through the form, and because this is the most expensive error in accounts payable: it is usually discovered by the supplier, after the money has gone.

Normalisation keeps letters and digits and upper-cases them, so `INV-001`, `inv 001` and `INV001` are one document. **Leading zeros are kept**: `INV-001` and `INV-1` are frequently different documents from the same supplier, and collapsing them would refuse a real invoice. A false refusal is worse than a near-match warning, because the warning asks a person and the refusal does not.

The **soft** half is the same invoice arriving under a different number - a re-issue, a statement line keyed as an invoice, a scan of a copy. No constraint can refuse those, because sometimes they are genuinely two purchases. So they are warnings, shown side by side, and acknowledging one needs an explanation that is kept.

A separate warning covers the same number against a **different** supplier, which the unique index cannot see: that is a bill entered under the wrong supplier, which pays the right amount to the wrong account, and it is the error that is hardest to get back.

Detection runs at entry **and** at payment time, because the duplicate frequently arrives after the first copy was approved and by then nobody is looking at the entry screen.

---

## Approval

The approval engine is shared: bills, requisitions and expense claims route through one mechanism rather than three.

**A document edited after approval returns to the start of the chain.** The engine fingerprints the fields that matter - via canonical JSON, so key order and whitespace cannot change the hash - and compares the fingerprint before accepting a decision. Without it, "approve, then change the amount" is the simplest fraud in the product.

**A delegate acts, and the record says so.** The naive implementation of "approve on behalf of" writes the absent person's id on the decision, which is a forged approval: the record says somebody approved a document they never saw, and the only way to find out is to notice they were on holiday that week. So the decision carries both the actor and the person they acted for, the delegation that permitted it has an end date, and the database refuses a delegation to oneself.

**A delegate cannot receive what segregation of duties forbids them**, which is the obvious way to launder a conflict: delegate to the person who created the document. The engine asks about the **actor**, so the conflict is caught where it arrives rather than where it was arranged.

**A limit is tested on the converted amount**, so a limit of 10,000 cannot be dodged by invoicing in a weak currency, and the rate is recorded on the request - "why did this need a director" is answered by the rate on the day the chain started, not by today's. A limit change is never retroactive: a change is a new row with its own window.

---

## What is not built yet

| Gap | Why |
|-----|-----|
| Orders sent as a PDF, or by Peppol | The PDF toolchain (Gotenberg, Ghostscript, veraPDF) is not installable in this environment; Peppol transport needs the AS4 access point and SMP lookup of PF-121 and PF-122. The order document is a UBL `Order`, a different document type from the invoice the Exchange plane serializes today |
| Requisitions, supplier credit notes, email and Peppol intake, document extraction, expense claims, AP aging | PF-130, PF-134, PF-140 to PF-144 |
| A bill in a foreign currency booking at its tax point's rate | The columns exist; the rate service call site arrives with PF-138 |
| The receive-then-approve segregation-of-duties pair | Whoever confirms a delivery should not approve the bill for it, and the fictitious-receipt fraud is to confirm a delivery that never came and approve the invoice for it. But the two duties sit on different objects linked by an order, and the engine's scopes are the same document, the same counterparty or the same period. Closing it means a **chain** scope that expands a bill into the receipts behind it, which is a change to PF-033 rather than to this module |

See [`tasks/phase-07-purchasing.md`](../tasks/phase-07-purchasing.md) for the criterion-by-criterion state.
