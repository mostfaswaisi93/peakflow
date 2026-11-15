# Phase 05 — Quotes, orders, invoices and credit notes

**21 tasks · 110 points**

The first phase that produces something a customer sees. It depends on the ledger and the tax engine being finished, because an invoice is a document **plus a posting plus a frozen tax treatment**, and building the document first produces a system where invoices exist and the books do not reconcile.

Two tasks in this phase carry disproportionate risk. `PF-093` is gapless numbering, where a gap is a finding in a tax audit. `PF-095` is the issuing transaction, which must be atomic across five separate concerns or leave the system in a state nobody can describe.

---

### PF-089 · Quotes
`Sales` · **5 pts** · depends: PF-065, PF-082 · standards: —

- [ ] Draft, send, accept, decline, expire, with a validity date and automatic expiry
- [ ] Versioning: a revised quote is a new version with the previous one retained and comparable
- [ ] No posting and no number from a legal series - a quote is not an accounting document
- [ ] Accepting produces an order, or an invoice directly where the tenant has no order step
- [ ] A customer-facing view by signed link, with accept and decline, and no login

### PF-090 · Sales orders
`Sales` · **5 pts** · depends: PF-089 · standards: —

- [ ] Order from a quote or from scratch; confirmed orders show against the party's credit exposure
- [ ] **Partial invoicing**: an order may be invoiced across several invoices, with the uninvoiced balance tracked per line and not merely per order
- [ ] Over-invoicing an order is refused; cancelling an order with invoiced lines is refused with an explanation
- [ ] Optionally posts a commitment for budget visibility, never a financial posting

### PF-091 · Invoice schema with a frozen snapshot
`Sales` · **8 pts** · depends: PF-082, PF-071 · standards: EN 16931 · `risk:high` `type:schema`

- [x] `invoices`, `invoice_lines`, `invoice_tax_breakdown`, `invoice_allowances_charges`, `invoice_references`
- [x] The document type is a UNCL 1001 code, stored as the code - `380` rather than `commercial_invoice`, because the code is what goes on the wire and a name in the column means a mapping at the boundary, which is a place for a document to acquire the wrong type on the way out
- [x] **Frozen at issue**: both parties' legal names, addresses and tax registrations; the entity's own legal identity; every applied tax rate; the FX rate; the payment terms; the derived due dates. The **dates**, not just the terms: "net 30 from the end of the month, adjusted for weekends" resolves through a policy an operator can change, so a document storing only the terms would have its due date move - and a dunning run would chase an invoice that was not yet due
- [x] A line carries the item reference *and* its own description, so changing an item's name later does not rewrite history. The reference is what a report groups by; the description is what the customer read
- [x] Totals stored at the amount scale, with the presentation exponent recorded separately. Recorded rather than derived at render time, because ISO 4217 has changed one - and a reprint has to show what was shown
- [x] A test changes a party's name and a tax rate after issue, and asserts the issued invoice is unchanged in every respect. It also asserts the world really moved, because a check where nothing changed proves nothing
- [x] There is **no `cancelled` status**. An issued invoice is immutable by trigger in every column, and a document that should not have been issued is credited (PF-097) or corrected (PF-098) by another document that references it. A status somebody can set to `cancelled` is a document that disappears from a return it was already in
- [x] The parts are immutable too - lines, breakdown rows, allowances and references - by a second trigger that refuses INSERT as well as UPDATE and DELETE once the header is issued. A line edited after issue changes the document as surely as editing its header does, and it is the easier mistake to make because a line looks like data
- [x] The number exists **exactly when** the document is issued, both halves in one CHECK: a draft with a number has consumed one from a gapless series and may never be issued; an issued document without one is a legal document with no identity
- [x] The totals tie in the database - gross is net plus tax, payable is gross less prepaid - because a document whose own totals disagree is one nobody can check against anything else
- [x] Found on the way: the bite that froze **today's** registration instead of the tax point's passed silently, because the fixture gave the party only one registration. It now holds two - the party was renumbered on 1 June and the document is dated 18 May - and the bite fires on the identifier

### PF-092 · Draft editing and validation
`Sales` · **5 pts** · depends: PF-091 · standards: EN 16931

