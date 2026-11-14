# ISO 20022 mapping

CAMT (statements in) and PAIN (payments out), element by element. ISO 20022 permits more variation than its name suggests, so the fixtures matter as much as the mapping: **at least four real-bank shapes per format**, because two banks implementing the same message version will not produce the same document.

## CAMT.053.001.08 — Bank to Customer Statement

### Statement header → `bank_statements`

| XPath | Column | Notes |
|-------|--------|-------|
| `BkToCstmrStmt/Stmt/Id` | `statement_number` | Not always sequential across banks; the continuity check uses balances, not this |
| `Stmt/ElctrncSeqNb` | `electronic_sequence` | Where supplied, used as a secondary continuity signal |
| `Stmt/CreDtTm` | `created_at_source` | |
| `Stmt/Acct/Id/IBAN` | → `bank_accounts` | Resolution is by IBAN; a statement for an unknown account is rejected, not auto-created |
| `Stmt/Acct/Ccy` | — | **Must match the account's currency**; a mismatch stops the import |
| `Stmt/FrToDt/FrDtTm`, `ToDtTm` | `period_start`, `period_end` | |
| `Stmt/Bal[Tp/CdOrPrtry/Cd='OPBD']/Amt` | `opening_balance` | **Must equal the previous statement's `CLBD`** |
| `Stmt/Bal[Tp/CdOrPrtry/Cd='CLBD']/Amt` | `closing_balance` | |
| `Stmt/Bal[Tp/CdOrPrtry/Cd='CLAV']/Amt` | `available_balance` | Closing available |
| `Stmt/Bal/CdtDbtInd` | — | `CRDT`/`DBIT` — a debit balance is stored as a negative, not as a flag |

> **The continuity assertion is on the balance, not the statement number.** Banks number statements inconsistently — per month, per page, per download — so `OPBD(n) = CLBD(n-1)` is the only reliable check. A mismatch stops the import and reports the gap.

### Entries → `bank_transactions`

| XPath | Column | Notes |
|-------|--------|-------|
| `Stmt/Ntry/NtryRef` | `bank_reference` | The idempotency key where present |
| `Stmt/Ntry/Amt` + `@Ccy` | `amount`, `currency` | |
| `Stmt/Ntry/CdtDbtInd` | sign of `amount` | `DBIT` → negative. Stored signed, like a journal line |
| `Stmt/Ntry/RvslInd` | `is_reversal` | A reversal is a **new** transaction, never a deletion of the original |
| `Stmt/Ntry/Sts/Cd` | `status` | `BOOK` posts; `PDNG` is provisional and does **not** post |
| `Stmt/Ntry/BookgDt/Dt` | `booking_date` | **The GL posts on this** |
| `Stmt/Ntry/ValDt/Dt` | `value_date` | **FX and forecasting use this.** Both are stored; collapsing them is a month-end bug |
| `Stmt/Ntry/BkTxCd/Domn/Cd` | `txn_domain` | e.g. `PMNT` |
| `Stmt/Ntry/BkTxCd/Domn/Fmly/Cd` | `txn_family` | e.g. `RCDT`, `ICDT`, `MDOP` |
| `Stmt/Ntry/BkTxCd/Domn/Fmly/SubFmlyCd` | `txn_subfamily` | e.g. `ESCT` |
| `Stmt/Ntry/BkTxCd/Prtry/Cd` | `txn_proprietary` | The bank's own code, retained verbatim |
| `Stmt/Ntry/AddtlNtryInf` | `additional_info` | |
| `Stmt/Ntry/Chrgs/Rcrd/Amt` | `charges_amount` | Deducted charges, classified rather than absorbed |

### Entry details → the matching signals

