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

- [x] Booked and pending entries, entry details, and batch entries with their underlying transactions. A payment run of fifty supplier payments arrives as **one** debit: the bank took one amount, so the entry is the movement and the entry is what must sum into the closing balance, while the fifty references inside `NtryDtls/TxDtls` are what the matcher needs. A reader that took only the entry makes a payment run permanently unmatchable; one that took only the transactions imports fifty movements the bank never made. So the entry is the bank transaction and its `TxDtls` become `bank_transaction_details`, which nothing sums into any balance
- [x] A **pending** entry is reported, marked provisional and kept out of the arithmetic, because `PDNG` on a CAMT.053 is ordinary and is not in the closing balance. Counting it makes a perfectly correct file disagree with its own stated movement, and the import is then refused for a reason that is not the file's fault - which reads as a parser bug
- [x] Structured remittance (`RmtInf/Strd`) with referenced document numbers, their types, their due and remitted amounts, and the creditor reference with its scheme. The highest-quality signal a payer can supply and the one most readers drop, because reading it takes more than a single element
- [x] Bank transaction codes retained as `domain/family/sub-family`, **and** the bank's own proprietary code beside them - which is all several domestic banks send
- [x] Reversal indicators, charges (a stated total or several `Rcrd` amounts summed) and FX instructed amounts with their currency and rate, all kept rather than dropped
- [x] XXE and entity expansion disabled explicitly, through the same `SafeXmlReader` the e-invoicing side uses: no network, no external entities, and any DOCTYPE **refused outright** - "billion laughs" is a DOCTYPE with no external reference at all, so entity limits do not stop it and refusing the declaration does. A malformed file fails with the parser's line and column, and says the position is where the parser gave up rather than where the problem is
- [x] Fixtures from four genuinely different bank shapes: the textbook `.08`; an older `.02` with `PRCD` instead of `OPBD`, `DtTm` dates, a domestic account number, proprietary-only codes, no `FrToDt` and an **overdrawn** closing balance; a batch entry with a refund running against it, a reversal and an FX payment; and a prefixed namespace with the currency only on a balance, remittance split across several `Ustrd` elements, charges as separate records and an entry with no details at all
- [x] **The two sign rules, which are the whole correctness of this.** `CdtDbtInd` gives the real direction of the booked amount, always - `RvslInd` says only that this booking reverses an earlier one and does **not** mean the sign should be flipped. Flipping it is the obvious reading, it is wrong, and it turns a reversed credit into a double debit while every figure stays plausible. And a balance has a direction too: `DBIT` on a current account is an overdraft, which a reader ignoring `Bal/CdtDbtInd` reports as money in the bank
- [x] **And the counterparty depends on the direction.** On a credit it is the debtor; on a debit, the creditor. A reader that always took `Cdtr` is right on half the lines and silently wrong on the rest, where the "counterparty" of every incoming payment comes out as us

### PF-151 · Intraday reports
`Banking` · **3 pts** · depends: PF-150 · standards: ISO 20022 CAMT.052, CAMT.054

- [x] CAMT.052 account report and CAMT.054 debit/credit notification, read by the same reader as the CAMT.053 - they differ in their root element and in which balances they are obliged to carry, and are the same `Ntry` structure below that. Three readers would be three copies of the entry parsing, and the copy that gets a fix is the one somebody was looking at
- [x] Intraday data feeds the cash position but **does not post** and does not reconcile. `BankBalances::statement()` takes the last **booked** statement and `intradayPosition()` is a separate method that states its basis - returning both from one call with a flag would guarantee the wrong one gets read
- [x] Provisional entries are marked on the row and superseded by the booked statement rather than sitting beside it
- [x] **Found while checking this:** continuity counted interim reports. A CAMT.052 imported between two CAMT.053s became the previous statement in the chain, so the next real statement - whose opening is the last *booked* closing - could never be imported. The chain is now the booked statements only, which is what continuity was always about
- [x] **And:** an interim report states no balances at all (MT942 has no `:60F:`/`:62F:` and a CAMT.052 need not carry them either). Rather than invent a figure, the parser reports them absent and the importer opens and closes the statement where the account's booked position already stands - so its booked movement is zero, which is exactly what an interim report moves

### PF-152 · MT940 and MT942
`Banking` · **5 pts** · depends: PF-149 · standards: SWIFT MT940 / MT942

- [x] Legacy, still ubiquitous, and lossy. No structured remittance, no end-to-end id, no ISO transaction code and no counterparty account in any dependable place
- [x] `:61:` statement lines parsed to SWIFT's own grammar - value date, the entry date that carries no year, the `C`/`D`/`RC`/`RD` mark, the comma decimal, the transaction type and the references either side of the `//`. `NONREF` is read as "the bank gave none" rather than stored as a reference that every such line appears to carry
- [x] `:86:` with **per-bank sub-field handling**, as configuration rather than code: the German numbered `?20?` form, SWIFT's tagged `/EREF/` form, and a profile map in `banking.mt940.profiles` because the numbers mean different things at different banks
- [x] An unparseable `:86:` is retained verbatim rather than dropped. Dropping it is the easy choice and the worst one - it is the only place a reference appears in this format, so a dropped one leaves the line unmatchable and unreadable at once
- [x] Documented explicitly as a degraded path in [`banking.md`](../domain/banking.md) and recorded as `fidelity: low` in `banking.formats`, so matching confidence from MT940 is structurally lower rather than incidentally lower. Where a bank offers both, the import takes the CAMT
- [x] **Found while checking this:** MT940 and MT942 recognised each other, so an interim report could be sniffed as a booked statement - which posts it and double-counts every line when the real statement arrives. They are told apart by their balances: an MT940 states `:60F:`/`:62F:` and an MT942 carries `:34F:` instead, which is also the only place its currency appears

