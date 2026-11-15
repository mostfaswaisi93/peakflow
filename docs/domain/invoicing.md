# Invoicing

The [README](../../README.md#invoicing) covers the shape. This document covers the mechanics: the state machine, the allocation algorithm, and the cases that break naive implementations.

## Before the invoice: quotes and orders

A quote is an **offer**, and an order is an **agreement to supply**. Neither is an accounting document: no posting, no number from a legal series, no tax point, no place in a hash chain. Both have their own tables for that reason — the easy way to build them is a status on the invoice table, and then a document with none of an invoice's obligations sits in the table every report, every return and every chain walk reads.

```
quote ──accept──▶ order ──invoice──▶ invoice(s)
      └──accept──────────────────────▶ invoice (draft), where sales.order_step is off
```

**A revision is a new version.** A sent quote is a thing somebody received, so revising it writes version 2 and marks version 1 superseded — kept and readable, because "the price I was sent" is a question about a document that existed, and no longer acceptable, because two live prices means the customer picks. The versions carry the same reference (it is the same conversation) and `Quotes::compare()` is what makes them comparable: lines matched by content rather than position, so moving a line is not a change to the offer.

**Expiry is read from the date, not the status.** A quote whose last day was yesterday is not acceptable this morning, whatever the column says; `quotes:expire` exists to make the state visible, and it reports what it closed rather than a count.

**The customer answers by signed link, with no login** — the tenant and entity are in the path because there is nowhere else for them to come from, and both are inside the signature. The link expires a week after the quote. Accepting records who, when and from where, and produces an order or a **draft** invoice: issuing is the business's act, and collapsing the two would consume a number from a gapless series and write a posting at the moment somebody clicked a link on a phone.

**An order's balance is tracked per line**, in quantity and in value, because "have we invoiced the installation yet?" is the question people ask. Over-invoicing is refused by the service with the figure and by a CHECK on the row, so the cap holds against any writer. Crediting an invoice puts the balance back — without that, crediting and re-issuing would exhaust an order nobody had delivered. Cancelling an order with invoices against it is refused: the invoice exists, and an order that says it never happened leaves a document in a legal sequence pointing at an agreement nobody made.

A confirmed order **commits** and posts nothing. A commitment is neither a receivable nor a liability, so it is a figure the credit check and a budget report read — and a check asserts no journal entry ever names an order.

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

**`PF-094` is the proof**, and it runs: `bin/verify concurrency` issues 200 invoices from 20 real OS processes against one series and asserts the numbers are exactly `1..200`, with no duplicates and a chain that still walks. Variants assert that four transactions failing *after* the lock point leave the rest gapless, that a worker killed with `SIGKILL` mid-transaction consumes nothing, and that holding one series' lock does not stop another series issuing.

It found a bug on its first run. First use of a series was a plain `INSERT`, so two workers creating the same series at the same instant collided on `number_series_one_per_scope` and the loser's whole document failed — for a reason with nothing to do with the document. It is `INSERT ... ON CONFLICT DO NOTHING` followed by a re-read under the lock now, and the collision is **forced** rather than raced: the suite holds the row in an open transaction, polls `pg_locks` until a backend is genuinely waiting, and only then commits.

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

## Credit notes

A credit note is a document, not an undo. The invoice was correct when it was issued, it is in a return that may already be filed, and the goods really did come back — so both documents stay in the record and the receivable ends up where it should be by addition.

Three ways to raise one, all of which go through the same issuer as any other document:

| | What is given | What is derived |
|---|---|---|
| **Full** | the invoice | every line, in full |
| **By line** | the net to credit per line | the tax, at each line's own rate and the jurisdiction's rounding; the quantity, in proportion |
| **By amount** | the **gross** to credit | each line's share, then its net backed out at its own rate; each group's tax as the remainder |

The third runs the arithmetic backwards on purpose. A settlement is agreed as "1,000 off invoice 41", tax included: computing the net from a rate and then the tax from the net gave 999.9952 and left 150.0048 creditable on a 1,150.00 invoice — a residue nobody can explain and nobody can clear.

**The posting is its own rule**, not the invoice rule with negative figures and not `Reverser` over the invoice's entry:

```
Dr  the line's revenue account     each credited line's net
Dr  vat_output @ the jurisdiction  each rate group's tax
Cr  accounts_receivable            the gross no longer owed
```

The tax leg goes back to **output** tax. Posting it as input leaves both figures overstated by the same amount: the net due is right and both boxes of the return are wrong, which is the failure that reconciles perfectly.

**The cap** — a credit note may not take an invoice's credited total past its gross — is enforced in the database, through a running total maintained by `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` on `invoice_credit_totals`. A locking read inside the trigger was tried first; three simultaneous full credit notes once left 345.00 credited against a 115.00 invoice. The total is kept because its correctness needs no reasoning about snapshots inside volatile functions, and no accidental serialisation by the numbering lock. The documents remain the record; a check asserts the counter agrees with them.

**Segregation of duties**: whoever issued an invoice may not credit it (`PF-033`), asserted inside the issuing transaction before anything is consumed. Building this found that the engine could not see any real act at all — it matched permission keys (`invoice.issue`) against an audit trail that records past-tense actions (`invoice.issued`). `config/sod.php` maps the two, and `bin/verify sod` fails the build if a duty maps to an action nothing writes.

## Debit notes and corrected invoices

| | UNCL 1001 | Reference | Posting |
|---|---|---|---|
| **Debit note** | `383` | `charges` | the invoice's own shape: receivable up, revenue up, output tax up |
| **Corrected invoice** | `384` | `corrects` | the replaced document's figures reversed **and** the corrected ones, in one entry |

A debit note is not a credit note with the sign flipped. It charges more for a supply already invoiced — a price that was understated, freight that was left off — and there is deliberately **no cap** on it: a customer can be charged more than once against one invoice for different things, and the only ceiling is what was agreed, which is not a number this system holds.

A corrected invoice replaces. Its lines are the corrected figures in full, because a replacement is not a difference, and it posts **both halves in one entry** — the reversal and the restatement — because it is one event. Two entries reach the same balances and read as two decisions taken at two moments. One entry is also self-checking: it balances only if each half does, so a correction that reverses 1,150 and restates 1,000 cannot be written at all.

The original's journal entry is left alone. It was posted correctly from the document that existed at the time; what changed is the document, not the books.

**One replacement per invoice**, enforced by a primary key on the corrected document — two transactions inserting one key cannot both succeed, and nothing about snapshots has to be true for that to hold. A replaced invoice is then closed to further adjustment: crediting it as well would count the correction twice.

**Which mechanism is legal is the jurisdiction's decision**, from `config/jurisdictions.php`:

| Profile | Corrects with |
|---|---|
| `facturx` | a replacement corrected invoice |
| `eta`, `jofotara` | a credit or debit note |
| `generic`, `peppol`, `zatca` | either |

`Corrections::permitted()` is what the sales screens read, so a type the profile forbids is never offered; `assertPermitted()` refuses it again at the moment of the write, because anything decided while rendering a page is already stale. A profile with no declared mechanism is refused rather than guessed.

## Prepayments

Money taken before performance is a **contract liability**, not revenue — IFRS 15 — and in most VAT regimes it is also a tax point, because the tax falls due on the earlier of payment and invoice. So a prepayment invoice (`386`) is a tax document that posts nothing to revenue:

```
Dr  accounts_receivable            the gross the customer owes now
Cr  deferred_revenue               the net taken before performance
Cr  vat_output @ the jurisdiction  the tax, where the regime taxes it
```

Crediting revenue instead cannot be fixed by the final invoice posting less: the overstatement is in a period that may be closed, reported and filed, and the correction is in a later one. Two wrong periods netting to right is the shape nobody notices until an auditor asks what the revenue in Q1 was for.

The final invoice then posts a **different entry** — chosen from the document's references, not its type, because a final invoice is an ordinary `380`:

```
Dr  accounts_receivable            the gross LESS what was prepaid
Dr  deferred_revenue               the prepayment's net, released
Cr  the line's revenue account     the whole supply's net
Cr  vat_output @ the jurisdiction  the tax not already charged
```

Worked through, on a 1,000 + 150 supply with a 400 + 60 prepayment:

| | Receivable | Deferred revenue | Output tax | Revenue |
|---|---|---|---|---|
| Prepayment invoice | +460 | −400 | −60 | — |
| Final invoice | +690 | +400 | −90 | −1,000 |
| **Balance** | **1,150** | **0** | **−150** | **−1,000** |

Which is the only claim worth making, and the one the check asserts: every individual posting can look defensible while the pair is wrong — revenue recognised twice, tax declared twice, the customer chased for one supply twice.

Three details that are easy to get wrong and are each checked:

- the tax deducted comes out of **the group it was charged in**, and a prepayment taxed in a jurisdiction the invoice does not touch is refused rather than netted against a tax the authority never saw;
- the liability is released with **the prepayment's** tax point, because the return it was reported in used that date;
- `prepaid_amount` (EN 16931 `BT-113`) must equal the prepayment it cites — a document deducting 300 while citing 460 leaves 160 in a liability nobody will ever clear, and both documents look correct on their own.

A prepayment is applied to **exactly one** invoice, by primary key. Splitting one across several is deliberately not modelled: it needs a balance per prepayment, ordering rules for when two invoices compete for the remainder, and a report of what is left — an allocation ledger rather than a column. Returning part of one is a credit note.

## Self-billed invoices

The buyer writes the supplier's invoice on their behalf, under an agreement both parties signed. Every regime that permits it requires that agreement, and most require it to be produced on request — so it is a row with a reference, a signing date and a half-open validity range, not a checkbox on the party. The question an auditor asks is which agreement was in force on the date of the document.

Two agreements with one party cannot overlap (an exclusion constraint), because two in force at once is two answers to "which agreement is this document under" and the document carries one reference. The trigger refuses a `389` whose supplier has no agreement covering its tax point, so a second issuing path cannot produce a document the regime does not recognise.

**It posts as a purchase**, which is the part that is easy to get backwards:

```
Dr  the line's expense account    each line's net
Dr  vat_input @ the jurisdiction  each rate group's tax
Cr  accounts_payable              the gross owed to the supplier
```

Posted as a sale it looks right in every individual figure: the supplier appears as a customer who owes money, the tax lands in output so the return declares tax on a purchase and claims nothing back, and revenue rises by the cost of the goods. The entry balances and the trial balance adds up — what is wrong is the direction of the whole document. So the check reads the **side** of each account rather than the amounts, and the validator refuses a line whose account type does not match the document's side (`invoice_lines.revenue_account_id` holds an expense account here; the name is historical and the ambiguity is real).

## Simplified and B2C documents

A simplified invoice is a real document with a reduced mandatory field set, not a prettier receipt. Its existence, its limits and what it omits are the regime's:

| Profile | Simplified form | Threshold | Omits |
|---|---|---|---|
| `peppol`, `facturx` | yes | EUR 100 | customer registration, address |
| `zatca` | yes | none — the customer decides, not the amount | customer registration, address |
| `generic`, `jofotara` | yes | none | customer registration |
| `eta` | **no** — consumer sales go through the e-receipt system | — | — |

Exceeding the threshold **upgrades the document type**. It does not make a simplified invoice slightly wrong: the regimes that set a limit set it as a condition of using the form at all, so the document has to be issued in full — with the customer's registration and address, which is what somebody chose the simplified form to avoid typing. That is refused when the form is chosen *and* re-checked at issue, because a draft marked simplified at 80 and edited to 120 is the ordinary way a document outgrows its form.

A threshold in another currency is not converted: doing so would be this system deciding what the regime's limit means on a rate of its choosing. The standard form is always acceptable; the simplified one is the concession.

Which form a document was issued in is **under the hash**, because a simplified invoice and a full one carry different obligations.

**B2C** is not a document type — it is a customer with no tax registration. The document omits the registration because there is none, and nothing about that may block the sale. What does change is the place of supply: a digital service to a consumer abroad is taxed where the consumer is, and the same service to a business abroad is reverse-charged. Both come from `PF-078`'s rule book.

## Recurring billing

A schedule is a template plus an **`RRULE`**, because "the last working day of every quarter" has to be expressible rather than approximated:

```
FREQ=MONTHLY;INTERVAL=3;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1
```

The Gulf working week is the same rule with `BYDAY=SU,MO,TU,WE,TH`, which is what makes it a rule rather than a hard-coded notion of a working day.

What is implemented is a **deliberate subset** of RFC 5545 — `FREQ`, `INTERVAL`, `COUNT`, `UNTIL`, `BYMONTH`, `BYMONTHDAY`, `BYDAY` with ordinals, `BYSETPOS` of ±1 — and everything else is refused **by name**, so the reader can tell whether to rewrite the rule or ask for the part to be built. One divergence is written down: `BYMONTHDAY=31` **clamps** to the month's length instead of skipping February, because a subscription billed on the 31st is billed every month and the customer who signed up on the 31st of January does not get February free.

**Nothing is activated without a preview.** `preview()` returns the next twelve occurrences with their dates and amounts, computed by the same code the generator uses — a preview computed differently is worse than none, because it is believed. A check generates the first previewed occurrence and compares the figures.

**Proration shows its basis on the line**: "Support and maintenance (prorated 12/31 days, 2026-03-20 to 2026-03-31)". Whether a customer who joins on the 20th pays 12/31 of the month or all of it is a commercial decision businesses make differently, and the system must not pick. A full period carries no label, because noise on every line is noise nobody reads.

### Generating exactly once

| Guard | What it is for |
|---|---|
| `recurring_occurrences (schedule_id, occurrence_date)` unique | **the guarantee** — the second writer loses, whoever it is |
| a lock per `(task, tenant, schedule, occurrence)` | an **optimisation** — two workers do not do the work twice |

The row is claimed **before** the invoice is drafted, so a generator interrupted between the two leaves a claim with no invoice — which the next run retries — rather than an invoice nobody has a record of asking for, which the next run would produce again.

The lock is not the guarantee on purpose: it is a service that can be down, misconfigured or shared with a second deployment, and "nobody double-bills as long as Redis is healthy" is not a property worth claiming. Measured: with the index gone and the lock in place, three simultaneous workers still produce one invoice; with both gone they produce two. The concurrency suite proves the pair, and the single-process suite — which calls the generator twice where no lock is contended — is what catches a missing index.

Each occurrence is its own claim and its own transaction, so a customer whose price list has a gap does not stop the other two hundred invoices. After three attempts the occurrence is surfaced in the audit trail rather than retried forever, and the command exits non-zero when any occurrence failed.

**The timezone is the entity's**, copied onto the schedule: a group with a company in Riyadh and one in Casablanca is one tenant with two entities, and "daily at 01:00" means a different moment for each. A generator on the server clock bills one of them on the wrong date for the week each March and October when only one has changed — the hardest kind of wrong to notice, because every other week it is right.



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

Per **line**, because one invoice is routinely two performance obligations — the licence earned on delivery and the support earned over twelve months — and a document-level setting forces the business to split the invoice for an accounting reason.

| Basis | When it is earned |
|---|---|
| `immediate` (the absent value) | at issue: goods delivered, work done, a thing sold |
| `straight_line` | over `service_from` … `service_until` |
| `milestone` | when somebody confirms a milestone |
| `on_delivery` | when somebody confirms delivery |

A deferred line credits `deferred_revenue` at issue instead of revenue, and the **plan is written then** — rows, not a formula. A formula re-evaluated monthly disagrees with itself the first time anything moves, and *"show me how you arrived at this month's revenue"* is a question about a list.

The plan is weighted by the days each month carries: a contract from the 20th of May to the 19th of August has eleven days in its first month and nineteen in its last, and twelve equal twelfths would recognise a month of revenue in eleven days. Each row is dated at the **end** of its service month, because recognising on the 1st recognises work not yet done.

```
revenue:recognize 2026-07-31 --all
```

posts **one entry per period**, not one per run: revenue belongs to the month it was earned in, and a run at the end of June that found May unrecognised would otherwise overstate June by May's revenue and understate May's — in accounts that may already have been reported. The entry carries no tax code, because releasing a deferral is not a supply and the tax was declared at issue.

### The case that breaks naive implementations

A credit note against a partially recognised invoice. Three months of a twelve-month contract are revenue and nine are still a liability:

| | Revenue | Deferred revenue |
|---|---|---|
| Invoice 1,200 | — | 1,200 |
| Three months recognised | 300 | 900 |
| **Credit note, naive (all from revenue)** | **−900** ✗ | 900 ✗ |
| **Credit note, naive (all from the deferral)** | 300 ✗ | **−300** ✗ |
| **Credit note, split** | 0 ✓ | 0 ✓ |

Both naive versions look right on the face of the credit note and leave the control account unreconcilable in opposite directions. The split is computed from the plan, the posting rule writes both halves, and the remaining plan rows are **cancelled** — otherwise a later run releases revenue for a credited document, the figures stay plausible, and the deferral goes negative in eight months.

The **roll-forward** — opening, additions, recognised, cancelled, closing — is classified by when the revenue is earned rather than when the job ran, and the closing figure is the roll while the control account is read from the journal lines independently. A check breaks the schedule and requires the difference to appear: deriving one side from the other is the shape of a reconciliation that is always clean.



An invoice is a **billing event**; revenue is recognized when the performance obligation is satisfied, and the two are frequently different months.

```
issue    → Dr accounts_receivable / Cr deferred_revenue + Cr vat_output
monthly  → Dr deferred_revenue    / Cr revenue
```

Schedules are per line: immediate, straight-line over a service period, on milestones, or on delivery. `revenue:recognize {period}` posts one entry per period and is re-runnable without double-posting.

**The case that breaks naive implementations:** a credit note against a partially recognized invoice. Three months of a twelve-month schedule have been recognized; the invoice is credited in full. The remaining nine months of deferral must be released against the credit, and the three recognized months must be reversed from revenue — not the whole twelve from deferral, which would leave revenue overstated and deferred revenue negative. It has its own test, and a deferred-revenue roll-forward report (opening, additions, recognized, closing) reconciles to the control account.

## Dunning

A policy is an ordered set of steps: offset in days past due, channel, template, and optional actions (late fee, credit hold, escalate to collections). Policies resolve **party → party group → entity default**, and a customer with none under an entity with no default is not chased at all — a system that invented a policy would start emailing customers nobody set up.

### Two decisions that separate a usable engine from an annoying one

- **Evaluated per invoice, sent per customer.** The step that applies is a property of an invoice: how far past due it is, under that customer's policy. What gets sent is a property of the customer: one statement listing every invoice that reached a step today. The person who receives six emails from a supplier in one morning telephones about the emails rather than about the money.
- **A dispute stops the escalation and nothing else.** The invoice is still owed, still counts towards exposure and still ages; it moves to a dispute report instead of escalating. Writing it off or netting it out of the exposure is the easy implementation and lets a customer keep buying by disputing everything — which is the standard response to a credit block.

### The highest step reached, once

The step that fires is the highest one whose offset has been reached and which has not already run for that invoice — not the next one in sequence. A run that has been down for a week sends the step the invoice has actually reached rather than walking the customer through four reminders in four minutes.

### Idempotence

| Layer | What it is |
|-------|------------|
| `dunning_events` unique on `(invoice_id, dunning_policy_step_id, occurred_on)` | The guarantee |
| `dunning_notices` unique on `(entity_id, party_id, notice_date)` | One statement per customer per day, whatever the caller does |
| The step's own "has this already run" query | The optimisation that keeps the common case off a caught exception |

Cron fires daily, somebody runs it by hand while looking into a complaint, and a retry lands after a timeout. All three arrive at the same index.

A run that sends nothing says **why** for each invoice it passed over — not due yet, already sent, disputed, no policy, not a business day. A quiet morning and a broken job look identical otherwise, and the person who has to answer "did the chasing go out?" should not have to read the code.

### The business calendar

Nothing is sent on a day the business is shut, per the entity's weekend and holidays. A reminder sent on a Friday in the Gulf lands at the bottom of an inbox nobody reads until Sunday, and the step has been spent. The step fires on the next business day, and the event records the day it *actually* happened rather than the offset it was nominally due on — because "when did you send it" is asked with a letter in hand.

### Late fees

A fee is a **debit note** against the overdue invoice, which makes it creditable and disputable like any other charge. A fee bolted onto the original would change a document that has been issued; a fee held in a column is a charge the customer cannot query. The document carries its own arithmetic — the percentage, the outstanding amount it was taken on, the invoice it relates to, and the minimum where that is what applied — because a fee with no basis on it is a number the customer telephones about, and whoever answers has to reconstruct it from a policy they have not read.

Never twice for the same step against the same invoice, whatever else changed.

### Credit hold: placed by policy, lifted by policy

| | |
|---|---|
| **Placed** | By a step with the `credit_hold` action, recording the exposure and limit *as they were* — "why was this customer put on hold in March" is answered with the March figures |
| **Released** | Automatically, as soon as nothing is overdue. A hold that has to be lifted by hand is a customer who paid on Thursday and cannot order on Monday while the person who could lift it is on holiday |
| **And only its own** | The hold event records its `source`, and `manual` is the default. A hold a person placed — a credit decision, a legal matter, a customer in administration — is never lifted by a nightly job because an invoice got paid |

Telling them apart by matching the opening words of a free-text reason was the alternative, and it lifts the credit manager's hold the first time somebody rewords a template.

### The worklist

Ordered by **value at risk**, descending — not by age. The oldest debt is often the smallest, and a worklist ordered by age spends the morning on fifty riyals. A broken promise sorts first within the value order, because somebody said they would pay and did not, which is the one signal worth interrupting the order for.

A promise to pay does **not** stop the chasing. That is the easy implementation and the one that lets a debt go quiet: the promise is broken, nothing happens on the 30th, and the next reminder goes out whenever somebody notices. A promise dated in the past, or for nothing, is refused rather than stored as a plan.

## PDF and print

| Requirement | Why |
|-------------|-----|
| PDF/A-3 via Gotenberg then Ghostscript, **verified by veraPDF** | A PDF that merely claims PDF/A conformance is not an archive copy, and a non-conformant output fails the job rather than being delivered |
| Fonts fully embedded, Arabic included, with correct shaping and text extraction | A PDF whose Arabic cannot be selected or searched is a scan with extra steps |
| Bidi isolation on identifiers | `JO94-CBJO-0010` inside Arabic text reorders unless isolated, and the user verifying it will not notice |
| Deterministic output | The same invoice renders byte-identically, so the archive hash is stable |
| Page breaks that never split a row or orphan a total; repeated headers; `page x of y` | It is the artefact attached to an email and archived |
| Tested by rendering and diffing the PDF | Eyeballing a browser preview does not catch a regression |