| XPath | Column | Used by |
|-------|--------|---------|
| `NtryDtls/TxDtls/Refs/EndToEndId` | `end_to_end_id` | **Pass 2** — matches a released payment instruction |
| `NtryDtls/TxDtls/Refs/InstrId` | `instruction_id` | Pass 2 |
| `NtryDtls/TxDtls/Refs/MndtId` | `mandate_id` | Direct debit reconciliation |
| `NtryDtls/TxDtls/RmtInf/Ustrd` | `remittance_unstructured` | **Pass 1** — reference extraction |
| `RmtInf/Strd/CdtrRefInf/Ref` | `structured_creditor_reference` | **Pass 4** — the highest-quality signal |
| `RmtInf/Strd/RfrdDocInf/Nb` | `referenced_documents[]` | **Pass 4** — with amounts, enabling exact multi-invoice matching |
| `RmtInf/Strd/RfrdDocAmt/RmtdAmt` | `referenced_amounts[]` | Pass 4 and pass 5 |
| `RltdPties/Dbtr/Pty/Nm` | `counterparty_name` | Passes 3 and 7 |
| `RltdPties/DbtrAcct/Id/IBAN` | `counterparty_account` | **Pass 3** — and the identifier stored on confirmation so pass 7 is not needed again |
| `RltdPties/Cdtr/Pty/Nm` | `counterparty_name` (outgoing) | |
| `AmtDtls/InstdAmt/Amt` | `instructed_amount` | FX: what was instructed, before conversion |
| `AmtDtls/TxAmt/Amt` | `transaction_amount` | What was booked |
| `NtryDtls/TxDtls/AmtDtls/.../CcyXchg/XchgRate` | `fx_rate_applied` | The bank's rate, used to explain an FX difference rather than guess at it |

**Batch entries** (`Ntry` with several `TxDtls`) are expanded into child transactions linked to the parent, because a batched payroll credit must be matchable per employee, not only in total.

### Transaction code → matching strategy

| Domain / Family / Sub | Means | Default strategy |
|-----------------------|-------|------------------|
| `PMNT/RCDT/ESCT` | Received SEPA credit transfer | Receivable passes 1, 3, 4, 5 |
| `PMNT/RCDT/BOOK` | Internal book transfer in | Receivable passes |
| `PMNT/ICDT/ESCT` | Issued SEPA credit transfer | **Payment-run match (pass 2)** |
| `PMNT/RDDT/ESDD` | Received direct debit | Mandate reconciliation |
| `PMNT/MDOP/*` | Fees, charges | **Rule engine (pass 6)** |
| `PMNT/MCOP/*` | Miscellaneous credit | Rule engine |
| `ACMT/*` | Account management | Rule engine |
| *unmapped* | — | Falls through to the generic passes rather than failing |

The map is **data**, so a bank using an unusual code is configuration and not a code change.

## CAMT.052 / CAMT.054 — intraday

| Message | Used for | Constraint |
|---------|----------|------------|
| CAMT.052 Account Report | Current cash position | **Provisional.** Does not post, does not reconcile |
| CAMT.054 Debit/Credit Notification | Near-real-time awareness | Provisional |

Provisional entries are visibly marked and are **superseded** by the booked CAMT.053. Treating intraday data as final double-counts when the statement arrives, which is the specific failure this separation prevents.

## MT940 / MT942 — the degraded path

| Tag | Content | Mapping |
|-----|---------|---------|
| `:20:` | Transaction reference | `statement_reference` |
| `:25:` | Account identification | → `bank_accounts` |
| `:28C:` | Statement and sequence number | `statement_number` |
| `:60F:` / `:60M:` | Opening balance, final or intermediate | `opening_balance` |
| `:61:` | Statement line | `value_date`, `booking_date`, sign, `amount`, type, `bank_reference` |
| `:86:` | **Information to account owner** | Bank-specific; see below |
| `:62F:` / `:62M:` | Closing balance | `closing_balance` |
| `:64:` | Available balance | `available_balance` |

> **`:86:` is the problem with MT940.** It is semi-structured free text whose layout is defined by each bank — some use numbered sub-fields (`?20` … `?29`), some use positional conventions, some use neither. PeakFlow parses it with a **per-bank profile** and **retains the raw value verbatim** when parsing fails, so a human can still read it.
>
> Matching confidence from MT940 is **structurally lower** than from CAMT.053, and the UI says so rather than presenting the two as equivalent. Where a bank offers both, the import prefers CAMT.053.

## PAIN.001.001.09 — Credit Transfer Initiation

### Group header

| XPath | Source |
|-------|--------|
| `CstmrCdtTrfInitn/GrpHdr/MsgId` | Payment run ulid; unique per message, never reused |
| `GrpHdr/CreDtTm` | Generation timestamp |
| `GrpHdr/NbOfTxs`, `CtrlSum` | Computed and asserted against the run before the file is written |
| `GrpHdr/InitgPty/Nm` | Entity legal name |

### Payment information — one block per `(debtor account, execution date, scheme)`