- [x] A draft is freely editable, has no number, no posting and no effect on any balance. All four asserted together, because the three absences are what make the first one safe: a document somebody can still change, that already moved a balance, is a document in the books nobody agreed to
- [x] Validation runs continuously and shows **what would prevent issuing**, addressed by field, rather than failing at the end. `InvoiceFinding` carries `lines.3.tax_code_id`, not "a line has no tax code" - on a fifty-line invoice the difference is twenty minutes. The engine is here and proven; the surface that calls it on every change arrives with the sales screens in Phase 11
- [x] The internal rule set: totals consistent, party has a registration where the tax treatment requires one, period open, currency enabled, evidence present for the tax category, place-of-supply rule resolved. Sixteen codes, each with a check that breaks exactly one thing and asserts both the code and its address
- [x] **Blocking and advisory are not the same list.** `blocking()` is what the issuer consults. A category K line's missing evidence is reported and does not stop the document, because a zero-rated export whose shipping paperwork has not arrived yet is still an invoice the business has to send - and the evidence gate is PF-080's business at return time. A check proves the advisory does not block, which is the half somebody breaks by treating the list as flat
- [x] A draft can be duplicated, and duplicating never copies the number or the posting. Seven columns named rather than `replicate()` with an exclusion list, so a column added next year is absent until somebody decides it belongs. References are deliberately not copied: two credit notes pointing at one invoice, each claiming to be the credit, is a reference an auditor cannot resolve
- [x] Found on the way: the duplication check duplicated a **draft**, and a draft has no number - so the bite that copied the number across copied a null and the check stayed green with the bug in place. It duplicates an issued document now, which is what anybody actually duplicates

### PF-093 · Gapless number allocation
`Sales` · **8 pts** · depends: PF-071 · standards: — · `risk:high`

- [x] Allocation by `SELECT ... FOR UPDATE` on the `number_series` row inside the issuing transaction. A check allocates inside a savepoint, rolls it back and asserts the counter is where it was - the property a sequence does not have
- [x] A comment in the code records why a PostgreSQL `SEQUENCE` cannot be used: `nextval()` is deliberately non-transactional, so a rollback does not return the number and every validation failure, deadlock and crash leaves a gap. Asserted as text, in the file somebody would reach for one in, because that is the only place the reasoning is any use
- [x] **Allocate last** - after validation, tax computation and the posting rule have all succeeded - so the series lock is held for as short a time as possible. Asserted by reading the issuer's source with its comments stripped, because it is the one property a single-threaded check cannot see. Moving the allocation to the top does **not** produce gaps - the transaction still returns the number - it destroys concurrency, silently, which is why the assertion is on the order and not on the outcome
- [x] **No I/O inside the lock**: no PDF render, no clearance call, no mail. A tax authority that takes thirty seconds to answer must not hold the invoice sequence for thirty seconds. There is no `dispatch()` in the issuer at all: `afterCommit()` returns the three jobs for the caller to queue, and a check asserts both halves
- [x] Lock scope is one series, so two entities or two document types never contend. An invoice and a credit note draw from different series and start their own chains; a check asserts the credit note is index 1 with the genesis hash while the invoice series is already running
The concurrency proof itself is `PF-094`, and it is what turns all of the above from arguments into measurements.

### PF-094 · The gapless concurrency proof
`Sales` · **5 pts** · depends: PF-093 · standards: — · `risk:high`

The single most important test in the suite, and the one that fails if someone "optimizes" the allocation by moving it earlier.

