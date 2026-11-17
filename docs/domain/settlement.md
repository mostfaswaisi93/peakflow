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

## Expected credit loss

IFRS 9's simplified approach, and the distinction everything here turns on:

**A provision is an estimate; a write-off is a conclusion.** One says a portion of what is owed probably will not arrive and is revised every period. The other says a particular debt will not, and is final.

They post to different accounts — `ecl_charge` against `ecl_provision`, versus `bad_debt` against receivables — so "how much of last year's estimate turned out to be real" stays answerable. That comparison is the only thing that calibrates the matrix, and a matrix nobody can calibrate is one somebody picked once.

### The matrix is data

A loss rate per ageing bucket per risk grade, with the **basis** beside it. A rate with no basis is a number somebody picked, and where the rates came from is the question an auditor asks about a provision.

Buckets are `current`, `1-30`, `31-60`, `61-90`, `90+` — fixed rather than configurable, for the same reason suspense ageing is: a matrix whose buckets differ between two entities cannot be added up, and a group provision is the figure most of this exists to produce.

A grade with no row falls back to the **ungraded** rate, not to zero. Most customers are ungraded in most businesses, so that row does the work — and being graded is not a reason to provision less for somebody. A bucket with no rate at all is **skipped and counted**, because zero would be a rate nobody set, reported as though they had.

### Ageing runs from the due date the document froze

Not its issue date. A customer on ninety-day terms is not overdue on day thirty-one, and a matrix keyed on the wrong clock provisions the patient customers while excusing the late ones. The due dates come from the terms snapshot taken at issue, so changing a party's terms later does not restate a provision already reported.

### Reversed and recomputed, never accumulated

Each run reverses the previous one **in full** and posts a fresh figure. Accumulating movements is the arrangement that drifts: a rate change, a regraded customer or a corrected invoice each leave a residue, and four quarters later the provision is the sum of four approximations nobody can take apart.

It costs more journal entries. It buys a provision that is one entry derived from one matrix and one set of open documents — re-performable rather than merely accepted. Every run also writes the figure it computed **per document**, with the grade, bucket, rate and balance behind it, so the total can be decomposed instead of re-derived.

### A write-off releases the provision it used up

The estimate was right about this one, so the provision is **utilized** rather than charged again. Without the release the period carries both: bad debt takes the full amount while the provision still claims part of it is coming, and the impairment line reads as twice the loss that happened — then recovers by itself next period, looking like a credit nobody can explain.

The release credits `ecl_charge`, never `bad_debt`. What is being undone is the estimate; netting it against the conclusion would leave the write-off reported at less than it was, and that figure must stay exact because it is what the matrix is calibrated against.

A partial write-off releases a part — releasing the whole provision would leave the remaining receivable unprovisioned until the next recompute, which is the opposite error and just as invisible.

### The roll-forward: five figures, and it ties

Opening, charge, release, utilization, closing. All five, because the closing figure alone says nothing about how it got there and "the provision went from 40,000 to 45,000" has four explanations that mean different things.

**Utilization is counted by the run it consumed, not by date.** A write-off on the same day the opening provision was computed is genuinely ambiguous on dates alone: the document was open when the figure was computed and written off afterwards, so the release belongs to the next period — and `released_on > previous.as_of` excludes it while `>=` would wrongly swallow releases the opening figure already reflects. The release records which run's provision it ate into, which is exact and independent of ordering within a day.

> A check caught that. The write-off vanished from the roll-forward and the five figures still tied, because a utilization counted nowhere is indistinguishable from one that never happened.

## The payment run — PF-174

The largest single movement of money most businesses make in a week, and the place where a mistake leaves the building.

### Three states, and exactly three transitions

```
draft → prepared     somebody selected what to pay
prepared → draft     the selection was wrong, and is being redone
prepared → released  somebody else sent it
```

Nothing else. There is no way out of `released`: a mistake is corrected by a new run and a recall request, because the money has left and an edited instruction would describe a payment the bank never made.

