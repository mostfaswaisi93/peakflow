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

### Reference extraction

Banks mangle references: truncation, case folding, inserted spaces, stripped punctuation, concatenation with other text. Extraction tolerates all of it — **without matching on a substring so short it is coincidental**. A three-character match against a six-digit invoice number is noise, and treating it as a signal is how an engine applies a payment to the wrong invoice confidently.

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

When a user corrects a proposed match, the correction is stored as a **rule candidate** and offered back as a suggested rule. A confirmed fuzzy-party match stores the counterparty identifier against the party, so the same payer matches **exactly** next time.

> The engine learns by accumulating identifiers and by proposing rules a human approves. It **never silently changes its own behaviour**, because an engine whose thresholds move on their own cannot be audited.

## Differences, classified before they are written off

| Classification | Posting |
|----------------|---------|
| Bank charge | Dr bank charges / Cr bank |
| FX difference | Dr/Cr `fx_gain_loss` |
| Short payment | Remains open, or written off with permission |
| Overpayment | Customer credit balance |
| Rounding | Dr/Cr `rounding_difference`, within tolerance |
| **Withholding deducted at source** | Dr `withholding_tax` — **not** a short payment |

Tolerances are tenant settings with a platform ceiling. "Write off anything under 50" is a policy decision, not a developer's constant. A write-off above tolerance requires `reconciliation.write_off` and is SoD-separated from matching.

## Suspense

An unmatched transaction posts to the `suspense` control account, so the bank account reconciles **even while the item is unexplained**. Suspense ages, appears on a dashboard, and is reported by bucket and entity.

**An entity cannot close a period with a non-zero suspense balance** unless a controller signs it off with a reason — recorded, reported, and asserted by `ledger:verify`. Clearing suspense is a reclassification posting, never an edit of the original.

## Reconciliation as a document

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