- [x] 200 invoices issued from 20 parallel workers against one series; the resulting numbers are exactly `1..200` with no gaps and no duplicates. Twenty real OS processes, each booting the framework and resolving the tenant for itself, because a loop in one process proves that the allocator can count - which nobody doubted. Measured at roughly 30-50 documents a second on one container, and the chain is walked afterwards as well as the numbers counted
- [x] A variant where a third of the transactions fail **after** the lock point, asserting the successful ones remain gapless. Failing before the lock proves nothing: the number was never taken. These workers wrap the issuer in their own transaction so its commit becomes a savepoint release, reach the allocation, and then roll the whole thing back - the case `nextval()` cannot survive, and the one that would leave four permanent holes
- [x] A variant with two series under concurrent load, asserting they do not block each other. Asserted by holding the invoice series' lock open in this process for certain and requiring a credit note to be issued anyway, rather than by comparing timings: a check that asserts "B was faster than A" fails on a loaded machine and teaches people to re-run it
- [x] A variant asserting a crashed worker does not consume a number. `SIGKILL`, not `exit()` - PHP's shutdown runs on `exit()`, and a shutdown handler that committed would make the probe lie. PostgreSQL sees the connection drop with a transaction open, and the number comes back
- [x] **It found a real bug on its first run.** First use of a series was a plain `INSERT`: two workers found no row, both inserted, one lost on `number_series_one_per_scope`, and that worker's entire document failed - for a reason with nothing to do with the document. It is `INSERT ... ON CONFLICT DO NOTHING` followed by a re-read under the lock now. A plain insert is correct in every single-threaded test and wrong on the morning a new year's series opens, when every worker in the entity tries to create it at once
- [x] And the race is now **forced rather than raced**. Re-running the burst did not reproduce it: whether two inserts land in the same microsecond is luck, and a check that catches a bug one run in three is a check people re-run until it passes. This process inserts the series row and holds the transaction open, polls `pg_locks` until a backend is genuinely waiting, and only then commits - so the collision is certain. Measured: polling `pg_stat_activity.wait_event_type` instead saw nothing at all, because those columns are not reliably visible to an unprivileged role
- [x] And the over-credit cap, under a forced interleaving: a credit note held in an open transaction until another backend is genuinely blocked, with the second one taking the raw path - one `UPDATE`, no issuer - because every path through the issuer waits on the number series lock first, and a check that went through it would be measuring that lock
- [x] Runs in the `concurrency` suite against real PostgreSQL. Not in `bin/verify all`: it writes committed data, which is the only way separate processes can see the fixture, and it cleans up afterwards as the role that **owns** the tables - because the application role cannot delete an issued invoice, which is PF-010 and PF-091 working. The audit records are deliberately left behind: deleting them would leave the tenant's audit chain pointing at a record that no longer exists
- [x] Found on the way: moving the chain link to before the allocation does **not** break the burst. The posting step takes the sales journal's own series lock, which serialises every document in the entity anyway, and `(entity_id, series_key, chain_index)` is unique. The reordering is still a defect - it relies on another lock for its correctness - and it is caught by the source-order assertion rather than by an outcome that stays right for reasons nobody chose

### PF-095 · The issuing transaction
`Sales` · **8 pts** · depends: PF-093, PF-047, PF-009 · standards: — · `risk:high` `type:posting`

Five concerns, one transaction. It either all happens or none of it does.

- [x] In order: validate, compute and freeze totals, apply the posting rule, allocate the number, write the hash-chain link, commit - then dispatch the post-commit jobs. Validation runs **inside** the transaction rather than before it, because the period could close and a registration could lapse between a check and a write, and the check that passed is the one somebody would quote afterwards
- [x] The journal posting is synchronous and inside the transaction; the PDF render, the delivery and the clearance submission are queued after commit. Returned rather than queued: a job dispatched inside a transaction that then rolls back is a job about a document that never existed
- [x] A failure at any point leaves no number consumed, no posting and no document in an issued state. Five injected failures, each asserting the series counter, the journal-entry count and the document's status afterwards
- [x] **The hash is taken over the stored row, not over the model that wrote it.** Measured: hashing the in-memory attributes froze `totals.prepaid` as absent while the row holds `0.0000`, and three genuine documents - issued through the real path, untouched afterwards - were reported as diverging at `totals.prepaid`. A verifier that cries tampering over a column default is a verifier nobody runs twice. The issuer re-reads the row first, and a check issues from a deliberately unread draft to prove it
- [x] The issued event carries enough for every downstream consumer without re-reading, so a consumer cannot observe a half-written document. The audit record carries the number, the gross, the hash, the chain index and the journal entry id, and a check asserts all five
- [x] A test injects a failure at each of the five steps and asserts the system state afterwards in each case. Three are reachable from outside and are checks: validation (four shapes), the freeze (a customer with no legal name), and the posting rule (a line with no revenue account). The allocation and the chain write cannot be made to fail from outside without changing the code, so they are covered by the bite loop instead - moving the allocation earlier and off the transaction, and making the chain link always claim the genesis, each turn a check red

