# Phase 08 — Bank accounts, statements and reconciliation

**21 tasks · 109 points**

Banking comes after sales and purchasing because reconciliation needs something to reconcile **to**. Building the matching engine first means tuning it against fixtures rather than against real receivables, and the engine's whole value is in how it behaves against messy real data.

The distinction this phase is built on: **the GL account holds what PeakFlow believes; the statement holds what the bank says.** Reconciliation is the process of explaining the difference. A design that stores one balance for both has deleted the question.

---

### PF-145 · Bank accounts
`Banking` · **5 pts** · depends: PF-008, PF-037 · standards: ISO 13616, ISO 9362, ISO 4217 · `type:schema`

- [x] Institution, branch, holder legal name, currency, IBAN, BIC, and domestic identifiers where IBAN does not apply. An account is reachable by **something** - an IBAN, or a domestic scheme with its identifier - because a row with neither is an account nobody can pay into, which looks complete and is discovered on the day somebody tries to pay from it
- [x] Identifiers validated before they are stored, so an invalid IBAN cannot be. The value object checks the country's declared length and the mod-97 check digits, not a regex: a regex accepts `GB00` followed by the right number of characters, which is the shape of every mistyped IBAN, and the check digits exist precisely so a typo is caught where somebody typed it rather than in a payment file three weeks later. An IBAN whose country disagrees with the institution's is **refused**, not warned about - it is either the wrong IBAN or the wrong institution, the refusal says so rather than letting somebody change the institution to match, and a payment file built from the pair would be rejected by the first bank that read it
- [x] An account belongs to exactly one entity. `entity_id` is a single non-null column, so a shared account is not a validation rule but an **absent column** - the strongest form the refusal can take. [The reasoning is documented](../domain/banking.md): the cash belongs to whichever entity holds the account and the others are being lent money, which is an inter-company loan with terms, interest and a tax position, not a column on a bank account. Modelling it as shared makes every entity's cash figure a guess and the inter-company balance invisible
- [x] Opening balance and opening date recorded, and immutable once a statement has been imported - it is the one figure nothing else can reconstruct, so changing it afterwards puts every reconciliation since the first import out by the difference with no document recording why. Refused by the service **and** by a trigger, each checked separately: a check that accepts a refusal from either layer passes while one of them is broken, which was proved by removing one
- [x] Details encrypted at the column level, `$hidden` on every encrypted column so none reaches a JSON response, and the audit record carries the last four characters and never the identifier - an audit log is read by more people than the account screen, exported more often and retained longer. The tail is stored in the clear because every screen that mentions an account has to identify it, and decrypting on every row of a list is how a page comes to take four seconds
- [x] **Found while checking this task:** `account_roles` is unique on `(entity_id, role)`, so requiring the `bank` role on every bank account's GL link capped an entity at one bank account for ever - a SAR operating account alongside a USD one was unrepresentable. The role now names a **subtree**: the role account itself, or any postable account beneath it. The refusal names that route explicitly, because without it the only visible fix is to move the role, which breaks every posting rule that credits the bank

### PF-146 · The GL mirror and three balances
`Banking` · **5 pts** · depends: PF-145, PF-038 · standards: — · `risk:high`

