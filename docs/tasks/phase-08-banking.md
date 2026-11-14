# Phase 08 — Bank accounts, statements and reconciliation

**21 tasks · 109 points**

Banking comes after sales and purchasing because reconciliation needs something to reconcile **to**. Building the matching engine first means tuning it against fixtures rather than against real receivables, and the engine's whole value is in how it behaves against messy real data.

The distinction this phase is built on: **the GL account holds what PeakFlow believes; the statement holds what the bank says.** Reconciliation is the process of explaining the difference. A design that stores one balance for both has deleted the question.

---

### PF-145 · Bank accounts
`Banking` · **5 pts** · depends: PF-008, PF-037 · standards: ISO 13616, ISO 9362, ISO 4217 · `type:schema`

- [ ] Institution, branch, holder legal name, currency, IBAN, BIC, and domestic identifiers where IBAN does not apply
- [ ] Identifiers validated in the constructor, so an invalid IBAN cannot be stored
- [ ] An account belongs to exactly one entity - a shared bank account across legal entities is a structure PeakFlow refuses, and says why
- [ ] Opening balance and opening date recorded, and immutable once a statement has been imported
- [ ] Details encrypted at the column level and excluded from logs, exports and webhook payloads

### PF-146 · The GL mirror and three balances
`Banking` · **5 pts** · depends: PF-145, PF-038 · standards: — · `risk:high`

- [ ] A 1:1 link to a GL account carrying the `bank` control role; the link is immutable
- [ ] **Three balances tracked and always displayed together**: ledger balance (posted GL), statement balance (last imported closing), available balance (statement minus unpresented payments)
- [ ] The difference between ledger and statement is always itemized into its reconciling components - never shown as a single unexplained figure
- [ ] A test asserts all three diverge correctly after a payment is released but before the statement arrives, which is the normal state

### PF-147 · Statements as continuous objects
`Banking` · **5 pts** · depends: PF-146 · standards: ISO 20022 · `risk:high` `type:schema`

- [ ] `bank_statements`: account, statement number, period, opening balance, closing balance, source format, imported-by, imported-at
- [ ] **Each import asserts its opening balance equals the previous statement's closing balance** for that account. A mismatch stops the import and reports the gap rather than importing a hole - the alternative is a reconciliation that can never be completed and nobody knows why
- [ ] A deliberate first-statement case, and a documented procedure for a genuine bank-side gap
- [ ] A statement cannot be deleted; it is superseded, with the supersession audited

### PF-148 · Bank transactions
`Banking` · **5 pts** · depends: PF-147 · standards: ISO 20022 · `type:schema`

- [ ] Booking date **and** value date, both stored - interest, FX and forecasting use the value date; the GL posts on the booking date. Collapsing them into one date is a bug that surfaces at month end
- [ ] Amount, currency, counterparty name and account, remittance information (structured and unstructured), bank reference, end-to-end id, ISO 20022 transaction code
- [ ] **Immutable once imported** - they are a third party's assertion. Enrichment lives in separate nullable columns, never by rewriting the imported value
- [ ] Declaratively partitioned by booking month, like the journal
- [ ] `DELETE` revoked at the role level

### PF-149 · Idempotent import
`Banking` · **5 pts** · depends: PF-148 · standards: — · `risk:high`

People re-download overlapping statement files constantly.

- [ ] Keyed on `(bank_account, bank_reference)` where the bank supplies one, otherwise a content hash of `(value_date, amount, currency, counterparty, remittance, sequence)`
- [ ] Re-importing an overlapping file is a no-op that **reports** what it skipped, rather than silently doing nothing
- [ ] Two genuinely identical transactions on one day are distinguishable by their sequence within the statement, so deduplication cannot swallow a real duplicate payment
- [ ] A test imports the same file three times, and an overlapping file, and asserts the transaction count

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