### PF-096 · The hash chain
`Sales` · **5 pts** · depends: PF-095 · standards: SHA-256, XML C14N, RFC 8785 · `risk:high` `type:compliance`

- [x] `canonical_hash`, `previous_hash` and `chain_index` per issued document, per series, with a CHECK that the three are present or absent together - a document with a hash and no position in the chain cannot be walked to
- [x] The hash covers the legally significant fields only, canonicalized, so a cosmetic change cannot invalidate a chain and a material one cannot avoid it. Asserted in both directions: the frozen bytes must contain the number, the totals, both parties, the lines, the breakdown and the tax decision, and must **not** contain the note, the timestamps, the presentation exponent or who issued it. A chain that breaks when somebody fixes a typo is a chain people learn to ignore
- [x] The canonical form is **stored**, not only hashed. A verifier holding a hash alone can say "this is not what it was", which is true and unhelpful; holding the bytes it can say the first field that differs, its old value and its new one
- [x] `invoices:verify-chain` re-walks a series and reports the **first** divergence, with the document and the field that differs. The checks are ordered deliberately: the index gap first, then the previous-hash link, then whether the stored bytes hash to the stored hash - the frozen bytes having been edited is a different and louder fact than the document having changed - and only then the field-by-field comparison
- [x] A document removed from the middle of a series is caught as a gap, not as a content mismatch. "Index 3 is missing" is the finding an auditor acts on; "document 4 does not match its bytes" is not
- [x] Documented clearly: the chain proves internal consistency, **not** authorship. Anyone who can write to this database can recompute a chain from a tampered document onward and the result verifies. A check asserts both the verifier and the command say so, because a system trusted for something it cannot do is worse than no system. The cryptographic seal is PF-124
- [ ] Runs nightly and alerts on divergence - the command is here and exits non-zero; the schedule belongs with the rest of the scheduled work in Phase 13

### PF-097 · Credit notes
`Sales` · **5 pts** · depends: PF-095 · standards: UNCL 1001 (`381`) · `type:posting`

- [x] Full or partial credit against an issued invoice, line-selectable, with its own number from its own series and its own hash chain. Three ways in: every line in full, chosen lines in whole or in part, or an amount. All three go through `Issuer`, so a credit note gets the same number allocation, chain link, posting, audit record and duty check as any other document - a crediting path with its own issuing logic would be a second implementation of all of it, and the second implementation is the one that drifts
- [x] **An amount credit runs the arithmetic backwards**, because the agreed figure is the gross. Measured: computing the net from a rate and the tax from the net turned a 1,000.00 settlement into 999.9952 and left 150.0048 creditable on a 1,150.00 invoice - a residue nobody can explain and nobody can clear. The gross is allocated across the lines, each line's net is backed out at its own rate, and each group's tax is the remainder, so the total is the figure that was asked for by construction
- [x] A partial credit carries its **share of the quantity**: 40% of the value of a one-unit line is 0.4 units, not one unit at 40% of the price. A warehouse receiving the goods back reads the quantity
- [x] Carries a mandatory reference to the invoice it credits (`BT-25`), and the invoice shows its credits - through the references, because that is where the link legally lives. A credit note with no reference cannot be issued at all: the database refuses it, and says why
- [x] Cannot credit more than the invoice's remaining uncredited value, enforced in the database. Twice over: the service refuses with a sentence somebody can act on, and the database refuses atomically. Both figures are the same figure, and a check asserts the two agree - a service that offers an amount the database then refuses is worse than one that offers nothing
- [x] **The cap is a running total, not a locking read.** Observed once under three simultaneous credit notes: an invoice for 115.0000 ended up with 345.0000 credited against it. That violation has not been reproduced on demand since - a forced interleaving is refused by the locking version too - and the total is kept anyway, because its correctness needs no reasoning about snapshots in volatile functions and no accidental help from the numbering lock. `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` blocks, re-reads the committed row, and adds to the value that is actually there
- [x] Reverses the original posting through a posting rule, not by negating rows - and not through `Reverser` either. A partial credit is not the reversal of anything: crediting two lines of a nine-line invoice is a posting whose shape happens to mirror part of an earlier one. A reversal says "that should not have happened"; a credit note says "this happened, and now this has happened too", which is what an auditor reading the receivable needs
- [x] The tax leg goes back to `vat_output`, not to `vat_input`. Posting it as input leaves both figures overstated by the same amount: the net due is right and both boxes of the return are wrong, which is the failure that reconciles perfectly
- [x] SoD: a user cannot credit an invoice they issued (`PF-033`), asserted in the issuing transaction before anything is consumed and proved end to end with two real users - one issues, is refused the credit, and the other is not
- [x] Found on the way: **the duty control could not fire at all.** The engine matched the duty's permission key (`invoice.issue`) against the audit trail's `action` column, which holds what happened in the past tense (`invoice.issued`). It matched nothing. Every object-scoped rule over a real flow was a control that passed its own tests - against audit records the tests themselves wrote under the key the engine was looking for - and did nothing in production. `config/sod.php` maps duties to actions now, and `bin/verify sod` fails the build if a duty maps to an action nothing writes or is not declared as awaiting its phase