- [x] A 1:1 link to a postable GL account in the `bank` subtree; the link is immutable. Re-pointing it moves history silently - every posting made through the old link stays where it was, so the ledger balance changes without a single journal entry being written and last month's reconciliation no longer ties to anything. A header account cannot be mirrored either, because its ledger balance is permanently zero and the failure would otherwise surface on the first payment as a refused posting with nothing to do with banking
- [x] **Three balances tracked and always returned together**: ledger (posted journal lines, read live rather than from a cache, because this is the figure a reconciliation is judged against and a stale cache would make the difference a cache problem), statement (the last **booked** closing balance that stands), available (statement minus unpresented payments). Available deliberately does **not** add deposits in transit: a cheque banked this morning is not money to spend this afternoon, and the whole point of a separate available figure is that it is the safe one
- [x] The difference is always itemized into four named components - unpresented payments, unsettled receipts, unrecorded bank items and an unposted opening balance - with whatever is left over reported as **unexplained** on its own line rather than folded into one of them, because folding it in is how a difference comes to look explained when it is not. "The bank says 90,000 and we say 93,500" is where a month of work starts; the components are where it finishes
- [x] All three diverge correctly after a payment is released and before the statement arrives, and the reconciliation still **agrees** - because that is Tuesday, not an exception, and a system that flagged its own normal operation would have the flag turned off within a week. The check puts every mechanism in play at once and asserts the components total the headline difference, rather than one at a time where two compensating sign errors would pass
- [x] **Found while checking this task:** the identity's signs. `unrecorded` was added where it must be subtracted, and deposits in transit were missing entirely, so every unmatched bank item reported exactly twice itself as unexplained and every unsettled receipt reported as a mystery. The four-way derivation is now written out in [the domain document](../domain/banking.md) in the direction the code reports — an earlier draft of that document had two of the signs the other way round
- [x] **And:** the opening journal entry was being counted as a deposit in transit, because the no-statement window started **on** the opening date rather than after it. The ledger and the statement agreed at 50,000 while the reconciliation reported 50,000 banked-not-credited against 50,000 unexplained - both invented, cancelling, and the only sign of it was `agrees` going false on an account that was perfectly reconciled

### PF-147 · Statements as continuous objects
`Banking` · **5 pts** · depends: PF-146 · standards: ISO 20022 · `risk:high` `type:schema`

- [x] `bank_statements`: account, the bank's own statement number **and** our sequence, period, opening and closing balance, source format, imported-by, imported-at, the file's name and its content hash. Two numbers because banks number statements per month, per page or per download - continuity is asserted on **our** sequence and the bank's label is kept verbatim, because the person reconciling has the paper statement in front of them and that is what it says
- [x] **Each import asserts its opening balance equals the previous statement's closing balance**, in the database. A mismatch stops the import and the refusal names both balances, the statement it compared against, its period and the gap - which is what somebody needs in order to find the missing file. A continuity check on the *statement number* would fire on a bank that simply numbers differently, and the first thing anybody does with a check that fires wrongly is turn it off
- [x] A deliberate first-statement case, which compares against the account's own opening balance instead - and refuses a first statement that disagrees with it, because importing anyway starts the account's history with a difference nobody can explain
- [x] A documented procedure for a genuine bank-side gap: `recordAGap()` writes a statement with no lines for the period, opening where the last one closed **by construction**, closing where the bank confirms, and a reason with a longer minimum than most. The history stays continuous and the hole becomes a figure somebody signed for. The continuity refusal names this route, because a person holding a file their bank will not re-issue otherwise sees only the configuration flag - and once that is off, the next *accidental* gap passes silently
- [x] A statement cannot be deleted; it is superseded, with the supersession attributed, reasoned and audited, and the old row kept with a pointer to its replacement. Because continuity would otherwise refuse a replacement imported while the statement it replaces is still current, the supersession happens in the same transaction as the import; and a statement already superseded cannot be superseded again, which would leave two statements claiming to replace it and no way to tell which one the history runs through
- [x] **Added while checking this task:** a statement whose lines do not add up to its own stated movement is refused before anything is written. That file is a parse that lost something - a batch entry read as one line, a page the parser stopped at, a reversal dropped - and the difference is the only sign of it. The refusal states the movement, the sum, the line count and the difference, because the difference is the string somebody greps the file for

### PF-148 · Bank transactions
`Banking` · **5 pts** · depends: PF-147 · standards: ISO 20022 · `type:schema`