All of it is enforced in the database — the transition set as a trigger, the attribution of both acts as column constraints, and the lines of a released run frozen against insert, update and delete. A state machine that lives only in the service is a state machine a single `UPDATE` walks past, and this is the one table where that matters most.

### Selection: due by the pay date, or offering a discount that expires

A run pays what is due by the day it pays. That default is stated rather than inherited: paying ahead of the due date is an ordinary thing to want, so it is asked for by name (`horizon_days`, or `due_by`), because a default horizon of a fortnight would move cash out early on every run nobody thought about.

The second arm of the selection is why a payment run exists at all in most businesses. A bill not due until August that offers 1.5% until the twenty-second is the highest-return decision a finance team makes in a week, and a selection that only looked at due dates would miss every one of them. So a discount whose window closes by the pay date pulls its bill into the run — while a bill that is neither due nor discounted stays out, since paying it early costs cash for nothing.

Only approved payables, always. The approval is the whole control on the purchasing side, and a run that could reach past it pays what nobody checked.

### The preview writes nothing

Somebody has to see the figure before they commit to it, and seeing it afterwards is not the same thing. So `preview()` returns the items, the instructions, the cash impact and the payables it could **not** pay — and creates no run, because a preview that wrote rows would hand a releasable instruction to anybody who asked a question.

### Discounts are evaluated at preparation and then left alone

A run prepared on Tuesday and released on Thursday pays what Tuesday's evaluation said. Recomputing on release would quietly change the amount the releaser approved, which is the one thing maker-checker exists to prevent.

A discount whose window has closed by the pay date is **not taken**, and the item says so in a sentence. Deducting it anyway is the mistake that turns a supplier relationship into a dispute over a figure nobody can explain — and the supplier is right.

### Grouping and splitting answer different questions

**Grouping** is the beneficiary's call. Four bills to one supplier normally become one transfer: four transfers cost four fees and arrive as four lines the supplier has to match against four invoices. But some counterparties reconcile per invoice and cannot match a lump sum to anything — a factored receivable, a franchise accounting per branch, a utility whose system keys on the account rather than the payer. For them one transfer is worse than four, and the only party who knows which kind they are is the supplier. So it is a stored preference, `group_payments`, defaulting to grouping.

**Splitting** is a fact about the rails. Where a beneficiary's bank, scheme or own mandate caps a single credit, the instruction is split at the cap and every part records `sequence`, `of` and the reason. A rejected file is worse than two transfers: the bank returns the whole batch, the supplier is paid nothing, and it is discovered when they chase. A limit is recorded only when somebody knows one — and with the basis beside it, because a bare number is a figure whose origin is lost the week after it was typed.

### A supplier with nowhere to pay is reported, not dropped

Two cases, and they are told apart:

- **No account, or none active in the run's currency.** There is nowhere to send the money.
- **Details changed and nobody re-verified them.** This is the invoice-fraud path: an invoice arrives with new bank details, somebody updates the supplier, and the next run pays a stranger. So `requires_reverification` is an **exclusion**, not a warning on a screen somebody may not read.

Either way the payable appears in `blocked` with its reason, and the run's summary says how many were left out. A payable that was silently omitted is one whose author discovers the omission when the supplier chases.

### The duty pair that can never be overridden

`prepare_then_release`. Arranging where money goes and then sending it is the highest-value fraud in the product, so it is one of the two rules the override mechanism cannot touch.

Until this task it enforced nothing: both duties were declared gaps, because no code recorded either act. `prepare()` now records `payment_run.prepared` and `release()` records `payment_run.released`, so the rule matches — and the refusal is also a column constraint, because an import or a repaired row is a second door into the same table.

> **A latent defect this work surfaced.** `bills.status` was `varchar(16)` while its own check constraint permitted `'awaiting_approval'` — seventeen characters. The state between entering a bill and approving it could never be stored: the constraint called it legal and the column refused it, with the error arriving as a string-length complaint rather than anything about approval. Nothing had noticed because nothing wrote it, and `DuplicateBillDetector` had been filtering on a value no row could hold. `purchase_orders.status` had the same pair of facts. Both are now 24, matching `expense_claims` and `purchase_requisitions`.