### PF-098 · Debit notes and corrected invoices
`Sales` · **3 pts** · depends: PF-097 · standards: UNCL 1001 (`383`, `384`) · `type:compliance`

- [x] Debit note (`383`) for an additional charge against an existing invoice, in its own series with its own chain, posting through the **invoice** rule - receivable up, revenue up, output tax up. A debit note is not a credit note with the sign flipped: it charges more for a supply already invoiced, which is a price that was understated or freight that was left off
- [x] **No cap on a debit note**, deliberately. A customer can be charged more than once against one invoice for different things, and the only ceiling on what may be charged is what was agreed, which is not a number this system holds. A check issues 1,000 and then 1,500 against a 1,150 invoice and requires both to succeed
- [x] Corrected invoice (`384`) where a jurisdiction requires a replacement rather than a credit-and-reissue, with a mandatory reference to what it corrects. The lines are the corrected figures **in full**, because a replacement is not a difference
- [x] It posts the reversal and the restatement as **one entry**, not two. It is one event: a replacement says "this invoice is not what it should have said; this is". Two entries read as two decisions taken at two moments, and anybody reconciling the receivable has to find both and know they belong together. It also makes the arithmetic self-checking - the entry balances only if each half does, so a correction that reverses 1,150 and restates 1,000 cannot be written at all
- [x] And the original's journal entry is **untouched**. It was posted correctly from the document that existed at the time; what changed is the document, not the books - which is why this is a posting rule and not `Reverser`
- [x] **One replacement per invoice, enforced by a primary key.** Two replacements for one invoice is a question with no answer: which one is the invoice? A key on the corrected document is the cheapest correct enforcement there is - two transactions inserting one key cannot both succeed, and nothing about snapshots has to be true for that to hold
- [x] A replaced invoice is **closed to further adjustment**: crediting it after it has been restated in full would count the correction twice, and both documents would be defensible on their own. Refused by the service and by the database
- [x] An adjusting document cannot itself be adjusted - correct the correction, which keeps every document in the returns it was reported in
- [x] Which mechanism is legal is a **jurisdiction profile decision**, not a user choice, and `permitted()` is what the sales screens read, so a type the profile forbids is not offered at all. `facturx` replaces, `eta` and `jofotara` use notes, the rest accept either, and a profile with no declared mechanism is refused rather than guessed - a guess issues a document under a procedure nobody chose
- [x] SoD: whoever issued an invoice may not issue the replacement that supersedes it (`invoice_then_correct`). The debit note is deliberately **not** paired with issuing: it increases what the customer owes, so the fraud it enables is overcharging - a different control, and pairing it here would refuse the ordinary case of the person who raised an invoice adding the freight they forgot
- [x] Found on the way: one bite did not fire. Replacing the single-replacement `INSERT` with `ON CONFLICT DO NOTHING` left the check green, because the replaced-invoice guard refuses the second document first - defence in depth working. Proven by removing both, which is what shows the check is sensitive to the behaviour rather than to one of its two layers