### PF-153 · OFX
`Banking` · **3 pts** · depends: PF-149 · standards: OFX 2.x

- [x] OFX and QFX for retail and North American banks
- [x] Both SGML-era and XML-era variants, since real files are still both. Rather than two readers, the SGML form is **closed into XML** first - every unclosed value tag gets its closing tag - and the result goes through the same safe parser, so the XXE and DOCTYPE guards apply to both
- [x] Transaction type mapped to the ISO 20022 code space so downstream routing has one vocabulary. Deliberately coarse: OFX says `DEBIT` where CAMT would say which kind of debit, and inventing a sub-family to fill the gap would state more than the file does - which is how a routing rule comes to fire on a code no bank ever sent. The original type is kept beside it, so a rule can still be written against it
- [x] **Found while checking this:** the first `FITID` was being used as the statement number. It is a *transaction* id, and using it labels the statement "FIT-0001" on every screen - which reads as the bank's own numbering and is not. OFX has no statement number, so the statement falls back to our own sequence

### PF-154 · CSV import
`Banking` · **5 pts** · depends: PF-149 · standards: — · `type:a11y`

- [x] Per-bank column mapping saved as a reusable, shareable template (`csv_import_templates`), with the dialect stored beside the columns. A bank's export does not change month to month, and making somebody map twelve columns every month guarantees that one month two of them go the wrong way round - an import that is plausible rather than broken, because the value date in the booking date column shifts a reconciliation by two days and nothing says so. The header row it was built against is stored too, so a bank that adds a column can be detected rather than silently shifting every index after it
- [x] Date-format, decimal-separator, thousands-separator, sign-convention and delimiter detection, each overridable. The delimiter is chosen by **consistency** rather than frequency - a remittance full of commas makes the comma the most frequent character in a semicolon-delimited file. The separators are decided across every amount in the file at once, by which reading leaves no value contradicting another, because one value settles nothing: `1.234` is ambiguous and `1.234,56` is not
- [x] Three sign conventions, because all three are common: one signed column, separate debit and credit columns, and an unsigned column with a direction column beside it. The second is the one that quietly halves a statement - a reader expecting one amount column finds the debit column empty on credit rows and imports zero - and a row with **both** filled is refused, because the usual cause is a running-balance column mapped as one of them
- [x] A **preview of the first rows as they will be interpreted**, paired with the raw cells they came from. That pairing is the point: "01.05.2026 → 2026-05-01" is checkable at a glance and "1.000,00 → 1000.0000" is the line where somebody spots that their thousands are being read as decimals. A preview of the raw file proves only that the file was uploaded. The detector also states its **evidence** for each setting, because "detected" with nothing behind it is what somebody clicks past
- [x] Where both readings of a decimal separator survive the whole file, it is **reported as ambiguous** rather than guessed at - a wrong answer there is off by a factor of a thousand with every row still looking right. Dates that read equally well day-first and month-first are reported the same way
- [x] Encoding detection with a byte-order mark, UTF-8 and Windows-1252, and a row that will not read **stops the import** rather than being skipped: importing the rest leaves the month short by those rows with nothing saying which, and the difference surfaces weeks later as a reconciliation that does not tie. Every unreadable row is reported together, with its number and its contents, so one pass through the file fixes all of them
- [x] **Found while checking this:** dates were being collected as numeric values and poisoning the separator inference - `01.05.2026` has a two-digit middle group, which rules out the comma as a decimal separator, so one date column made every German file read as English. And a `.xlsx` renamed `.csv` was reported as "a date range with no transactions in it", because Windows-1252 is single-byte and accepts any byte sequence; it is now recognised as a ZIP container and says to export CSV from the bank instead
- [ ] Keyboard-operable mapping UI with a clear error summary. The service side is complete - detection, evidence, preview and refusals - and the screen that presents it is the remaining work

### PF-155 · Transaction codes and strategy routing
`Banking` · **3 pts** · depends: PF-150 · standards: ISO 20022 External Code Sets

- [x] Codes retained and used to pick the default matching strategy: `PMNT/ICDT/ESCT` outgoing credit transfers against payment runs, `PMNT/RCDT` incoming against receivables, `PMNT/MDOP` fees against the rule engine. The bank's own code is the cheapest signal there is about what a line **is**, and reading it first removes a whole class of error - a bank charge run through the document passes occasionally produces a confident wrong match on amount alone, a 50 charge against a 50 invoice, which survives review because the figures agree
- [x] **Most specific wins.** `PMNT/RCDT/ESCT` is tried, then `PMNT/RCDT`, then `PMNT` - which is the whole reason the sub-family is stored. `PMNT/RCDT/RRTN` is a *returned* incoming payment, not a new receipt, and routing it as one applies a bounced payment to a different invoice
- [x] The code-to-strategy map is data, so a bank using `PMNT/MCOP` where its neighbour uses `PMNT/MDOP` is configuration rather than a release that arrives a month after the problem. The checks assert that every strategy a code names has a pass list, and that every pass named is one the engine declares - a typo in a pass name is a pass that never runs, and the only sign of it is a slightly worse match rate
- [x] An unmapped code falls through to the generic passes rather than failing: an unfamiliar code is a transaction like any other, and refusing it would make a whole bank unusable because of a code set nobody here has seen. The routing also explains itself in a sentence naming the code, so a bank sending its charges with the wrong code is a configuration fix rather than a reported matching bug

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
