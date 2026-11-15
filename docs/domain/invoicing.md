# Invoicing

The [README](../../README.md#invoicing) covers the shape. This document covers the mechanics: the state machine, the allocation algorithm, and the cases that break naive implementations.

## The state machine

```
                    ┌──────────► expired
                    │
  draft ──► sent ───┼──► accepted ──► (order or invoice)
 (quote)            └──► declined

  draft ──► confirmed ──► partially invoiced ──► fully invoiced
 (order)        │
                └──► cancelled   (refused if any line is invoiced)

  draft ──issue──► issued ──┬──► sent ──┬──► partially paid ──► paid
(invoice)   │               │           ├──► overdue ──► dunning ──► written off
            │               │           └──► disputed
            │               └──► credited (fully or partially)
            └─ discard (no number consumed, no posting)
```

Transitions that are **refused**, each with a specific message rather than a generic error:

| Attempt | Refusal |
|---------|---------|
| Edit an issued invoice | Not editable. Credit it and re-issue |
| Credit more than the uncredited value | Enforced in the database |
| Issue into a period that does not accept postings | Named period and its state |
| Issue without a tax registration where the treatment requires one | Names the missing registration |
| Issue in a currency the entity has not enabled | Names the currency |
| Cancel an order with invoiced lines | Names the lines |
| Credit an invoice you issued yourself | SoD; names who can |

## What is frozen at issue, and why each one

An issued invoice is a **historical statement**. It carries copies, not foreign keys, of everything that can change:

| Frozen | If it were a reference |
|--------|------------------------|
| Both parties' legal names and addresses | A customer who moves would retroactively alter a filed document |
| Both parties' tax registrations | A deregistration would invalidate the document's tax treatment |
| Every applied tax rate and category | A rate change would silently restate every historical invoice |
| The FX rate | A later rate correction would move the receivable's booked value |
| The entity's own legal identity | A group restructure would rewrite its own history |
| Payment terms and the derived due dates | Changing a customer's terms would re-age their old invoices |
| The line's description | Renaming an item would rewrite what was sold |
| The jurisdiction profile | A profile change would alter what a past document claims to conform to |

A test changes a party's name, a tax rate and a payment term after issue and asserts the issued invoice is unchanged in every respect.

## Gapless numbering — the algorithm

```php
// Inside the issuing transaction, as the LAST step before commit.
// App\Tenant\Ledger\Services\NumberAllocator::next()
$series = $connection->table('number_series')
    ->where('entity_id', $entityId)
    ->where('scope', $scope)        // 'sales_document'
    ->where('key', $key)            // 'INV', 'CRN', ...
    ->where('year', $date->year)    // only where the series resets annually
    ->lockForUpdate()               // SELECT ... FOR UPDATE
    ->first();

$number = (int) $series->next_number;

// The counter and the issuance record move in ONE statement under the lock:
// if the count could be written separately it would eventually be written
// separately, and the gap check compares the two against each other.
$connection->table('number_series')->where('id', $series->id)->update([
    'next_number' => $number + 1,
    'last_issued_number' => $number,
    'issued_count' => DB::raw('issued_count + 1'),
    'last_issued_at' => now(),
]);
```

Three rules, each with a comment at the call site:

1. **`nextval()` cannot be used.** A PostgreSQL sequence is deliberately non-transactional: it does not lock, and a rollback does not return the number. That is right for a surrogate key and wrong for a legal sequence — it would leave a gap on every validation failure, deadlock, crash and retry.
2. **Allocate last.** Validation, tax computation and the posting rule all succeed first, so the series lock is held for one `UPDATE` rather than for the transaction's duration.
3. **No I/O under the lock.** No PDF render, no clearance call, no mail. A tax authority that takes thirty seconds to answer must not hold the invoice sequence for thirty seconds.

Rule 2 is asserted by **reading the issuer's source**, with its comments stripped so the file's own reasoning cannot satisfy its own check. That is deliberate: moving the allocation to the top of the transaction does not produce a single gap — the transaction still returns the number on failure — it serialises every invoice in the entity behind the slowest document anybody is issuing. The damage is invisible to any functional test and to every user until the day it is not.

Rule 3 is asserted twice: there is no `dispatch()`, `Http::`, `Mail::` or render in the issuer at all, and `Issuer::afterCommit()` returns the three post-commit jobs for the caller to queue. A job dispatched inside a transaction that then rolls back is a job about a document that never existed.

**`PF-094` is the proof**: 200 invoices from 20 parallel workers against one series, asserting the numbers are exactly `1..200`. Variants assert that a third failing after the lock point leaves the rest gapless, that two series do not block each other, and that a crashed worker consumes no number.

Formats are templated — `INV-{entity}-{YYYY}-{seq:6}` — and the format is **frozen once the series has issued its first document**, because changing the shape of a sequence mid-year breaks its own gaplessness.

## The hash chain

```
document.canonical_hash = SHA-256( canonicalize( legally_significant_fields ) )
document.previous_hash  = previous document in the same series .canonical_hash
document.chain_index    = previous .chain_index + 1
```

Canonicalization is RFC 8785 (JCS) for the JSON form and XML C14N 1.1 for the XML forms, so the hash is stable across PHP versions, key insertion order and locale — asserted by test.

**The canonical bytes are stored, not only hashed.** A verifier holding a hash alone can say "this is not what it was", which is true and useless. Holding the bytes, `invoices:verify-chain` names the first field that differs, what it was and what it is now.

**The hash is taken over the stored row, not over the model that wrote it.** Measured while building this: hashing the in-memory attributes froze `totals.prepaid` as absent, while the row holds the column default `0.0000` — and three genuine documents, issued through the real path and untouched afterwards, were reported as diverging at `totals.prepaid`. A verifier that cries tampering over a column default is a verifier nobody runs twice. The issuer re-reads the row before hashing, and a check issues from a deliberately unread draft to keep it that way.

**What is checked, in order**, because the order decides what the report is worth:

1. **The chain index**, so a gap reads as "index 3 is missing" — the finding an auditor acts on — rather than as a content mismatch on the document after it.
2. **The previous hash**, so a broken link is named at the document that broke it.
3. **The stored bytes against the stored hash**, because the frozen bytes having been edited is a different and louder fact than the document having changed.
4. **Field by field**, last, against a recomputation from the row as it stands now.

**What the chain proves:** insertion, deletion and alteration are detectable. Changing any document invalidates every hash after it; removing one breaks the link.

**What it does not prove:** authorship. It is internal consistency, not a signature. Where a jurisdiction requires a cryptographic seal — ZATCA's stamp, an eIDAS qualified seal — that is a separate step performed in the `Exchange` plane with a key in a KMS or HSM.

`invoices:verify-chain` re-walks a series and reports the **first** divergence with the document and the differing field, and exits non-zero. Scheduling it nightly belongs with the rest of the scheduled work in Phase 13.

## What a draft refuses before any of this

Validation is the step before the number, and it runs **inside** the issuing transaction as well as continuously while editing — because the period can close and a registration can lapse between a check and a write, and the check that passed is the one somebody would quote afterwards.

Findings are addressed to a field (`lines.3.tax_code_id`), not phrased as a sentence about the document ("a line has no tax code"). On a fifty-line invoice that difference is twenty minutes.

Blocking and advisory are separate lists. `blocking()` is what the issuer consults; an advisory finding is reported and stops nothing:

| Finding | Why it blocks, or does not |
|---|---|
| `no_lines`, `net_does_not_match_the_lines`, `breakdown_does_not_sum_to_the_tax` | Blocks. EN 16931 BR-CO-14, and a document whose totals do not tie is a document in dispute |
| `line_without_a_tax_code`, `line_without_a_tax_point` | Blocks. A return is assembled from codes and tax points; a line without them is computed and then dropped |
| `place_of_supply_unresolved` | Blocks. A default would issue a document in a jurisdiction nobody chose |
| `exemption_reason_missing` | Blocks. BT-121. An invoice that says 0% with no reason cannot be defended |
| `party_is_not_a_customer`, `party_was_merged`, `no_customer` | Blocks. A document addressed to a party nobody sells to, or to one that no longer exists |
| `period_is_not_open`, `return_period_will_not_take_it` | Blocks. A document in a filed period is a correction, which is `PF-087`'s procedure, not an invoice |
| `currency_is_not_enabled`, `foreign_currency_without_a_rate` | Blocks. A foreign-currency document with no rate has no functional-currency value to post |
| `reverse_charge_without_a_registration` | Blocks. Reverse charge shifts the liability to a counterparty who must be registered to receive it |
| `evidence_required` | **Advisory.** A zero-rated export whose shipping paperwork has not arrived yet is still an invoice the business has to send. The gate is `PF-080`, at return time |

## Recurring billing

A schedule is a template plus an RFC 5545 `RRULE`, which is why "the last working day of every quarter" is expressible rather than approximated:

```
FREQ=MONTHLY;BYMONTHDAY=-1;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;INTERVAL=3
```

| Concern | Handling |
|---------|----------|
| Double firing | Idempotent on `(schedule_id, occurrence_date)`, plus a Redis lock per `(task, tenant, occurrence)` so a two-node scheduler cannot double-bill |
| Timezone | Runs in the **tenant's own** timezone. A tenant in Riyadh and one in Casablanca do not share "01:00" |
| Mid-period start or stop | Proration rules, with the basis shown on the generated line |
| Price changes | Picked up by effective date; the schedule records which price list it resolves against |
| A failed occurrence | Retried without skipping the next; a permanently failed one is surfaced rather than retried forever |
| Confidence before activation | A dry-run preview of the next twelve occurrences with amounts and dates |

## Revenue recognition (IFRS 15)

An invoice is a **billing event**; revenue is recognized when the performance obligation is satisfied, and the two are frequently different months.

```
issue    → Dr accounts_receivable / Cr deferred_revenue + Cr vat_output
monthly  → Dr deferred_revenue    / Cr revenue
```

Schedules are per line: immediate, straight-line over a service period, on milestones, or on delivery. `revenue:recognize {period}` posts one entry per period and is re-runnable without double-posting.

**The case that breaks naive implementations:** a credit note against a partially recognized invoice. Three months of a twelve-month schedule have been recognized; the invoice is credited in full. The remaining nine months of deferral must be released against the credit, and the three recognized months must be reversed from revenue — not the whole twelve from deferral, which would leave revenue overstated and deferred revenue negative. It has its own test, and a deferred-revenue roll-forward report (opening, additions, recognized, closing) reconciles to the control account.

## Dunning

A policy is an ordered set of steps: offset in days past due, channel, template, and optional actions (late fee, credit hold, escalate to a collections owner).

Two design decisions that separate a usable dunning engine from an annoying one:

- **Evaluated per invoice, sent per customer.** A customer with six overdue invoices receives one statement, not six emails.
- **A dispute flag stops escalation.** An invoice the customer has formally queried moves to a dispute report rather than continuing to escalate, because dunning a disputed invoice damages the relationship and does not collect the money.

`dunning:run` is idempotent per `(invoice, step, day)` and respects the tenant's timezone and business calendar. A late fee is posted as **its own document**, so it is creditable and disputable like anything else.

## PDF and print

| Requirement | Why |
|-------------|-----|
| PDF/A-3 via Gotenberg then Ghostscript, **verified by veraPDF** | A PDF that merely claims PDF/A conformance is not an archive copy, and a non-conformant output fails the job rather than being delivered |
| Fonts fully embedded, Arabic included, with correct shaping and text extraction | A PDF whose Arabic cannot be selected or searched is a scan with extra steps |
| Bidi isolation on identifiers | `JO94-CBJO-0010` inside Arabic text reorders unless isolated, and the user verifying it will not notice |
| Deterministic output | The same invoice renders byte-identically, so the archive hash is stable |
| Page breaks that never split a row or orphan a total; repeated headers; `page x of y` | It is the artefact attached to an email and archived |
| Tested by rendering and diffing the PDF | Eyeballing a browser preview does not catch a regression |