### PF-099 · Prepayment invoices
`Sales` · **5 pts** · depends: PF-095 · standards: UNCL 1001 (`386`), IFRS 15 · `type:posting`

- [x] A prepayment invoice posts to a **liability**, not to revenue - cash received before performance is not revenue. Its lines carry no revenue account at all, deliberately: the rule credits `deferred_revenue` through its role, and a revenue account on the line would be a number nobody reads and somebody would eventually post to
- [x] The overstatement could not be fixed later, which is why this matters: crediting revenue on the prepayment puts income in a period that may be closed, reported and filed, and the correction in a later one. Two wrong periods netting to right is the shape nobody notices until an auditor asks what the revenue in Q1 was for
- [x] Settled against a later final invoice, which shows the prepaid amount as a deduction and carries the EN 16931 prepaid-amount term (`BT-113`). The invoice total does not change - the supply is still the supply - and what the deduction changes is what is left to pay
- [x] The final invoice posts a **different entry**: the receivable is only the balance, the revenue is the whole supply, the liability is released, and the tax is the increment. Chosen from the document's references rather than its type, because a final invoice is an ordinary `380` - and a rule chosen from the type alone posts the full gross again, showing the customer owing for one supply twice
- [x] The tax already charged is deducted from the group it was charged in, and a prepayment taxed in a jurisdiction the invoice's lines do not touch is **refused** rather than netted against a tax the authority never saw
- [x] The liability is released with the **prepayment's** tax point, not today's. The return it was reported in used that date, and releasing it under another one would make PF-088's reconciliation differ by the prepayment in both periods, with "explained by timing" having to explain something that is not timing
- [x] Tax on a prepayment follows the jurisdiction's rule, which is frequently "tax now", and that is a profile decision - `prepayment_tax` in `config/jurisdictions.php`, refused rather than guessed for a profile that declares none. The difference is a quarter's cash: taxing at the final invoice under a regime that taxes on receipt files a return short by the tax on every prepayment in the period, and the authority already has the other side of it because the customer claimed the input tax
- [x] A test takes a prepayment, issues a final invoice, and proves revenue, the liability and the receivable all end correct - by reading the **balances** after the whole cycle, not the lines of either document. Every individual posting can look defensible while the pair is wrong: revenue recognised twice, tax declared twice, the customer chased for one supply twice
- [x] **A prepayment is applied to exactly one invoice**, enforced by a primary key, and a deduction that does not match the prepayment it cites is refused - a document deducting 300 while citing a 460 prepayment leaves 160 in a liability nobody will ever clear, and both documents look correct on their own
- [x] Splitting one prepayment across several invoices is deliberately **not** modelled, and the refusal says so: it needs a balance per prepayment, ordering rules for when two invoices compete for the remainder, and a report of what is left, which is an allocation ledger rather than a column. Returning part of one is a credit note, which exists

### PF-100 · Self-billed invoices
`Sales` · **3 pts** · depends: PF-095 · standards: UNCL 1001 (`389`) · `type:compliance`