## Maker-checker and step-up — PF-175

The single highest-consequence control in the product: the last gate before money leaves the business.

### Five controls, in the order they run

| | | |
|---|---|---|
| 1 | The run is prepared | A draft has nothing anybody checked |
| 2 | The releaser is not the preparer | By the column **and** against the audit trail |
| 3 | The duty pairs | Per beneficiary, because one of them is scoped to the party |
| 4 | A fresh factor, now, for this run | A stolen session passes every check that looks at the session |
| 5 | Nothing has moved since preparation | Or maker-checker is two people clicking the same button a day apart |

**The order is not arbitrary.** Everything that answers "you may not do this at all" comes before the factor: asking somebody to re-prove themselves for an act they can never perform wastes a code and reads as the system not knowing its own rules. The review comes after, because it names what the run is paying and whoever holds the session has not yet shown they are the account's owner.

### The audit trail, not the column

`prepared_by` is one column on one row. A repaired row, a restored backup or an import that cleared it leaves the obvious check comparing against null and passing.

So the control reads the trail, which is a hash chain nobody can quietly edit and the record that says what actually happened. Scoped to this run: preparing one run does not bar somebody from releasing another, and a control that did would stop a two-person finance team working at all.

### Two pairs, scoped differently

`prepare_then_release` is scoped to the **object** — did you prepare this run. `beneficiary_then_release` is scoped to the **party** — did you change this supplier's bank details, which is the pair PF-061 exists for. A person who can point a supplier's account at their own and then send the payment needs no accomplice.

A run pays many beneficiaries, so there is no single party id for it. The assertion therefore runs **once per distinct beneficiary**, carrying both keys, so each rule can see what it is scoped to.

> **A defect the first authenticated check found.** `release()` asserted the duty once for the run with no party id, and the engine refused outright — a party-scoped rule cannot be evaluated without one, and skipping it would silently disable the control. The engine was right to refuse. Nothing had noticed because no check had signed a real user in, so the assertion had only ever run in the path where the engine returns early for want of an actor.

### Step-up: a stolen session is not enough

Asserted inside the service, not on a route: this method is reached from a console command, a queued job and an API endpoint that does not exist yet, each of which is a route a middleware does not protect.

**Object-scoped**, so re-proving yourself to release Tuesday's run does not authorise releasing Thursday's in the same five minutes. That is the half that gets dropped, because an unscoped assertion makes every test pass.

### Any change invalidates the preparation

Somebody prepared this run against a state of the world: these payables approved, these beneficiaries verified, these accounts on file. If anything in it has moved, what the releaser approves is not what was prepared.

So the run goes back for re-preparation. Re-preparing costs minutes; releasing a stale run moves money to a destination nobody checked.

What counts as a change:

- **A beneficiary's details changed and nobody re-verified them.** The invoice-fraud path: new details arrive, the supplier record is updated, and the pending run pays whoever sent them.
- **A beneficiary's account changed and *was* re-verified.** Still a change. The colleague who verified it knew nothing of this pending run — everybody did their job and the money would go somewhere the approver never saw.
- **A beneficiary account withdrawn or gone.** The transfer has nowhere to go.
- **A payable no longer approved.** The run would pay what nobody currently stands behind.
- **A payable worth less than when it was selected.** A credit note, a write-off or somebody else's payment landed in between, and paying the prepared figure would over-settle it. *More* owed is not a change that invalidates anything: the run pays what it prepared and the rest waits for the next one.

### "Has the destination changed" is a question about content, not a clock

The first implementation compared the account's `updated_at` against the run's `prepared_at`. Both are stored to the second, so an account changed in the same second as the preparation compared equal and reported nothing. In production the gap is days and it would have worked for years; the check that exercised it found the hole on the first run.

