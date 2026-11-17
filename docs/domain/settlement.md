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

## Realized exchange differences

A document booked in a foreign currency was worth one figure in the books when it was issued, and the money that settles it is worth another when it arrives. The difference is **realized** — the money came, and it will not move again.

### The entry has three lines, and the third is left over

| Line | At which rate | Why |
|---|---|---|
| Dr unapplied cash | the payment's | That is what was credited when the money arrived |
| Cr receivables | the document's | That is what was debited when it was issued |
| Dr/Cr `fx_gain_loss` | — | Whatever is left |

Neither of the first two is a choice: each line reverses exactly what it reverses. The difference is left over **by construction** rather than computed, which is why it cannot be out.

A rule that posted both sides at one rate would balance, and would leave either the clearing position or the receivable holding a residue nobody can explain, in a currency nobody is owed.

### The rate is the payment's value date

The value date is when the money was ours. Using today's would make the same receipt worth a different figure depending on when somebody got round to entering it — a figure nobody can defend.

**Both rates are stored on the allocation**, with the figure they produced. "We used 4.00" cannot be checked; "booked at 3.90, settled at 4.00, so the difference on 500 is 50" is arithmetic an auditor re-performs in a line. They are stored rather than re-fetched because a corrected rate is ordinary — a provider restates, somebody fixes a typo — and a realized figure that moved with it would restate a gain somebody reported last quarter, silently.

A rate of exactly `1` is refused rather than stored for a functional-currency payment. It is what somebody fills in "for completeness", and it makes "which payments were translated" unanswerable — the one query written when a realized figure looks wrong.

### Proportional, because it is computed on the allocation

A partial settlement realizes a share of the difference, not the document's whole difference. That is the case that is easy to get wrong, because the figure that looks right is the one on the document. It is proportional by construction here: the arithmetic runs on the amount being allocated.

### Realized is not revaluation

`fx_gain_loss` holds what is settled; `fx_revaluation` holds what is still open and still moving. A report that added the two would count the same exposure twice — once as settled and once as outstanding — so a check asserts a realized difference never reaches the revaluation account.

### The worked example is committed

`tests/Fixtures/settlement/three-currency.json` holds three cases — the books' own currency (realizes nothing, and posts no third line), a euro invoice where the rate rose, and a dollar invoice where it fell and only half is settled — with the expected postings **written out by hand**. A fixture whose expected figures are computed by the code under test proves only that the code agrees with itself.

## Rounding differences

A customer pays 999.99 against 1,000.00. The penny is not a debt anybody is going to chase and not a discount anybody granted: two correct figures differ by the smallest unit the currency expresses, usually because one side rounded a tax line the other way.

**The receivable is closed and the difference is named.** Not quietly shrunk — a receivable reduced to make it agree with a payment is a figure that no longer traces to the invoice behind it, and the penny nobody posted is the one that makes a control account disagree with its subledger by an amount too small to investigate and too persistent to go away.

It runs through PF-164's classification rather than having a path of its own. The tolerance, the platform ceiling that bounds it, the approver above it and the report by type all live there already; a second rounding path here would be a second tolerance to keep in step, and the one that drifts is always the one nobody reads.

**Above the tolerance it is not a rounding difference.** It is a short payment, a charge, or something withheld, and which of those is a question for classification. Absorbing it into rounding is the quiet write-off the tolerance exists to prevent.

### A difference relieves its document

This closed a real divergence. A difference posts against a document and relieves the control account — and the subledger did not know, because a document's open balance was gross, less credit notes, less **allocations**, and a difference is none of those. So the ledger said an invoice was settled and the customer ledger said a penny was still owed.

It was found by a check asserting the document **closes**, not one asserting the posting happened — the difference between checking the consequence and checking the act.

The allocation guard had the same hole: a document part-relieved by a write-off could still absorb an allocation for its full gross, reaching the over-application the guard exists to refuse by a route it was not watching. Both now subtract what a difference already relieved.

## Write-offs

The one act in this module that destroys value on purpose. Every other path moves money between accounts or names a difference; this one says a receivable is not going to be collected and the money is gone.

That is why it is permissioned, reasoned by code, approved above a threshold and separated by duty from both invoicing and reconciliation — not because writing off is rare (it is ordinary) but because **a person who can raise an invoice and then write it off can move money out of a business with two clicks and leave nothing for a reader to find**.

### A code and a sentence, not one or the other

A sentence is read once, by the person who approved it. A code is countable: "how much did we write off after liquidation this year, and how does that compare with last" is the question a provision matrix is calibrated from, and prose cannot answer it.

The sentence is kept because a code cannot say which customer and which conversation. Without both, the report is either uncountable or unexplained. The refusal for an unknown code lists the ones in use, so somebody can pick the right one or decide none fits and add it.

Codes are **tenant-wide and carry no `entity_id`**. A reason code is vocabulary, not money: a tenant database is one customer, and the list of ways their debts fail is theirs rather than each legal entity's. The first version had a nullable `entity_id` meaning "a code the platform ships", and two checks refused it — a nullable `entity_id` under row-level security is invisible to everybody, and a table with the column and no policy crosses an entity boundary the moment anybody queries it. Neither wanted a cleverer policy; both were pointing at the column.

### It is a credit loss, not an adjustment to revenue

The sale happened at the invoiced figure and it is still that. What changed is that the money is not coming — a cost of having extended credit, which is IFRS 9's view and the useful one. Restating revenue would make the margin on that sale look correct while the cash never arrived, and it would spread the pattern of which customers do not pay across the revenue accounts where nobody can see it.

### A second name above a threshold, and not below it

The threshold is a tenant setting under a platform ceiling, and it is **not zero** on purpose: an approval step on every small write-off is a step people click through, and the habit carries to the ones that matter.

The approver cannot be the person who recorded it, and the threshold **that applied** is stored on the row — a tenant who raises it next week would otherwise make every past write-off look as though it had always been within one signature. Both are enforced in the table as well as in the service, because this is the control that stops one person moving money out of a business and a service is one code path.

### A recovery is not an un-write-off

Money that arrives afterwards is a **new receipt against a recovery account**. Reversing the write-off would say the conclusion had been wrong, and it was not: on the day it was made, with what was known, it was right. A customer who pays eighteen months after liquidation proceedings closed has not made the earlier decision a mistake.

Netting the two also destroys the only question that improves those decisions. "We wrote off 400,000 and recovered 30,000" is a sentence about how good the write-offs were; netted into one account it is a sentence about nothing, and nobody can tell a business that writes off too eagerly from one that writes off too late.

The document stays closed, and the recovery account is typed as **revenue** rather than a negative expense — an expense would let it net against bad debt in every report that groups by account type.

### A report by reason, period and customer

All three, because each answers a different question and only the three together are worth reading. By reason: which kinds of debt fail. By period: whether it is getting worse. By customer: whether it is one relationship or a pattern.

## One definition of an open balance

What a document still owes is its gross, less credit notes, less allocations, less what a difference relieved, less what was written off. Each term arrived with a task, and by PF-171 the formula existed **three times**: in the trigger that refuses an over-allocation, in the service that reports a balance, and in the query that lists open documents for a strategy.

They were kept in step by hand, and PF-171 showed what that costs — the difference term reached two of the three, and the one it missed reported a penny still owed on an invoice the ledger had settled.

So it is a SQL function and the three callers ask it. In SQL because one of them is a trigger and a trigger cannot call PHP; the other arrangement — PHP as the definition, the trigger trusting it — is the one that failed, since a service is one code path and an import is another. A term added later is a one-line change in one place, which is the only arrangement under which "they agree" is a property rather than a hope.
