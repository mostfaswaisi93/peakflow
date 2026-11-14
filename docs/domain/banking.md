# Banking

The [README](../../README.md#banking) covers the shape. This document covers the matching engine in detail, the statement formats and their failure modes, and the payment-release control.

## Three balances, always shown together

| Balance | Source | Meaning |
|---------|--------|---------|
| **Ledger** | Posted GL on the bank account's mirror | What PeakFlow believes |
| **Statement** | Last imported closing balance | What the bank says |
| **Available** | Statement minus released-but-unpresented payments | What can be spent |

> **The difference between ledger and statement is the reconciliation question, and it is always itemized** — never shown as a single unexplained figure. A design that stores one balance for both has deleted the question.

All three diverge correctly after a payment is released and before its statement arrives. That is the normal state, and it has a test.

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

**Idempotency.** Keyed on `(bank_account, bank_reference)` where the bank supplies one, otherwise a content hash of `(value_date, amount, currency, counterparty, remittance, sequence_in_statement)`. People re-download overlapping files constantly. Including the sequence means two genuinely identical transactions on one day stay distinguishable, so deduplication cannot swallow a real duplicate payment.

**Immutability.** A bank transaction is a third party's assertion. Enrichment — the counterparty we think it is, the category we guessed — lives in **separate nullable columns**, never by rewriting the imported value.

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
  + deposits in transit
  − unpresented payments
  ± classified differences
  = statement balance          ← asserted, not displayed
```

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
