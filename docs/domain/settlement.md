# Settlement

Payments, unapplied cash and allocations — PF-166, PF-167, PF-168.

## The decision this module rests on

**A payment never touches a party balance.**

"They sent 10,000" and "10,000 of their invoices are settled" are different facts. They become one fact when somebody says which invoices.

Reducing the balance on receipt gives the right total and a customer ledger nobody can take apart: 4,000 outstanding that is three invoices and a receipt, with nothing saying which invoice the receipt was for. Every question about it afterwards is a reconstruction, and the reconstruction happens in a spreadsheet beside the accounting system.

So a receipt posts to the bank or cash account and to a **clearing position**, and an allocation is where a party balance moves — every movement on the balance naming the document it belongs to.

## Unapplied cash is a liability, and that is correct

Not an error state and not a holding pen. Money a customer sent that nothing has claimed is money being held for them.

Treating it as an error produces the two bad fixes: reduce the customer's balance anyway, which is the ledger nobody can take apart; or hold the receipt out of the books until somebody allocates it, which leaves the bank reconciliation carrying it for a month.

**Old unapplied cash is a misidentified payer, nearly always.** A receipt sitting unapplied for ninety days is rarely a customer who has not decided what it was for — it is a payer nobody matched to a party, or a payment recorded against the wrong one. So it ages, by **value date**, and the report says what an old one usually means rather than only what it is worth.

It appears on the **customer's statement**, because the customer is the only person who definitely knows what the money was for. A statement that lists their invoices and hides their payment starts an argument; one that shows both lets them answer in a sentence.

## The two invariants, in the database

An allocation exceeds neither the payment's unallocated amount nor the document's open balance. Both are sums across rows, so both are triggers — and both are in the database rather than in a service because this is **the** invariant that keeps the receivables subledger equal to its control account.

| Broken | What happens |
|---|---|
| Payment side | Cash applies twice. Two invoices report settled by one payment, the control moves by twice what arrived, and the only symptom is a bank reconciliation that will not balance |
| Document side | A document goes negative — a customer owing minus 400, which is a credit nobody granted |

A service can be bypassed. An import, a correction script, a bulk tool written next year, a half-finished migration: each reaches this table without passing one, and "the service is careful" is not an invariant.

The property check is the one that matters most: for any sequence of allocations, reversals and refusals, the sum of the documents' open balances equals the receivables control, **and** the unallocated cash across the payments equals the clearing account. A sequence can satisfy the first and leave the second wrong, and that error surfaces as a bank reconciliation nobody can explain.

## A payment is immutable once posted

Corrected by reversal, which is a posting of its own. "We received 10,000 on the 3rd and it turned out to be 1,000" is a fact about the 3rd, and editing the row deletes it. A trigger says the same thing from underneath.

Reversing a payment that has live allocations is refused: the cash underneath them would go and the documents would stay settled, which is a document reported as paid by money that was never received. Undo the allocations first, which is an ordinary thing to do.

Reversing an allocation is also a posting, and **re-allocating afterwards is ordinary** — somebody allocated to the wrong invoice, undid it, allocated correctly. The reversed row stays, because "it was against 4471 and somebody changed it" is what a customer disputing a statement is asking.

## Strategies propose; they never apply

`oldest first`, `by reference`, `instructed`, or a person allocating explicitly. Each returns a proposed set with the figures and the reason each document was chosen, and a caller applies it.

The same division the matching engine makes, for the same reason: a strategy that applied its own answer is one nobody inspects until it is wrong — and **"oldest first" is wrong whenever a customer has told you which invoice they are paying**. A quoted reference beats a date, because a payer naming a document is the intent stated rather than a guess about it.

A proposal never offers something that cannot be applied: it takes the smaller of what is left and what the document is owed, which is the same bound the database enforces.

## A currency mismatch is refused, never converted

A payment of 5,000 EUR into a SAR account would have to pick a rate, and the rate would be invisible in the figure that came out. Refusing costs somebody a conversation with their bank; converting silently costs an audit.

A cash method against a bank account is refused too — it puts a line on a bank reconciliation that no statement will ever carry.

## The method is UNCL 4461

The same code list the e-invoice carries. A payment recorded as "transfer" here and `30` on the document it settles is two vocabularies for one fact, and the mapping between them gets written twice and is wrong in one of them.

The subset is what this system can post. A code outside it is refused rather than stored: a method nothing knows how to post is a payment that reaches the ledger by whichever branch happened to be last.

## Over-payment, credits and refunds

An overpayment is **not a special kind of object**. The customer sent more than their invoices came to, the excess sits unallocated against them, and that is a credit they can use on their next document or ask to have back. Two ways to hold a customer's money would need a reconciliation between them.

A credit is shown as a credit — its own figure on the statement and a negative bucket in the ageing, never netted into what is owed. "You owe 4,000" and "you owe 5,000 and we are holding 1,000 of yours" lead to different replies, and only the second lets the customer tell you what the 1,000 was for.

### A refund is a document and a payment, never a negative receipt

A negative receipt nets against the customer's cash in every report that sums payments, so "how much have we refunded this year" becomes a query nobody can write — and that figure is the one a finance director asks for, usually after a chargeback.

It is also the shape fraud takes. A negative receipt looks like a correction; a refund has an amount, a reason of at least a sentence, an approver and a payment behind it, and the approver is a person whose name is on a row.

The movement itself needs no special posting rule: a refund is a **disbursement**, Dr unapplied cash and Cr bank — the exact opposite of the receipt that created the credit.

**Refunding more than the credit is refused.** The money beyond it was never theirs to get back, and paying it out is a payment on account to somebody who does not have one — which surfaces later as a debit balance on a customer nobody sold anything to.