- [x] The buyer issues on the supplier's behalf under an agreement, which must be recorded on the party before the type is available. A **row**, not a checkbox: a reference, the date it was signed and a half-open validity range, because the question an auditor asks is which agreement was in force on the date of the document - and a checkbox records that somebody once said yes
- [x] Two agreements with one party cannot overlap, by exclusion constraint. Two in force at once is two answers to "which agreement is this document under", and the document carries one reference
- [x] Enforced in the **database**, not only in the service: the trigger refuses a `389` whose supplier has no agreement covering its tax point. A second issuing path - an import, an API, a correction script - would otherwise produce a document the regime does not recognise, and the supplier is the one who would be asked to explain it
- [x] Numbered from its own series and clearly marked as self-billed on the document, in both languages, with the agreement's reference. The type code says `389` to a machine; a person reading the paper needs to know the buyer issued it and under which agreement, which is what most regimes require the document to state
- [x] Posts as a purchase, not a sale, which is the part that is easy to get backwards: payable up, **input** tax up, expense up. Posting it as a sale looks right in every individual figure - the supplier appears as a customer who owes money, the tax lands in output so the return declares tax on a purchase and claims nothing back, and revenue rises by the cost of the goods. The entry balances and the trial balance adds up. What is wrong is the direction of the whole document, so the check reads the side of each account rather than the amounts
- [x] A line pointing at the **wrong side of the books** is refused. `invoice_lines.revenue_account_id` holds the account a line posts to, and on a purchase-side document that is an expense account - the name is historical and the ambiguity is real, so the validator asserts the account's type matches the document's side rather than trusting the column name
- [x] A customer record cannot be self-billed, and a supplier cannot be sent an ordinary invoice: the party check reads the document's side. Checking `is_customer` for every type would refuse every self-billed invoice, and dropping it for all of them is how a payment ends up chasing the wrong side of a relationship

### PF-101 · Simplified and B2C invoices
`Sales` · **3 pts** · depends: PF-095 · standards: EN 16931, jurisdiction profiles · `type:compliance`

- [x] A simplified variant with a reduced mandatory field set where a jurisdiction permits one, subject to a value threshold the profile declares. Which form a document was issued in is a **column**, because it is part of what the document says: deriving it later from the profile and the customer would re-read a configuration that may have moved and a party that may have been merged
- [x] The threshold and the permitted field set are profile data - the EU's EUR 100 ceiling, ZATCA's no-limit B2C form, and `eta` with no simplified invoice at all because Egypt's consumer sales go through the e-receipt system. An undeclared profile is refused rather than guessed: inventing a threshold issues documents the authority rejects, and inventing the field set issues documents that are missing something
- [x] Exceeding the threshold **upgrades the document type** rather than issuing an invalid simplified one, and the refusal names the figure and says to issue it in full. Checked in the service when the form is chosen and again by the validator at issue, because a draft marked simplified at 80 and edited to 120 is the ordinary way a document outgrows its form - and at the moment of issue the only options are to abandon it or to change its form, which is what people do
- [x] A threshold in another currency is **not converted**. Converting it would be this system deciding what the regime's limit means on a rate of its choosing; the standard form is always acceptable and the simplified one is the concession
- [x] The form is **under the hash**. A simplified invoice and a full one carry different obligations, so a document changed from one to the other after issue breaks the chain. Found by the check that asked for it: it was not in the canonical form at first, and both the issuer and the verifier now carry it
- [x] A B2C document omits the customer registration - a consumer has none, and nothing about that may block the sale. It still names somebody, because a document addressed to nobody cannot be delivered or disputed
- [x] And carries different place-of-supply treatment for digital services: to a consumer abroad the supply is placed where the consumer is (the destination principle, the rule that made a small business selling a download liable abroad), and to a business abroad the same supply is reverse-charged. The engine is PF-078's rule book; what is proved here is that the two decisions genuinely differ and that the sales side reaches them
- [x] Only an invoice and the credit note that corrects one have a simplified form, enforced by a CHECK as well as by the service - that is what the regimes define, and everything else is a full document

### PF-102 · Invoice PDF and PDF/A
`Sales` · **8 pts** · depends: PF-095 · standards: ISO 19005-3 (PDF/A-3) · `type:compliance`

- [ ] Rendering through Gotenberg from the print template, then Ghostscript conversion to PDF/A-3
- [ ] **veraPDF verifies conformance** and a non-conformant output fails the job rather than being delivered - a PDF that merely claims PDF/A is not an archive copy
- [ ] Fonts fully embedded, including the Arabic face, with correct text extraction and shaping - a PDF whose Arabic cannot be selected or searched is a scan with extra steps
- [ ] Bilingual layouts: an RTL document with Latin identifiers, bidi-isolated so an IBAN and an invoice number read in the right order
- [ ] Deterministic output: the same invoice renders byte-identically, so the archive hash is stable
- [ ] `PDF_DRIVER=stub` renders an HTML placeholder so a fresh clone works without the services