And a timestamp is the wrong question anyway. "Was this row written after that one" is not "is the money still going where somebody checked": a row rewritten with the same content has changed nothing, and a row changed and changed back is also nothing.

So each instruction stores `account_fingerprint` — a hash of the destination's tail, BIC, country and active flag as they stood at preparation. The review recomputes and compares. Clock-free, survives a replica read, and silent about a bookkeeping column being touched. Deliberately **not** over `verified_at`: an account re-verified with no change to where the money goes is not a change to where the money goes, and blocking on it would be noise.

The column is nullable, because a run prepared before it existed has nothing to compare — and "we cannot tell" reported as "it changed" would block every release across the deploy with a reason nobody can act on.

### The hash is of what was authorised, not of a file

PF-175 asks that the released file be identifiable afterwards. PF-176 is what generates the file, so what `released_hash` holds is the **content the file will be built from**: the run's identity and every instruction's beneficiary, account, amount and position, in a fixed order, canonicalised.

That is the better control either way. A hash of a file proves which bytes went out; a hash of the instruction set proves **what was authorised**, and the file must then match it — which PF-176 can assert and could not against a hash of itself.

Ordered by instruction id, because two instructions can share a party, an amount and a sequence — a split to one beneficiary in a run that also pays them separately — and a hash whose input order depends on how a query happened to return rows is a hash that changes without the content changing.

It is written in the same statement as the release, so there is no instant in which a released run has no hash, and the run is frozen immediately afterwards — which makes that the last moment the figure can be recorded at all. It also goes into the audit trail, because the row is frozen and the trail is what survives a restore.

### The screen asks before the button is pressed

`beforeRelease()` returns the run, the changes, the hash, whether the viewer is the preparer and whether a factor is needed. A release refused at the last step is a release somebody has already decided to make, and the diff arriving then reads as an obstacle rather than as information.

> **What the bite sweep took three attempts to catch.** Removing `orderBy('id')` from the hash input — leaving the order to whatever a query returned — fired no check twice over. The first version of the check rewrote every instruction in one statement, giving them all the same timestamp so any ordering agreed by accident; the second touched the row that was *already first* by id, which a descending timestamp order also puts first. Only moving the row that is **last** by id to the front of a timestamp order tells the two apart. A hash that moved there would stop matching the file after a vacuum, an index rebuild or a replica read, for reasons nobody could explain.

## The payment instruction file — PF-176

### A payment file is rejected as a whole

One beneficiary name with a forbidden character, one remittance line two characters too long, one execution date on a Sunday: the bank returns the batch and **nobody is paid**. The rejection arrives hours later, by email, naming an XPath into a document the person who built it cannot read.

So every constraint the scheme imposes is declared as data and checked **before the file is produced**, against the run somebody is looking at, with the beneficiary named. That is the difference between a control and a log.

Everything is reported at once rather than the first problem only. A run of forty transfers with three problems should report three: throwing on the first means three rounds of fix-and-retry, each of which is a person waiting on a file, and the third round is where somebody starts editing the XML by hand.

### The scheme is on the account, and there is no default

Not on the entity and not in a global setting. An entity with a euro account in Frankfurt and a riyal account in Riyadh sends SEPA from one and a local file from the other, and a product with one hard-coded format serves whichever customer it was written for.

`bank_accounts.payment_scheme` is nullable with **no default**, and an account that declares none cannot produce a file — the refusal names the schemes that exist. A default would pick one, and the first time it picked wrong the file would be built to the wrong rulebook, pass every check in this product and be rejected by the bank.

### Three levels, and the grouping decision between them

| Level | Element | One per |
|---|---|---|
| Message | `GrpHdr` | File |
| Payment information | `PmtInf` | Debtor account, execution date, service level |
| Transaction | `CdtTrfTxInf` | Instruction |

A run pays from one account on one date, so this produces one block — but the structure is built as a block rather than flattened, because a run paying from two accounts would be two blocks and nothing else about the file would change, whereas a flattened generator would have to be rewritten.