- [x] Booking date **and** value date, both stored - interest, FX and forecasting use the value date; the GL posts on the booking date. Collapsing them into one date is a bug that surfaces at month end
- [x] Amount, currency, counterparty name, account and BIC, remittance information structured and unstructured, bank reference, end-to-end id, mandate id, the ISO 20022 four-level transaction code and the bank's proprietary one, reversal flag, charges, and the instructed amount with its currency and rate
- [x] **Immutable once imported** - they are a third party's assertion, and a line you disagree with is a line to dispute with the bank: editing it locally produces a statement that reconciles perfectly to our ledger and to nothing the bank has ever sent. A trigger refuses any change to an imported column and says what to do instead. Enrichment lives in `bank_transaction_enrichment`, and a party attributed there must say **how** it was decided - "the account number is on file" and "somebody chose from a list" are the same party at different levels of confidence, and the second is the one an auditor asks about
- [x] Declaratively partitioned by booking month, like the journal, with partitions created ahead of time rather than on demand: a partition created during a request is a DDL lock taken while somebody is importing, and the first import of a new month is exactly when the month-end rush happens
- [x] `DELETE` revoked at the role level rather than refused by a trigger, because a trigger can be disabled by whoever owns the table. **Found while checking this task:** the first version revoked on the partitioned parent only. PostgreSQL checks privileges on the relation a statement names, so `DELETE FROM bank_transactions_2026_05` was permitted - thirty-seven partitions, thirty-seven holes, and the only sign of it a privilege query. It now goes through the `peakflow:no-delete` marker, which the provisioner applies to the parent and every partition, and the check asks per partition

### PF-149 · Idempotent import
`Banking` · **5 pts** · depends: PF-148 · standards: — · `risk:high`

People re-download overlapping statement files constantly.

- [x] Keyed on `(bank_account, bank_reference)` where the bank supplies one, otherwise a SHA-256 of `(value_date, amount, currency, counterparty_account, counterparty_name, remittance, sequence)`. `identity_key` is a **generated column**, not something the import computes: an import that forgot to compute it is an import that duplicates, and the unique index could not see the difference. The date goes in as a day count rather than as text, because `to_char` and `date::text` are `STABLE` - they read `DateStyle`, so the key would change meaning with a session setting, which PostgreSQL rightly refuses in a generated column
- [x] Re-importing an overlapping file is a no-op that **reports** what it skipped - how many landed, how many were already there, and for each skipped line its amount, its date and the bank's reference where it has one. An import that does nothing and says nothing leaves the person unsure whether it worked, and the usual next step is to try again with a wider date range, which is how the same afternoon gets imported four times. The summary says in words that an overlapping file is normal, so a legitimate re-import does not read as a failure. A byte-identical file is caught by its content hash before anything is written, and says which statement it already is and when
- [x] Two genuinely identical transactions on one day are distinguishable by their sequence within the statement, so deduplication cannot swallow a real duplicate payment. A customer paying the same amount twice in a morning is ordinary, and collapsing the pair hides the error this whole area exists to catch. The check asserts both that the pair survives and that nothing *else* distinguishes them - same day, same amount, same counterparty, same remittance - so it is the sequence doing the work
- [x] The checks import the same file three times and an overlapping file, and assert the transaction count after each. Three rather than two because the second import takes a different path from the third, and a check that stopped at two would not notice an implementation that recorded the no-op as a new row with the same hash
- [x] **Found while building this:** the skip is detected with `insert … on conflict do nothing` and the affected-row count, not by catching the unique violation. In PostgreSQL a failed statement aborts its transaction, so a caught violation would have turned every remaining line of the file into `current transaction is aborted` - the first line of an overlapping file would have taken the whole import with it

### PF-150 · The CAMT.053 parser
`Banking` · **8 pts** · depends: PF-149 · standards: ISO 20022 CAMT.053.001.08 · `risk:high`

The target format and the richest one.

- [ ] Booked and pending entries, entry details, batch entries with their underlying transactions
- [ ] Structured remittance (`RmtInf/Strd`) with referenced document numbers and amounts, which is what makes pass 4 possible
- [ ] Bank transaction codes retained as `domain/family/sub-family`
- [ ] Reversal indicators, charges, and FX instructed amounts handled rather than dropped
- [ ] XXE and entity expansion disabled explicitly; a malformed file fails safely with a line reference
- [ ] Fixtures from at least four real-bank shapes, because the standard permits more variation than its name suggests

### PF-151 · Intraday reports
`Banking` · **3 pts** · depends: PF-150 · standards: ISO 20022 CAMT.052, CAMT.054

