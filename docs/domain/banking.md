# Banking

The [README](../../README.md#banking) covers the shape. This document covers the matching engine in detail, the statement formats and their failure modes, and the payment-release control.

## Three balances, always shown together

| Balance | Source | Meaning |
|---------|--------|---------|
| **Ledger** | Posted GL on the bank account's mirror | What PeakFlow believes |
| **Statement** | Last **booked** closing balance that stands | What the bank says |
| **Available** | Statement minus released-but-unpresented payments | What can be spent |

> **The difference between ledger and statement is the reconciliation question, and it is always itemized** — never shown as a single unexplained figure. A design that stores one balance for both has deleted the question.

All three diverge correctly after a payment is released and before its statement arrives. That is the normal state, and `bin/verify banking` asserts it.

Two details that only look like details:

**Available does not add deposits in transit.** A cheque banked this morning raises the ledger balance and does not raise the available one. The whole point of a separate available figure is that it is the figure safe to spend against, and a deposit the bank has not cleared is not money to spend this afternoon. Adding it would make available the optimistic figure, which is the figure that causes an overdraft.

**The statement balance excludes intraday reports.** CAMT.052 and CAMT.054 carry the same movements the booked statement will carry, so letting one set the reconciliation baseline double-counts every line on it when the CAMT.053 arrives. They feed `intradayPosition()` instead — which is what they are for, and which states that it is provisional rather than returning a bare number.

### The identity, and its four components

The reconciliation is the four-way form, rearranged so the ledger is on the left:

```
ledger = statement
       − unpresented payments      we have paid, the bank has not shown it
       + unsettled receipts        we have banked it, the bank has not shown it
       − unrecorded bank items     the bank booked it, nothing here records it (signed)
       − unposted opening balance  the account was opened with money and nothing posted it
```

Anything left over is **unexplained**, and it is reported on its own line rather than folded into one of the four — because folding it in is how a difference comes to look explained when it is not.

The signs are the part that is easy to get wrong, and a sign error here reports *exactly twice* the item as unexplained, which reads as a second mistake somewhere else. `bin/verify banking` puts every mechanism in play at once and asserts the components total the headline difference, rather than checking one component at a time where two compensating sign errors would pass.

**Unrecorded bank items** are the direction a naive reconciliation forgets, and the worse one: a direct debit, a bank charge or an interest credit the bank has taken and no document here explains. Reported as a component, the next step is to find the documents. Reported as unexplained, the usual next step is to adjust the ledger to match the bank — which posts a number with no document behind it and makes next month's difference harder to read, not easier.

**The unposted opening balance** is its own component because it has exactly one cause and it is the first thing anybody sees after opening an account. Opening an account deliberately posts nothing — an opening entry needs a date, a counter-account and somebody to approve it, none of which a bank-account form has — so the account starts with a ledger balance of zero against a statement balance of its opening figure. The generic "a posting with no bank transaction behind it" sends somebody hunting for a mistake that is not there; what they need to do is post the opening entry, and the reconciliation says so in those words. Once the entry exists the component returns zero and stops explaining anything.

## The account, and the chart line it mirrors

A bank account mirrors a GL account, and the link is immutable. Re-pointing it moves history silently: every posting made through the old link stays where it was, and the account's ledger balance changes without a single journal entry being written. Both the service and a database trigger refuse it, and `bin/verify banking` checks each layer separately — a check that accepts a refusal from either one passes while one of them is broken.

### The bank role names a subtree, not an account

`account_roles` is unique on `(entity_id, role)`, so exactly one account per entity carries `bank`. That is right: the posting rules that credit "the bank" need one unambiguous target.

It also means that requiring the role on **every** bank account's GL link caps an entity at one bank account for ever — and a SAR operating account alongside a USD account is not an edge case. So a bank account may mirror either the role account itself, or any **postable** account beneath it:

```
1.1.1.2      Bank              ← carries the role
1.1.1.2.1    Al Rajhi, SAR     ← a bank account may mirror this
1.1.1.2.2    SAB, USD          ← and this
```

Beneath it rather than anywhere, because that is what keeps the requirement meaningful: the subtree is the part of the chart the trial balance reports as cash, and an account outside it is one the ledger does not think is a bank account. Posting cash to a receivable is how a cash figure comes to include money nobody has.

The refusal names the sub-account route explicitly. Without that sentence the only visible way to add a second bank account is to move the role, which breaks every posting rule that credits the bank.

### A header account cannot be mirrored

The link exists so the two balances can be compared, and a GL account nothing can post to has a ledger balance that is permanently zero. Refused up front, because otherwise the failure surfaces on the first payment as a refused posting with nothing to do with banking.

### Two bank accounts cannot share one GL account

The ledger would have one balance and the bank two, and no arithmetic recovers which part is which. The answer is a sub-account per bank account.

### A shared account across legal entities is refused structurally

`bank_accounts.entity_id` is a single non-null column, so there is no way to express a bank account belonging to two entities — not a validation rule but an absent column, which is the strongest form the refusal can take.

It is a common arrangement and it is always wrong in the books. The cash belongs to whichever entity holds the account; the others are being lent money, and that is an inter-company loan with terms, interest and a tax position, not a column on a bank account. Modelling it as shared makes every entity's cash figure a guess and makes the inter-company balance invisible. Open an account per entity, or record the loan — which [inter-entity postings](general-ledger.md) already support.

### The identifier is encrypted, and the tail is not

`$hidden` covers every encrypted column, so no JSON response carries one, and the accessors decrypt on demand. The last four characters are stored in the clear because every screen that mentions an account has to identify it, and decrypting on every row of a list is how a page comes to take four seconds. The audit record carries the tail and never the identifier: an audit log is read by more people than the account screen, exported more often, and retained longer.

The IBAN is validated by its value object — the country's declared length and the mod-97 check digits — not by a regex. A regex accepts `GB00` followed by the right number of characters, which is the shape of every mistyped IBAN, and the check digits exist precisely so a typo is caught before the money moves.

An IBAN whose country disagrees with the institution's is **refused**, not warned about. It is either the wrong IBAN or the wrong institution, both are worth stopping, and neither is worth guessing at — the refusal says so rather than letting somebody change the institution to match the IBAN, which is the wrong half half the time.

## Statement ingestion

| Format | Standard | Fidelity | Notes |
|--------|----------|----------|-------|
| CAMT.053.001.08 | ISO 20022 | **High** | The target. Structured remittance, transaction codes, batch entries, reversals, charges |
| CAMT.052 / .054 | ISO 20022 | Intraday | **Provisional** — feeds the cash position, does **not** post and does **not** reconcile |
| MT940 / MT942 | SWIFT FIN | **Low** | Still ubiquitous. `:86:` is semi-structured free text and its layout is bank-specific |
| OFX / QFX | OFX 2.x | Medium | Both SGML-era and XML-era variants exist in the wild |
| CSV | — | Variable | Per-bank mapping saved as a reusable template |

A named format is **verified against the content** before anything is parsed. Somebody picks "CAMT.053" from a list and uploads an MT940 because the two live in the same folder and the names mean nothing to them. Reading an MT940 as CAMT produces no rows; reading a CAMT.052 as a CAMT.053 produces rows that look right and are provisional — and that second one is the dangerous case, so the refusal says what the file actually is.

Sniffing is by shape, never by extension: a bank that serves CAMT.053 as `statement.txt` is ordinary, and an extension is whatever the browser put there. CSV is never sniffed, only chosen — anything is a CSV if you squint, and a sniffer that guessed CSV would swallow every file no other reader claimed and produce nonsense rows instead of a refusal.

**And the file names its own account.** Importing one account's statement into another is a mistake somebody makes within a week of having two accounts, and it produces a reconciliation that can never be completed: the balances are a third party's, the lines are a third party's, and nothing about the result says so. The check costs a string comparison. Where a file identifies no account — a CSV never does — the report says the account *was not checked*, because "no mismatch" and "nothing was checked" are not the same assurance.

### What varies between banks, and what it costs to ignore

"ISO 20022" sounds like it settles the question. It does not, and the four shipped CAMT fixtures are four genuinely different banks.

| Varies | What a reader that assumed one way does |
|--------|------------------------------------------|
| Namespace and version, `.02` to `.08`, any prefix | Refuses every bank whose prefix is not ours — every expression uses `local-name()` |
| Opening balance as `OPBD`, `PRCD` or `ITBD` | Refuses two banks in four; they are the same figure under different names |
| `FrToDt` absent | Refuses a file whose period is only in its entries — it is derived instead |
| `BkTxCd/Prtry` with no ISO domain | Loses the only code most domestic banks send |
| `Ntry` with no `NtryDtls` at all | Loses every fee line, which is how most banks send one |
| `Acct/Ccy` absent | Refuses a file whose currency is on the balance amounts |
| Several `Ustrd` elements | Keeps a third of the remittance, and the invoice numbers are usually in the part dropped |

### The two sign rules, which are the whole correctness of this

**`CdtDbtInd` gives the real direction of the booked amount, always.** `RvslInd` says only that this booking reverses an earlier one; it does **not** mean the sign should be flipped. Flipping it is the obvious reading, it is wrong, and it turns a reversed credit into a double debit — with every figure still plausible. The same applies to MT940's `RC`/`RD` marks.

**A balance has a direction too.** `Bal/CdtDbtInd` of `DBIT` on a current account is an overdraft, and a reader that ignored it reports an overdrawn account as having money in it.

A third, smaller one: **the counterparty depends on the direction.** On a credit it is the debtor; on a debit, the creditor. A reader that always takes `Cdtr` is right on half the lines and silently wrong on the rest, where the "counterparty" of every incoming payment comes out as us.

### A batch entry is one movement and many things to match

A payment run of fifty supplier payments arrives as **one** debit. The bank took one amount on one day, so the entry is the movement and the entry is what has to sum into the closing balance. But nothing in that entry matches a document: the fifty references, beneficiaries and amounts are inside `NtryDtls/TxDtls`.

A reader that took only the entry would make a payment run permanently unmatchable. One that took only the transactions would import fifty movements the bank never made, and the statement would never tie.

So the entry is the bank transaction, and its `TxDtls` become `bank_transaction_details` rows — which **nothing sums into any balance**. That is the point. A detail may also run against its entry: a refund inside an outgoing run is a credit among debits, and its own `CdtDbtInd` says so.

### A pending entry is not in the closing balance

`PDNG` on a CAMT.053 is ordinary — a card authorisation that has not settled. Importing it as a booked line makes the file disagree with its own stated movement by that amount, and the import is then refused for a reason that is not the file's fault, which reads as a parser bug. Pending lines are imported, marked provisional, and left out of the arithmetic.

### An interim report states no balances, and that is not a defect

MT942 carries no `:60F:`/`:62F:` at all, and a CAMT.052 need not either. The parser reports them absent rather than inventing a figure; the importer opens and closes the statement where the account's booked position already stands, so its booked movement is zero — which is exactly what an interim report moves.

Interim reports are also **outside the continuity chain**. They carry the same movements the booked statement will carry and are in no closing balance, so a chain that included one would refuse the next real statement — whose opening balance is the last *booked* closing.

### MT940 is a degraded path and says so

No structured remittance, no end-to-end id, no ISO transaction code, no dependable counterparty account. Everything a matcher wants is in `:86:`, which is semi-structured free text whose layout is **bank-specific** — and the standard does not say what goes in it. Three layouts are common and none is universal: the German numbered `?20?` sub-fields, SWIFT's tagged `/EREF/` segments, and plain prose. The numbers mean different things at different banks, which is why the profiles are configuration.

Where none of the three fits, the text is kept **exactly as it arrived**. Dropping it is the easy choice and the worst one: `:86:` is the only place a reference appears in this format, so a dropped one leaves the line unmatchable and unreadable at once.

### A CSV is detected, previewed, then imported

Nothing the detector decides is applied without somebody seeing the rows **as they will be interpreted**, beside the raw cells they came from. `1.234` is a thousand in Frankfurt and one in London, and a detector that guessed silently imports a year off by a factor of a thousand with every row looking plausible.

- The **delimiter** is chosen by consistency, not frequency: a remittance full of commas makes the comma the most frequent character in a semicolon-delimited file.
- The **separators** are decided across every amount in the file at once, because one value settles nothing. Where both readings survive, it is reported as ambiguous rather than guessed at.
- Three **sign conventions** are all common: one signed column, separate debit and credit columns, an unsigned column with a direction beside it. The second quietly halves a statement when a reader expects one amount column, and a row with both filled is refused — the usual cause is a running-balance column mapped as one of them.
- A row that will not read **stops the import**. Skipping it leaves the month short with nothing saying which rows, and the difference surfaces weeks later as a reconciliation that does not tie.

The mapping and the dialect are saved together as a template, because a bank's export does not change month to month and re-mapping twelve columns every month guarantees that one month two go the wrong way round.

### The code picks the strategy

The bank's own transaction code is the cheapest signal there is about what a line **is**, and the map from code to matching strategy is data rather than code — a bank using `PMNT/MCOP` where its neighbour uses `PMNT/MDOP` is configuration, not a release.

| Code | Strategy | Why |
|------|----------|-----|
| `PMNT/ICDT/*` | payment run | Ours going out, so it matches a released batch |
| `PMNT/RCDT/*` | receivables | A customer paying us |
| `PMNT/RCDT/RRTN` | reversal | A *returned* payment, not a new receipt — matching it against open invoices applies a bounced payment to a different invoice |
| `PMNT/MDOP/*`, `ACMT` | rule engine | Fees and interest have no document and never will |
| anything unmapped | generic | Every document pass in order: slower, no less correct |

Most specific wins, which is the whole reason the sub-family is stored.

### Rules that each exist because of a failure mode

**Continuity.** Every import asserts its opening balance equals the previous statement's closing balance for that account. A mismatch **stops the import and reports the gap** rather than importing a hole — the alternative is a reconciliation that can never be completed and nobody knows why.

**A genuine bank-side gap is recorded, not configured away.** A bank that will not re-issue a statement from four years ago, or a migration from a system whose history is gone, is a real gap — and the answer is not to turn continuity off, because the next *accidental* gap would then pass silently.

The procedure is `StatementImport::recordAGap()`, which writes a statement of its own: the period nobody has the file for, an opening balance equal to the previous statement's closing balance **by construction**, the closing balance the bank confirms, no lines, and a reason. The account's history stays continuous and the hole becomes a figure somebody signed for rather than an import that keeps failing. The difference between the gap statement's opening and closing balance *is* the gap, it is stated on the record in words, and the explanation has a longer minimum length than most because whoever reads the account's history in three years needs to know which bank refused what, and when.

The continuity refusal names this route. A person holding a file their bank will not re-issue needs a route that is not the configuration flag; without the sentence, the flag is the only thing they can see.

**A re-issued statement supersedes, and the old one stays.** It is the document somebody reconciled against last month, and deleting it makes that reconciliation unexplainable. Because continuity would otherwise compare the replacement against the statement it replaces, the supersession happens in the same transaction as the import — `import(replaces: $old, supersessionReason: …)` — and a statement already superseded cannot be superseded again, which would leave two statements claiming to replace it and no way to tell which one the account's history runs through.

**The lines must add up to the statement's own movement.** Checked before anything is written. A file whose lines do not account for the difference between its stated opening and closing balance is a parse that lost something — a batch entry read as one line, a page the parser stopped at, a reversal dropped — and the difference is the only sign of it. The refusal states the movement, the sum, the line count and the difference, because the difference is the string somebody greps the file for; importing anyway produces an account that can never be reconciled, and the missing line is far harder to find a month later.

**Idempotency.** Keyed on `(bank_account, bank_reference)` where the bank supplies one, otherwise a SHA-256 of `(value_date, amount, currency, counterparty_account, counterparty_name, remittance, sequence_in_statement)`. People re-download overlapping files constantly. Including the sequence means two genuinely identical transactions on one day stay distinguishable, so deduplication cannot swallow a real duplicate payment — a customer paying the same amount twice in a morning is ordinary, and collapsing the pair hides the error this whole area exists to catch.

`identity_key` is a **generated column**, not something the import computes: an import that forgot to compute it is an import that duplicates, and the unique index could not see the difference. The date goes into the hash as a day count rather than as text, because `to_char` and `date::text` are `STABLE` rather than immutable — they read `DateStyle`, so the key would change meaning with a session setting.

That also decides *how* a skip is detected. The import uses `insert … on conflict do nothing` and reads the affected-row count, rather than catching the unique violation: in PostgreSQL a failed statement aborts its transaction, so a caught violation would turn every remaining line of the file into `current transaction is aborted`.

**And the skip is reported.** An import that does nothing and says nothing leaves the person unsure whether it worked, and the usual next step is to try again with a wider date range — which is how the same afternoon gets imported four times. So an import returns a report: how many lines landed, how many were already there, and for each skipped line its amount, its date and the bank's reference where it has one. The summary says in words that an overlapping file is normal, so a legitimate re-import does not read as a failure. A re-import of the byte-identical file is caught by its content hash before anything is written, and says which statement it already is and when.

**Immutability.** A bank transaction is a third party's assertion. A line you disagree with is a line to dispute with the bank: editing it locally produces a statement that reconciles perfectly to our ledger and to nothing the bank has ever sent. Enrichment — the counterparty we think it is, the category we guessed, the reference we extracted — lives in `bank_transaction_enrichment`, never by rewriting the imported value, and a party attributed there has to say **how** it was decided: "the account number is on file" and "somebody chose from a list" are the same party at different levels of confidence, and the second is the one an auditor asks about.

`DELETE` is revoked at the role level rather than refused by a trigger, because a trigger can be disabled by whoever owns the table. The table opts in with a `peakflow:no-delete` comment and the provisioner revokes the privilege on the parent **and on every partition** — PostgreSQL checks privileges on the relation a statement names, so revoking on the partitioned parent alone looks sufficient and is not: each partition carries its own grant, and `DELETE FROM bank_transactions_2026_05` would be permitted. Thirty-seven partitions, thirty-seven holes, and the only sign of it is a privilege query, which is why `bin/verify banking` asks per partition.

**Both dates.** Booking date and value date are both stored. Interest, FX and cash forecasting use the value date; the GL posts on the booking date. Collapsing them into one date is a bug that surfaces at month end.

**MT940 is a degraded path, and says so.** Matching confidence from MT940 is structurally lower than from CAMT.053, and the UI states it rather than presenting both as equivalent. An unparseable `:86:` is retained verbatim so a human can still read it.

## The matching engine

Seven passes, cheapest and most certain first. Each proposes with a **confidence score and a human-readable reason**.

| # | Pass | Signal | Typically matches |
|---|------|--------|-------------------|
| 1 | Exact reference | Payment reference, invoice number or end-to-end id in the remittance field | Most B2B receipts |
| 2 | Payment-run match | An outgoing amount equal to a released batch's total, or a line within it | AP payments |
| 3 | Amount + party + window | Counterparty account identifies the party | Single-invoice receipts |
| 4 | Structured remittance | ISO 20022 `RmtInf/Strd` referenced documents | Modern, Peppol-aware payers |
| 5 | Subset-sum | One credit against a combination of open invoices | Customers paying several invoices in one transfer |
| 6 | Rule-based | Tenant rules on counterparty, code, amount range, pattern | Fees, interest, standing charges, payroll, transfers |
| 7 | Fuzzy party | Normalized name distance | Messy retail payers — **proposal only, never auto-applied** |

### A pass proposes; the engine decides

No pass writes anything, judges whether its own score is good enough, or stops the ones after it. That belongs to the engine, which is the only place that can see all the candidates at once — and seeing them at once is what makes ambiguity detectable. A pass that applied its own best answer could never be told that two of them agree and that is the problem.

Passes run cheapest and most certain first, and once a candidate reaches the auto-apply threshold the **expensive** ones are skipped: a subset-sum search is bounded precisely because it is costly, and running it after pass 1 read the invoice number off the remittance field spends that budget on a worse answer to a question already answered.

The skip is **recorded on the result**. "No fuzzy proposal" and "fuzzy was not run" are different facts, and only the second has an explanation — somebody asking why a payer was not recognised deserves the second answer rather than silence.

Every pass is accounted for: it is in `ran`, or it is in `skipped` with a reason. There are three reasons a pass is skipped — the transaction routes to a strategy that does not use it, something already scored above the threshold and the pass is expensive, or the pass looked and does not apply — and the third is the common one, so it is the one whose absence is hardest to notice. The check that guards this compares the whole set against `ran ∪ skipped` rather than asserting that *something* was skipped, because the first two reasons alone keep that weaker assertion true while the third has gone silent.

### The scoring model, in one place

| Pass | Base | Why there |
|---|---|---|
| Rule engine | 0.95 | A human wrote the rule and said what it means. The engine is not guessing; it is doing what it was told |
| Structured remittance | 0.90 | The payer put the number in a field that exists for it, with its amount beside it |
| Exact reference | 0.80 | The reference is there, in free text — which the payer typed and their bank may have mangled |
| Payment run | 0.75 | Ours going out, matched against a batch we released |
| Amount + party + window | 0.60 | Circumstantial. Right far more often than not, never evidence on its own |
| Subset-sum | 0.55 | Arithmetic, not evidence. A combination that adds up is a combination that adds up |
| Fuzzy party | 0.40 | A guess about **who**, with nothing about **what** |

Every pass moves from its base by **named factors**, and the factors reach the proposal. A confidence of 0.87 is a number nobody can argue with; the factors are what let somebody say "it scored that high because the amount matched exactly, and an exact amount means less than you think when the customer pays round numbers" — which is the conversation that improves the engine. They are also what makes the model reproducible: a model documented in prose drifts from the code within two changes, and one that emits its own working cannot. The checks assert the factors add up to the score.

The fuzzy ceiling is not a threshold. It sits **below any value a tenant can set**, so no configuration anywhere makes that pass apply by itself.

### Reference extraction

Banks mangle references: truncation, case folding, inserted spaces, stripped punctuation, concatenation with other text. Comparison is therefore on a normalised form — upper case, letters and digits only — so `INV-1001`, `inv 1001`, `Inv.1001`, `/REMI/INV-1001 THANKS` and the same reference inside a hand-typed sentence are all found.

That makes everything more findable, **including things that are not there**: `1001` appears in an account number, a date and half the amounts on a statement. Three rules stop it.

1. **A minimum length.** A three-character match against a six-digit invoice number is noise, and treating it as a signal is how an engine applies a payment to the wrong invoice confidently.
2. **The whole reference, or nothing.** A match on the first five characters would match every invoice issued the same month.
3. **A digit boundary.** `INV1001` must not match inside `INV10010` — the next invoice in the series. Normalising removes the punctuation that would have separated them, so without this every invoice matches its own successor for the first ten of them.

It reports more than a boolean: whether the reference stood alone or was found inside a sentence, and how much of the field it accounted for. A remittance that is nothing but the reference is a payer answering the question; the same characters inside forty words are much weaker evidence, because the sentence may be about a different invoice entirely.

### Outstanding is recomputed, never read off a column

Gross, less credit notes, less every applied match. A stored `paid_amount` is a second place the truth lives: it drifts the first time a match is reversed, a credit note cancelled, or two transactions matched to one invoice in the same second — and the symptom is an invoice that reports itself settled while the customer is still being chased, or one that keeps accepting payments after it is paid.

It is also what makes reversal clean: the document comes back by itself, with no second write to get wrong.

### Ambiguity is a property of the set

Two candidates within the margin of each other **pointing at different documents** are a choice, not a ranking. Neither is applied, both are shown, and both carry the flag — so a proposal that was ambiguous when made still reads as ambiguous after the others are decided.

Two passes agreeing about one document is agreement, and deduplication has already collapsed it. Two open documents owed exactly the same amount — a monthly retainer, a standing charge, two identical orders — are both proposed and both penalised for the other existing.

### Subset-sum, bounded

One credit against several open invoices is how most customers actually pay, and the naive implementation hangs on a customer with 400 open items.

- An explicit candidate cap and a **time budget**, with a pathological-input test asserting the budget is respected
- Deterministic tie-breaking, oldest first, with the chosen combination shown **in full** before it is applied
- Never auto-applied above a configured candidate count — beyond that it proposes and a human confirms
- A small residual is classified, not absorbed

### Confidence and what is done with it

- An auto-apply threshold per tenant, with a **platform-enforced ceiling**
- Everything auto-applied is auditable, individually reversible, and appears on a review list for a configurable window
- **Nothing below the threshold is hidden.** Low-confidence proposals are shown as suggestions, because a hidden proposal is worse than a wrong one
- A per-pass accuracy report — proposed, accepted, corrected, rejected — so a degrading pass is visible

### Learning without drifting

When a user accepts or corrects a match, the counterparty's **account number** is stored against the party. The next transfer from that payer is identified exactly, on an identifier, with no name comparison at all — and the one after that, and every one after that.

> The engine learns by accumulating identifiers and by proposing rules a human approves. It **never silently changes its own behaviour**, because an engine whose thresholds move on their own cannot be audited: nobody can say why last month's matches were made at a bar this month's would not clear, and the only way to investigate a bad match is to reconstruct a threshold that has since moved again.

Two constraints on that, both load-bearing:

**It never learns from its own automatic match.** An identifier taken from an unreviewed guess is the engine teaching itself, and two confident mistakes later nobody can say where the belief came from. Identifiers come from a person confirming.

**A name is never an identifier.** `normalised_name` is stored — the fuzzy pass ranks on it, and knowing which names have been seen is useful — but identity comes from an account number or a BIC, things a payer cannot have by coincidence. Two companies called "Gulf Trading" is not a hypothetical. A BIC counts only when exactly one party has it: for a small supplier at a small bank that is informative, and for anybody at a large one it would make every payer there the same party.

### A rule is tested before it is saved, and versioned after

A rule is a standing instruction. Written too broadly it does not fail — it quietly claims every receipt in the account from the day it is saved, and the first sign is a month that will not reconcile.

So the tester answers "which of the transactions you already have would this have matched" **before** anything is stored, and returns the transactions rather than a count: "this matches 412 transactions" is a number somebody argues with, and the first twenty of them with their amounts and counterparties is a rule somebody rewrites. It flags the dangerous case by name — a transaction the rule would have claimed that turned out to be a document matched to something else, which is how a rule written for fees comes to swallow receipts.

Rules are **versioned**, never updated in place. An edit writes a new row with the same key and the next version; the old one is marked superseded; a proposal points at the exact text that produced it. A rule edited next March must not change what it says about a match it made today.

### The accuracy report is derived, not counted

Per pass: proposed, accepted, corrected, rejected. Two ways to get that — a counter incremented on each decision, or the decision recorded on the proposal and the report derived.

A counter is cheaper and drifts: the first time a match is reversed, a migration backfills, or two tabs decide the same proposal. A drifting accuracy report is worse than none, because a degrading pass is exactly what it exists to show and a wrong number hides it.

Accuracy is accepted out of **decided**, not out of proposed. Dividing by the total would make a new pass look terrible on its first day and a stale one look excellent. A reversal counts as **corrected**, not as never-decided — counting it as undecided hides the outcome the report is for.

## Differences, classified before they are written off

### Why classification is the task, not the write-off

A customer pays 9,985 against an invoice for 10,000. The fifteen is a bank charge, or a rounding difference, or an exchange movement, or withholding their finance department deducted at source, or they are disputing part of the bill. Those are five different facts about the business and they post to five different accounts.

An engine that absorbed all five into "difference written off" would be right about the receivable and wrong about everything else. The symptoms arrive slowly:

| Misclassified as | What it costs |
|---|---|
| **Withholding → short payment** | The tax credit is never claimed. It is a credit against the entity's own liability — real money, thrown away quietly, at a few percent of revenue in every withholding jurisdiction the business sells into |
| **Bank charge → bad debt** | The customer looks like a poor payer and the cost of a bank relationship is invisible |
| **Short payment → rounding** | The one difference somebody should have chased is the one nobody sees |

### The order the classifier tries things is the design

**Withholding first**, before anything else can claim the difference, because it is the one whose misclassification costs money rather than reporting quality. Recognised on the **arithmetic** — the shortfall is the rate applied to the document — not on "this customer usually withholds", which would file a genuine dispute as a tax credit nobody can certify.

**Rounding next**, because it is the only one recognisable with certainty: below the smallest unit the currency expresses, two correct figures disagree by one. That unit is the currency's own — 0.001 in JOD — and a rule with 0.01 written into it would absorb nine units and call it arithmetic.

**Short payment last, and as the default.** Deliberately: when nothing fits, "the customer did not pay all of it" is the answer that gets somebody to look. Every alternative is an answer that gets it filed.

A classification carries its reason and its **alternatives**. The default is a guess by construction, and a guess presented without them is one nobody questions.

### A tolerance per type, each with a ceiling

A rounding tolerance of 0.05 is arithmetic. A short-payment tolerance of 0.05 is a policy that nothing is written off without a decision. One figure for all six would have to be the largest, and the largest is the one that absorbs theft — one invoice at a time, each write-off looking like housekeeping.

Each is a tenant setting bounded by a **platform ceiling**: "write off anything under 50" is a policy decision somebody in the business makes, not a developer's constant and not unlimited.

Below the tolerance the difference is recorded automatically; above it, somebody's name is on it. **No type is exempt.** An earlier version let bank charges and rounding through at any size — the reasoning being that an approval step on 0.02 trains people to approve without reading, which is true — and it put the enum at odds with the constraint on the table, which refuses an above-tolerance row with no approver. Two rules that disagree is worse than either; the answer is the tolerance itself, and a bank-charge ceiling of 250 is what keeps an ordinary fee from ever reaching an approver.

The tolerance that applied is **stored**, not recomputed. "It was within tolerance" is the claim and the figure is the evidence; a tenant who raises one next week would otherwise make every past difference look as though it had always been inside it.

### Withholding is never absorbed

Its ceiling is zero and it has no tenant setting. A customer who deducted tax at source handed us a credit against our own liability, and the only correct outcome is claiming it. Recording one is allowed — the receivable has to close — and the result says plainly that it is not a claimable credit until PF-083 has a certificate for it.

### Separation of duties

`reconciliation.write_off` conflicts with `bank.reconcile`, scoped to the **document**. Whoever decided a payment did not cover an invoice must not also decide the shortfall need not be chased — and scoping it to the object matters, because a person may reconcile a thousand lines and still approve a write-off on an invoice they never touched.

The duty maps only to `difference.written_off`, never to `difference.recorded`. A 0.01 rounding is not a decision anybody made, and counting it would stop the same person approving a real write-off for the rest of the period — a control that fires on nothing is one people get an override for.

### A standing report, by type and by period

By **both**, because the figure that matters is a trend. A bank-charge line of 400 one month is a cost; the same line rising for four months is a bank that repriced, a payment route that should change, or a customer base that started paying by a more expensive method — and none of those are visible in a single total.

A trend needs three months and has to rise in each of them. Two points are a line through any two numbers, and a report that cries wolf about bank charges is one people stop opening.

| Classification | Posting |
|----------------|---------|
| Bank charge | Dr bank charges / Cr bank |
| FX difference | Dr/Cr `fx_gain_loss` |
| Short payment | Remains open, or written off with permission |
| Overpayment | Customer credit balance |
| Rounding | Dr/Cr `rounding_difference`, within tolerance |
| **Withholding deducted at source** | Dr `withholding_tax` — **not** a short payment |

Tolerances are tenant settings with a platform ceiling. "Write off anything under 50" is a policy decision, not a developer's constant. A write-off above tolerance requires `reconciliation.write_off` and is SoD-separated from matching.

## Splits, in both directions

One transfer settling four invoices, and one invoice settled by three transfers. Both are ordinary, and a system that models matching as one link between one line and one document forces the first into a suspense account and the second into a write-off — which ends with the real figures living in a spreadsheet beside the accounting system.

### A split is one operation

Three allocations applied one at a time is three states the system can be found in, two of which are wrong — and the second can fail on its own, because the invoice it names was settled from another screen while somebody was filling in the form. So the allocations are validated as a set and written in one transaction: either the line is split the way somebody intended, or nothing happened and the refusal names the allocation that could not be made.

### The sum of what a line settles is the line

The document side was guarded from the start — a match larger than what a document still has outstanding is refused. The line side was not, and that is the more expensive hole: a 10,000 receipt split 6,000 / 6,000 across two invoices has each allocation fitting its own document perfectly. Nothing refuses it, both invoices read as paid, and the only symptom is a reconciliation that will not balance by 4,000.

It is enforced by a **trigger**, not only by the service. A split is several rows and a service is one code path; an import, a correction script or a future bulk tool reaches `matches` without passing it, and the invariant is not "the service is careful".

The service refuses it too, with both figures and a sentence. That is not redundancy — a person splitting a line wrongly should be told what they did, and a PostgreSQL exception reaching them is a defect in everything but the data. The checks assert the *wording*, so a service check removed in future fails even though the database still holds the line.

### The remainder is the point, not a leftover

A 10,000 receipt against a 4,000 invoice leaves 6,000 that is **not explained**. It shows on the line, it keeps the line on the unreconciled list, and — the part that is easy to miss — the **engine re-proposes against it**. An engine still looking for documents that add to 10,000 finds nothing, and subset-sum spends its budget searching for a combination that cannot exist because part of the line is already spoken for.

On the document side the same figure arrives by a different route: outstanding is recomputed from the matches applied, so a part-settled invoice shows what is left without anything updating it. `appliedTo` lists the lines that contributed, because "where did the other 4,000 come from" is the first thing anybody asks about a part-paid invoice.

A line with nothing left says so in those words. "The engine found nothing" and "there is nothing left to find" are different facts and only the second is good news.

### Accepting one proposal does not reject the others

It did, and that was right while a match settled a whole line. On a part-applied line the remaining proposals are **superseded**, not rejected: they answered "what is this 10,000", the question is now "what is the remaining 6,000", and marking them rejected charges six passes with being wrong about a question nobody asked them — which the accuracy report then believes.

### A combination is identified by its documents

Deduplication keyed a candidate by `matched_id`, so "invoice A" and "invoices A and B together" were the same answer and the engine kept whichever scored higher. A 2,300 receipt against two 1,150 invoices lost the combination that explained the whole line and kept the single invoice that explained half, with nothing saying the other answer had been found. A candidate with components is keyed by the **set**.

### Unsplitting is a reversal

Every match the split made is reversed, each stamped with who and why; the rows stay. "It was split four ways and somebody undid it" is a question an auditor asks, and a table that deletes cannot answer it. Afterwards the line is unexplained in full and can be split again — which matters, because the moment somebody needs to rematch a line is the moment after they found the first attempt wrong.

The reason is checked **before** the first match is touched. Reversing one match refuses an empty reason too, so without that the first would be written and the second would refuse: a half-undone split, which is the state the operation exists to make impossible.

## Suspense

### Why an unexplained item is posted rather than left

The instinct is to leave it alone until somebody works out what it is, and that instinct costs a reconciliation a month. A line the bank has and the ledger does not is an **unrecorded bank item**: the bank account does not agree with the bank, every statement after it carries the same difference, and somebody re-derives the same conclusion about the same item each time.

Parking posts the movement to the bank's GL account — the bank really did move that money — and puts the uncertainty in a control account named for exactly that. The item is not less unexplained. It is unexplained **in one place**, where it can be aged, reported, and where a period close refuses to walk past it.

It is not a write-off and must never be used as one. The money is still somebody's; a difference somebody has decided to absorb is PF-164, with its own rule and its own permission. The two look identical on a bank reconciliation and are opposite in accounting terms: "we do not know yet" against "we have decided".

### Age is the bank's date, not ours

How long the money has been sitting unexplained is what somebody means by "that one is four months old". Measuring from when we parked it would reset every time an item is re-parked — and re-parking is exactly what happens when part of an item turns out to be explainable, so the oldest and worst items would report as the newest the moment anybody made progress on them.

Buckets are 0–30, 31–60, 61–90 and 90+, and they are **not configurable**: a suspense report whose buckets differ between two entities cannot be added up, and seeing one organisation's unexplained money in one table is the whole value of it.

### Clearing is a reclassification, never an edit

The parked entry stays exactly as posted. That costs two entries where one would do, and it buys the only thing suspense is for: that on the 14th nobody knew what this was, and the ledger still says so after somebody found out. An auditor asking how long an item was unexplained has no answer in a ledger that rewrites its own history.

Part of an item can be cleared. The rest stays parked and keeps its age.

### Two totals, because there are two questions

`total` is how much unexplained money there is — a magnitude. 450 in and 120 out is 570 of unexplained money and somebody has to look at both. `signed` is what the control account should hold, because netting is what a balance does.

Conflating them was a real defect: a receipt credits suspense and a payment debits it, so comparing the magnitude against the account reported every entity with movements both ways as broken. A check that only ever parked receipts would never have shown it.

### Every riyal traces to a bank line

`ledger:verify` asserts it. Suspense holding 17,400 while the parked items account for 12,900 means money somebody posted there by hand — a manual journal, a correction, an import that did not know where else to put something — and it is money no list accounts for.

That is how a suspense balance stops being a list of items and becomes a number. A number cannot be aged, assigned or cleared, because clearing an item means knowing what it was. It sits for years, and every close either steps over it or stops on it.

> The assertion reaches `ledger:verify` through a registry in `Support`, the same shape as the closing checks. The ledger depends on nothing but `Tenancy`, and a verifier importing the banking service to ask this would reverse the dependency the layering exists to protect.

### The close, and the one way past it

**A period does not close with a non-zero suspense balance.** Not a warning — a warning is advice, and this is read, understood and dismissed by the person closing. Anything in suspense could be a sale, and a sale in suspense is revenue missing from the accounts and output tax missing from the return; closing freezes that, and the correction becomes a prior-period adjustment.

The escape is a **controller's sign-off**: a name against a figure and a sentence of at least forty characters, kept as a record. Sometimes an item genuinely cannot be resolved by the close — a customer's bank will not say who sent a transfer — and the alternative is leaving the period open, which stops reporting and invites backdated postings. The point is not to prevent the close; it is that nobody closes over unexplained money **silently**.

The sign-off is **for the figure it was given against**, not for the period. If more lands in suspense before the close, the figures stop agreeing and the close is refused again — a flag would let a decision made about four hundred carry a close over forty thousand, because the thing that changed was not the flag.

### And the person who signs is not the person who reconciled

`suspense.sign_off` conflicts with `bank.reconcile` over the period. Whoever matched the lines decided which ones could not be explained; letting them also sign off that the leftovers are acceptable makes the control a formality performed by the person it exists to check — and an unexplained credit is the easiest place in an accounting system to hide a receipt somebody took.

Overridable, unlike the payment pair. A finance function of one has nobody else to sign, and refusing the override would leave them unable to close a period at all: periods left open, backdated postings, and a worse outcome than a recorded override.

## Reconciliation as a document

### The difference between a document and a screen

A screen answers "does this account reconcile now". A document answers "was it reconciled in May, against what, by whom, and does the arithmetic still hold". Only the second is the question an audit asks.

That distinction shows up in one decision: the figures are **frozen**. The ledger balance, the statement balance and the four reconciling items are stored as they stood. Recomputing them when somebody opens the document would silently restate every past reconciliation the moment a prior-period entry was posted — and a reconciliation that restates itself is not evidence of anything.

### The arithmetic is asserted, not displayed

A reconciliation statement showing `ledger + this − that = statement` without checking it is a page somebody reads past, and the figures all look plausible. The identity holds at the moment of reconciling or the row does not exist — a CHECK constraint on the table, not a condition in a service, because an import or a correction script reaches the table without passing one.

The presentation is checked separately, against the stored figures. A statement whose displayed lines do not add to the balance beside them is the specific failure that makes a reconciliation worthless and the one nobody would notice.

### An account that does not reconcile is not reconciled

If the four items do not account for the difference between ledger and statement, authorising a document would assert an identity that does not hold. The refusal names the unexplained figure, because "it does not reconcile" is not something anybody can act on.

### Undoing keeps the document

With a reason and an author, and the figures exactly as authorised. "It was reconciled and then it was not — what changed" is the question, and a table that overwrites cannot answer it. The statement can then be reconciled again.

One standing reconciliation per statement, enforced by a partial unique index so a withdrawn one does not block its replacement. Two live documents for one statement leaves nothing saying which the close should look at.

### A correction is a suggestion, never a lesson learned silently

When somebody rejects what the engine proposed and matches something else, the correction is stored as a **rule candidate**. The engine does not change its behaviour because a person disagreed with it once — an engine that did would propose something different this month, and the only explanation would be a correction somebody made in March.

The same correction made again increments a count rather than writing a second row: one person correcting one match is an exception, and the same correction four times is a rule nobody has written. Once it has happened enough, it is offered back as a rule for somebody to write **deliberately** — with an author, a version and a test, like every other rule in this module.

### And the close rests on it

A period does not close over an active bank account that has statements in the period and no reconciliation standing for the end of it. Every other control in this phase protects one fact; this protects the conclusion — that the cash figure in the accounts is the cash the business has. Cash is the one balance an auditor can confirm independently, which is why it is the first one they ask about.

An account with no statements in the period is not blocked. Refusing a close over an absence of facts makes the control the thing people route around.

```
ledger balance
  + unpresented payments
  − unsettled receipts
  + unrecorded bank items
  + unposted opening balance
  ± classified differences
  = statement balance          ← asserted, not displayed
```

> An earlier draft of this document had the first two signs the other way round. They are written out here in the direction the code reports and `bin/verify banking` proves, because a formula in a specification is what the next implementation is built from.

A reconciliation has a statement period, a closing balance, the full set of matches, an authorizing user and a timestamp. **It is what an auditor re-performs.** Unreconciling requires a reason, is permissioned and audited, and the prior reconciliation is retained rather than overwritten. A completed reconciliation is a precondition of the period close.

## Payment initiation

**PeakFlow never moves money.** It produces an instruction file that a human releases into the bank's own channel. The boundary is stated in the UI, because a product that appears to send money carries a duty of care it cannot discharge.

```
approved bills → run (draft) → prepared → [maker-checker + step-up] → released → PAIN.001
                                                                          │
                                                                          └─► bank channel (human)
```

| Control | Detail |
|---------|--------|
| Maker-checker | Preparer ≠ releaser, enforced **against the audit trail**, not against a role list |
| Step-up auth | Re-authentication at the moment of release, so a stolen session is not enough |
| Change detection | The releaser sees exactly what changed since preparation; any change invalidates the preparation |
| Beneficiary guard | An account changed since the payable was approved **blocks release** until re-verified |
| Duplicate detection | Same beneficiary, same amount, within a window, **across all runs and all entities** — including already-released and already-reconciled payments |
| Immutability | Released runs are immutable; a mistake is a new run plus a recall request |
| Non-overridable | This SoD pair cannot be overridden by the documented-override mechanism |

**SEPA constraints are validated before the file is produced**, not after the bank rejects it: IBAN reachability, the restricted Latin character set, the 140-character remittance limit, amount ceilings, execution-date rules. An Arabic beneficiary name must be transliterated for SEPA — **and the user is shown exactly what will be sent** rather than having it silently mangled.

The generated file is hashed, archived and downloadable once. Regenerating produces a new file with a new hash and an audit entry, so "which file did we actually send" is always answerable.

## The three-way tie

```
accounts_payable ──release──► payments_in_transit ──statement match──► bank
```

The in-transit account is what makes the tie between AP, the bank and the GL possible. It **ages**, and an aged in-transit item means a payment that left the system and never appeared on a statement — a question worth asking, so it is reported and alerted. `ledger:verify` asserts in-transit equals released-and-unreconciled.

## Pass 2: the payment run — PF-156

### The one pass that matches against something we sent

Every other document pass compares a bank line against something a counterparty did: an invoice they owe, a reference they typed, a figure they happened to pay. This one compares it against an instruction **we** issued, on a date we chose, for an amount we computed.

That is why it sits second — above structured remittance and below an exact reference. We know what we sent; the only question is which debit on the statement is it. Its base is 0.75.

### Two shapes, because banks debit batches two ways

A bank handed a payment file either debits the whole batch as one line, or debits each transfer separately. Both are normal, both are what the same file produces at different banks, and an engine that only knew one would leave every statement from the other bank to be matched by hand.

| Shape | `matched_type` | Why it scores where it does |
|---|---|---|
| The run's total | `payment_run` | The figure was computed from the run's own lines minutes before the file went out. Little else on the statement is likely to equal it. |
| One transfer within it | `payment_run_instruction` | A 3,000 transfer in a run of forty is a figure other things on the statement may also equal, and two instructions in one run at the same amount identify neither. |

A single-instruction run carries a penalty rather than the batch bonus: its total **is** its one transfer, so "the batch total matches" and "a transfer matches" are the same fact counted once — and that fact is no more deliberate than any other payment of that amount.

### Only released runs, and only once

A prepared run has not been sent, so a debit equal to its total is a coincidence; proposing it would invite somebody to accept a match for money that never left.

A run already matched is excluded too, at the pass **and** again at acceptance. The pass declines to propose what it has already matched; a pass cannot defend against two proposals accepted in the same second, and the acceptance can. Two statement lines equal to one released batch means either the bank debited twice or this is a different payment — and recording the same money leaving twice produces a reconciliation that balances while saying it.

The acceptance check is its own path, because the document one asks a receivable what it still owes and a payment run has no outstanding balance to absorb anything. The questions are different ones: is it still released, is the figure still the one it was proposed for, and has this debit already been accounted for.

### It never matches a receipt

A payment run pays suppliers. An incoming credit equal to a run's total is a coincidence with a very bad reading — "we were refunded exactly the batch we sent" — and proposing it would put that reading in front of somebody as though the engine believed it.

### What the score is made of

Beyond the shape: the pay date (on the day, within days, or well after — a debit eight days from the pay date means less), the beneficiary being **identified** from the account the bank paid, and the account's last four digits agreeing where nothing stronger is available. The last four costs nothing and decrypts nothing — `last_four` is stored in the clear for exactly this — and is weak evidence, since four digits collide, so it never carries a match on its own.

The window is wider than the five days the document passes allow: ten, because a run's pay date is a request rather than an observation. The bank executes on the next business day, a cross-border transfer takes longer, and a file sent on a Thursday before a holiday lands the following week.

> **A defect this pass found in the payment run.** A matching fixture built two runs of 9,000 and got a second one worth 18,000. A payment run does not reduce a bill's open balance — the payment does, which is PF-176's business — so a bill selected into Monday's released run was selected again by Tuesday's, and both could be released. The supplier is paid twice and the second debit looks as ordinary as the first. The selection now excludes payables held by a prepared or released run, and deliberately **not** by a draft: nothing in a draft has been checked or can be released, so blocking on one would let an abandoned draft hold payables hostage with nothing on the screen saying why.