`BtchBookg` is `false`, deliberately: it asks the bank for one debit per transaction. Both are legal and PF-156's pass 2 matches either, but a statement with a line per transfer is one a person can reconcile against the run's instructions by eye.

### Element order is not a style question

Every ISO 20022 complex type is an `xsd:sequence`. The serializer builds children in whatever order reads best and the writer sorts them into a declared sequence table; an element with no declared position is an **error** rather than something appended at the end.

A check walks the produced file and asserts every element is where its sequence says — which asserts the *table* rather than the writer. A sequence with two entries transposed produces a file that validates in this product and fails at the bank, and nothing else would catch it.

### Determinism, because the file is hashed

The same run renders byte-identically: no timestamps generated at render time, no ids from a sequence, no formatting that depends on nesting depth. The message id and creation time are passed in. Instructions are ordered by id — the same order PF-175's hash uses, for the same reason.

### What the counterparty will read

The EPC restricted set is letters, digits, space and `/ - ? : ( ) . , ' +`. Nothing else.

| | | |
|---|---|---|
| `Müller & Søn GmbH` | → | `Mueller AND Soen GmbH` |
| `Société Générale` | → | `Societe Generale` |
| `Straße 1` | → | `Strasse 1` |
| `شركة الخليج للتجارة` | → | `SHRKH ALKHLYJ LLTJARH` |

The Latin expansions are the conventional ones and are **not** reported as lossy: a German supplier reads `UE` as their own name and `U` as a typo. The ampersand matters most — forbidden, extremely common in trade names, and dropping it turns "Smith & Sons" into "Smith  Sons".

**Arabic is lossy and says so.** Arabic script does not write short vowels, so `محمد` can only become `MHMD` by a strict letter mapping or `MUHAMMAD` by guessing. This does the first and never the second: a guessed vowel is a different name, and the beneficiary's bank matching a name against their records is who decides whether it is close enough. The result is shown with the original beside it and a sentence saying it can be overridden.

Not `iconv('ASCII//TRANSLIT')`: its output depends on the locale and the libc build, so the same name transliterates differently on a developer's machine and in production — which for a hashed file means the bytes that were hashed are not the bytes that go out. It also drops what it cannot map, silently.

### Bound to what was authorised

The file carries the run's `released_hash` as `authorised_hash`, and the database refuses a file whose hash is not the run's own. So "is this the file for what was approved" is a string comparison rather than a reconstruction from two tables and an argument — which is exactly why PF-175 hashes the instruction set rather than a file.

### Handed over once

A payment file is an instrument. Two copies in circulation is two chances for somebody to upload the second one after the first was processed, and the bank's duplicate detection is not something to rely on.

The first hand-over is recorded and the database refuses a second — including clearing the column, which is the obvious shortcut. Regeneration supersedes with a reason and a new message id, because a bank rejecting a duplicate message id rejects the whole file and the usual cause is a regeneration reusing the id of the one already accepted. Nothing is deleted, and a payment file cannot be deleted at all.

> **A control that would have created the hole it guards.** Reading a beneficiary's IBAN went through `BankDetailDisclosure`, which requires `bank.manage` — and that is the permission that lets somebody **change** supplier bank details. Requiring it to produce a payment file would have handed it to every treasury clerk who needs to send one, and `beneficiary_then_release` would have started firing on people doing their jobs. A payment file is not a disclosure to a person: the number goes into a machine-readable instruction, and the producer has already been through maker-checker, step-up and a review of what changed. So the file path asks for `payment.release` — the duty that already governs money leaving — and records the read under `party.bank_account.instructed`, so an investigation can tell "somebody looked at this account" from "this account went into a file".

> **And the fixtures had to become three people.** The first draft of the file checks signed one user in to do everything, and `beneficiary_then_release` refused every release. The second separated the supplier maintainer and was refused by `prepare_then_release`. The third needed a real enrolled factor on top. The product requires three people and a second factor to send money, so a fixture that wanted a file had to supply all of it — which is the controls working on the checks written to exercise them.