- [ ] CAMT.052 account report and CAMT.054 debit/credit notification
- [ ] Intraday data feeds the cash position but **does not post** and does not reconcile - it is provisional, and treating it as final double-counts when the CAMT.053 arrives
- [ ] Provisional entries are visibly marked and are superseded by the booked statement

### PF-152 · MT940 and MT942
`Banking` · **5 pts** · depends: PF-149 · standards: SWIFT MT940 / MT942

- [ ] Legacy, still ubiquitous, and lossy
- [ ] `:61:` statement lines and `:86:` information parsed, with **per-bank `:86:` sub-field handling** because that field is semi-structured free text and its layout is bank-specific
- [ ] An unparseable `:86:` is retained verbatim rather than dropped, so a human can still read it
- [ ] Documented explicitly as a degraded path: matching confidence from MT940 is structurally lower than from CAMT.053, and the UI says so

### PF-153 · OFX
`Banking` · **3 pts** · depends: PF-149 · standards: OFX 2.x

- [ ] OFX and QFX for retail and North American banks
- [ ] Both SGML-era and XML-era variants, since real files are still both
- [ ] Transaction type mapped to the ISO 20022 code space so downstream routing has one vocabulary

### PF-154 · CSV import
`Banking` · **5 pts** · depends: PF-149 · standards: — · `type:a11y`

- [ ] Per-bank column mapping saved as a reusable, shareable template
- [ ] Date-format, decimal-separator, thousands-separator and sign-convention detection, each overridable - a European CSV with `1.234,56` and a debit column is the normal case, not the edge case
- [ ] A **preview of the first rows as they will be interpreted**, before anything is imported
- [ ] Encoding detection, and a clear failure for a file that is not what it claims to be
- [ ] Keyboard-operable mapping UI with a clear error summary

### PF-155 · Transaction codes and strategy routing
`Banking` · **3 pts** · depends: PF-150 · standards: ISO 20022 External Code Sets

- [ ] Codes retained and used to pick the default matching strategy: `PMNT/ICDT/ESCT` outgoing credit transfers match against payment runs, `PMNT/RCDT` incoming against receivables, `PMNT/MDOP` fees against the rule engine
- [ ] The code-to-strategy map is data, so a bank using an unusual code is configuration and not a code change
- [ ] An unmapped code falls through to the generic passes rather than failing

### PF-156 · Matching passes 1 and 2
`Banking` · **8 pts** · depends: PF-155, PF-174 · standards: — · `risk:high`

Cheapest and most certain first.

- [ ] Pass 1, exact reference: a payment reference, invoice number or end-to-end id found in the remittance field, matched against open documents
- [ ] Pass 2, payment-run match: an outgoing amount equal to a released batch's total, or to a line within it
- [ ] Reference extraction tolerates the mangling banks apply - truncation, case folding, inserted spaces, stripped punctuation - without matching on a substring so short it is coincidental
- [ ] Each proposal carries a confidence score and a **human-readable reason**
- [ ] A matched document's remaining balance is recomputed, never assumed

### PF-157 · Matching passes 3 and 4
`Banking` · **5 pts** · depends: PF-156 · standards: ISO 20022 `RmtInf/Strd`

- [ ] Pass 3: exact amount plus party plus a date window, where the party is identifiable from the counterparty account
- [ ] Pass 4: structured remittance referenced documents, which is the highest-quality signal when a payer supplies it
- [ ] A partial amount against a single open document proposes a partial allocation rather than being discarded
- [ ] Ambiguity - two equally good candidates - is surfaced as a choice, never resolved silently

### PF-158 · Subset-sum matching
`Banking` · **8 pts** · depends: PF-157 · standards: — · `risk:high`

One credit against a combination of open invoices, which is how most customers actually pay.

- [ ] Bounded search with an explicit candidate cap and a time budget, so a customer with 400 open invoices cannot hang the engine
- [ ] Deterministic tie-breaking, oldest first, and the chosen combination is shown in full before it is applied
- [ ] Tolerance for a small difference, classified by `PF-164` rather than absorbed
- [ ] Never auto-applied above a configured candidate count - beyond that it proposes and a human confirms
- [ ] A test with a pathological input asserts the time budget is respected