### PF-103 · The print stylesheet
`Sales` · **5 pts** · depends: PF-102 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] A4 and Letter, correct margins, page breaks that never split a table row or orphan a total
- [ ] Repeated table headers across pages, `page x of y`, and the entity and document identity on every page
- [ ] No interactive chrome, no colour-only meaning, and a `draft` watermark that is also a word
- [ ] Tested by rendering and diffing the PDF, not by eyeballing a browser preview
- [ ] The print view is what gets attached to an email, so it is the same artefact that is archived

### PF-104 · Delivery
`Sales` · **5 pts** · depends: PF-102, PF-063 · standards: —

- [ ] Email with the PDF and, where the profile requires, the XML attached, rendered in the **recipient's** language
- [ ] A delivery log per document: channel, address, attempt, outcome, provider message id
- [ ] Bounce and complaint handling that marks the address and surfaces it, rather than silently retrying forever
- [ ] A signed customer-facing view with a payment instruction, and no login
- [ ] Delivery failure never changes the document's issued state - it is issued, and undelivered

### PF-105 · Recurring schedules
`Sales` · **5 pts** · depends: PF-095 · standards: RFC 5545

- [ ] A template plus an `RRULE`, so "the last working day of every quarter" is expressible rather than approximated
- [ ] Proration rules for mid-period starts and stops, with the proration basis visible on the generated line
- [ ] End conditions: a date, an occurrence count, or open-ended
- [ ] A **dry-run preview** showing the next twelve occurrences with their amounts and dates before anything is activated
- [ ] Price changes on the underlying items are picked up by effective date, and the schedule records which price list it resolves against

### PF-106 · Recurring generation idempotency
`Sales` · **3 pts** · depends: PF-105 · standards: — · `risk:high`

- [ ] Generation is idempotent on `(schedule_id, occurrence_date)` - a scheduler that fires twice cannot bill twice
- [ ] A Redis lock per `(task, tenant, occurrence)`, so a two-node scheduler cannot double-bill
- [ ] Runs in the **tenant's own timezone**: "daily at 01:00" means 01:00 where the business is, and a tenant in Riyadh and one in Casablanca do not share a moment
- [ ] A failed occurrence is retried without skipping the next, and a permanently failed one is surfaced rather than retried forever
- [ ] A test fires the same occurrence from two workers simultaneously and asserts exactly one invoice

### PF-107 · Revenue recognition
`Sales` · **8 pts** · depends: PF-099, PF-052 · standards: IFRS 15 · `risk:high` `type:posting`

- [ ] A recognition schedule per line: immediate, straight-line over a service period, on milestones, or on delivery
- [ ] Issuing posts to `deferred_revenue`; the recognition run releases to `revenue` on schedule
- [ ] `revenue:recognize {period}` posts one entry per period, is re-runnable without double-posting, and reports what it recognized and what it deferred
- [ ] A credit note against a partially recognized invoice unwinds the remaining deferral correctly, which is the case that breaks naive implementations
- [ ] A deferred revenue roll-forward report: opening, additions, recognized, closing - reconciling to the control account

### PF-108 · Dunning policies
`Sales` · **5 pts** · depends: PF-066, PF-062 · standards: —

- [ ] A policy is a sequence of steps, each with a days-past-due offset, a channel, a template and optional actions
- [ ] **Evaluated per invoice, sent per customer**: a customer with six overdue invoices gets one statement, not six emails
- [ ] A disputed invoice stops escalating and appears on a dispute report instead
- [ ] Policies are assignable per party and per party group, with a tenant default
- [ ] `dunning:run` is idempotent per `(invoice, step, day)` and respects the tenant's timezone and business calendar

### PF-109 · Late fees, credit hold and collections
`Sales` · **3 pts** · depends: PF-108 · standards: — · `type:posting`

- [ ] A late fee as a dunning action, posted as its own document so it is creditable and disputable
- [ ] Credit hold applied and released automatically by policy, with a manual override that is permissioned and audited
- [ ] A collections owner per party, a promise-to-pay date, and a collections worklist ordered by value at risk
- [ ] A fee is never applied twice for the same period, and the fee basis is shown on the document