| XPath | Source | Notes |
|-------|--------|-------|
| `PmtInf/PmtInfId` | Run line group id | |
| `PmtInf/PmtMtd` | `TRF` | |
| `PmtInf/BtchBookg` | Per bank profile | Affects how the statement line comes back, and therefore pass 2 |
| `PmtInf/PmtTpInf/SvcLvl/Cd` | `SEPA`, or the scheme | |
| `PmtInf/PmtTpInf/InstrPrty` | `NORM` / `HIGH` | |
| `PmtInf/ReqdExctnDt/Dt` | Requested execution date | Validated against scheme cut-off and the entity's business calendar |
| `PmtInf/Dbtr/Nm` | Entity legal name | |
| `PmtInf/DbtrAcct/Id/IBAN` | Paying `bank_accounts.iban` | |
| `PmtInf/DbtrAgt/FinInstnId/BICFI` | Paying BIC | |
| `PmtInf/ChrgBr` | `SLEV` for SEPA | |

### Credit transfer transaction — one per instruction

| XPath | Source | Validation before the file is written |
|-------|--------|---------------------------------------|
| `CdtTrfTxInf/PmtId/InstrId` | Instruction ulid | |
| `CdtTrfTxInf/PmtId/EndToEndId` | **Stable, unique, and what pass 2 matches on** | Max 35 chars, restricted character set |
| `CdtTrfTxInf/Amt/InstdAmt` + `@Ccy` | Instruction amount | Scheme ceiling; currency matches the scheme |
| `CdtTrfTxInf/CdtrAgt/FinInstnId/BICFI` | Beneficiary BIC | ISO 9362 structure |
| `CdtTrfTxInf/Cdtr/Nm` | Beneficiary legal name | **SEPA permits a restricted Latin set, so an Arabic name must be transliterated — and the user is shown exactly what will be sent** |
| `CdtTrfTxInf/CdtrAcct/Id/IBAN` | Beneficiary IBAN | MOD-97-10, country length, **and reachability** |
| `CdtTrfTxInf/RmtInf/Ustrd` | Remittance | **Max 140 characters**, truncation refused rather than silent |
| `CdtTrfTxInf/RmtInf/Strd/RfrdDocInf/Nb` | Bill numbers | Preferred over unstructured where the beneficiary's bank supports it |
| `CdtTrfTxInf/Purp/Cd` | Purpose code | Where a scheme or jurisdiction requires one |

**Everything above is validated before the file is produced**, not after the bank rejects it. A rejected file arrives hours later, by which point the payment run is released and the window is missed.

## PAIN.002.001.10 — Status Report

| XPath | Applied to |
|-------|------------|
| `CstmrPmtStsRpt/OrgnlGrpInfAndSts/OrgnlMsgId` | → the payment run |
| `OrgnlGrpInfAndSts/GrpSts` | Batch-level status |
| `OrgnlPmtInfAndSts/TxInfAndSts/OrgnlEndToEndId` | → **the individual instruction** |
| `TxInfAndSts/TxSts` | `ACCP`, `ACSP`, `ACSC`, `RJCT`, `PDNG` |
| `TxInfAndSts/StsRsnInf/Rsn/Cd` | ISO reason code, retained verbatim |
| `TxInfAndSts/StsRsnInf/AddtlInf` | The bank's own message, shown to the user |

**Status is applied per instruction, not per batch.** A partially rejected batch must leave the accepted instructions accepted — the common bug is reversing the whole run. A rejected instruction reverses its in-transit posting and returns the payable to open, with the reason code attached. An unmatched status report is **surfaced, not discarded**.

## PAIN.008.001.08 — Direct Debit Collection

| XPath | Source | Notes |
|-------|--------|-------|
| `PmtInf/PmtTpInf/LclInstrm/Cd` | `CORE` or `B2B` | Scheme per mandate |
| `PmtInf/PmtTpInf/SeqTp` | `FRST`, `RCUR`, `FNAL`, `OOFF` | **Wrong sequence type is a scheme-level rejection**, so it is derived from the mandate's collection history rather than set by hand |
| `DrctDbtTxInf/DrctDbtTx/MndtRltdInf/MndtId` | Mandate reference | |
| `DrctDbtTxInf/DrctDbtTx/MndtRltdInf/DtOfSgntr` | Signature date | |
| `PmtInf/ReqdColltnDt` | Collection date | Validated against the scheme's **pre-notification lead time** |

Mandate cancellation and expiry **stop collections rather than failing them at the bank**. Returns and refunds are ingested and posted with their reason code retained.