### PF-159 · The rule engine
`Banking` · **5 pts** · depends: PF-157 · standards: —

- [ ] Tenant-authored rules on counterparty, transaction code, amount range, remittance pattern and account, producing a GL account plus dimensions
- [ ] Ordered with first-match-wins, and a rule tester that shows which historical transactions a rule would have matched **before** it is saved
- [ ] Handles the recurring non-document traffic: bank fees, interest, standing charges, payroll, inter-account transfers
- [ ] Rules are per entity, auditable, and versioned so a past match can be explained by the rule as it was

### PF-160 · Fuzzy party matching
`Banking` · **3 pts** · depends: PF-157 · standards: —

- [ ] Normalized name distance on the counterparty: case, punctuation, legal-form suffixes, Arabic diacritics and alef variants, and Latin transliteration differences
- [ ] **Proposal only, never auto-applied** at any confidence
- [ ] A confirmed proposal stores the counterparty identifier against the party, so the same payer matches exactly next time - the engine learns by accumulating identifiers, not by lowering its threshold

### PF-161 · Confidence and thresholds
`Banking` · **5 pts** · depends: PF-160 · standards: — · `risk:high`

- [ ] A documented scoring model per pass, with the factors that raise and lower a score
- [ ] An auto-apply threshold per tenant, with a platform-enforced ceiling
- [ ] Everything auto-applied is **auditable and individually reversible**, and appears on a review list for a configurable window
- [ ] Nothing below the threshold is hidden - low-confidence proposals are shown as suggestions, because a hidden proposal is worse than a wrong one
- [ ] A per-pass accuracy report: proposed, accepted, corrected, rejected

### PF-162 · Splits
`Banking` · **5 pts** · depends: PF-158 · standards: —

- [ ] One bank line across many documents, and many bank lines against one document
- [ ] Partial matching with the remainder left open and visible on both sides
- [ ] A split is one atomic operation - a half-applied split is not a state the system can be in
- [ ] Unsplitting reverses cleanly, with the reversal audited

### PF-163 · The suspense account
`Banking` · **5 pts** · depends: PF-161, PF-038 · standards: — · `risk:high` `type:posting`

Unmatched lines land somewhere explicit rather than nowhere.

- [ ] An unmatched transaction posts to the `suspense` control account, so the bank account reconciles even while the item is unexplained
- [ ] Suspense **ages**, appears on a dashboard, and is reported by age bucket and by entity
- [ ] **An entity cannot close a period with a non-zero suspense balance** unless a controller signs it off with a reason, which is recorded and reported
- [ ] Clearing suspense is a reclassification posting, never an edit of the original
- [ ] Included as an assertion in `ledger:verify`

### PF-164 · Difference classification
`Banking` · **5 pts** · depends: PF-163 · standards: — · `risk:high` `type:posting`

Differences are classified before they are written off.

- [ ] Types: bank charge, FX difference, short payment, overpayment, rounding, withholding deducted at source
- [ ] Each has its own posting rule and its own tolerance; `withholding` resolves against `PF-083` rather than being treated as a short payment
- [ ] Tolerances are tenant settings with a **platform-enforced ceiling** - "write off anything under 50" is a policy decision, not a developer's constant
- [ ] A write-off above tolerance requires the `reconciliation.write_off` permission and is separated by SoD from matching
- [ ] A standing report of differences by type and by period, because a rising bank-charge line is information

### PF-165 · Reconciliation as a document
`Banking` · **8 pts** · depends: PF-164 · standards: — · `risk:high`

What an auditor re-performs.

- [ ] A reconciliation has a statement period, a closing balance, the full set of matches, an authorizing user and a timestamp
- [ ] It produces a reconciliation statement: ledger balance, plus and minus the itemized reconciling items, equals the statement balance - and the arithmetic is asserted, not displayed
- [ ] **Unreconciling requires a reason**, is permissioned and is audited; the prior reconciliation is retained rather than overwritten
- [ ] When a user corrects a proposed match, the correction is stored as a **rule candidate** and offered back as a suggested rule - the engine never silently changes its own behaviour
- [ ] A completed reconciliation is a precondition of the period close checklist
