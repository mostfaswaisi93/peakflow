# Phase 09 — Payments, allocation, FX and payment runs

**16 tasks · 81 points**

A **payment** is cash moving. An **allocation** is the act of saying which obligation it settles. They are separate objects in PeakFlow because they are separate events, often days apart and performed by different people - and forcing an allocation at receipt time is how payments get applied to the wrong invoice to make a screen close.

This phase also contains the single highest-consequence control in the product: the maker-checker boundary on releasing money.

---

### PF-181 · Statements and open items — mostly done
`Settlement` · **3 pts** · depends: PF-168 · standards: —

- [x] Customer and supplier statements in open-item and balance-forward form, in the recipient's language and the document currency
- [x] Includes unapplied cash, credit balances and disputed items, each labelled - a statement that silently omits them invites a dispute
- [x] Reconciles to the control account, and the report says so explicitly rather than leaving it implied
- [x] Deliverable by email on a schedule, with a delivery log
  - **Unblocked by PF-104.** `EmailChannel` is the SMTP implementation of `MessageChannel` this was waiting on, so a statement is delivered through the same path as an invoice - the same attempt numbering, the same duplicate reference, the same delivery log. Waiting was the right call: a half-channel built here would have been the second implementation the interface exists to prevent, and the two would have differed first on deduplication.
  - `statement_ready` is an approved template in both languages, so a customer receives the statement in theirs rather than in the issuer's.
  - The **schedule** is `config/schedule.php`'s business: this task's half is that the delivery is a real channel with a real log, which it now is. A recurring statement run is a job to register when somebody wants one, and registering it before anybody does would put a cron on the product that sends mail nobody asked for.

`App\Tenant\Settlement\Services\Statements` · `bin/verify settlement`

**Two forms, because they answer different questions.** Open-item lists
what is not settled — "which invoices do I still have to pay" — and is
the only form that reconciles line by line against the customer's own
ledger. Balance-forward opens with last period's figure and lists what
happened since, which is what somebody asks when the two sides disagree
about the *total* rather than about a document. A product with only the
first cannot explain a movement; with only the second it cannot be paid
from.

**Unapplied cash and disputes are labelled, never netted.** A statement
that nets a customer's unapplied payment against their oldest invoice
invites exactly the dispute it was meant to prevent: they telephone
about an invoice they believe they paid, and nobody can see which
payment the system chose. The total is identical either way — what
changes is that the customer can see what it is made of. A disputed
document stays on, labelled, because removing it makes the statement
disagree with the control account and tells the customer we forgot
their complaint.

**It says whether it reconciles, either way.** A report that printed
the figure only when the two agreed would be silent exactly when it
matters, and a statement that was right about one customer while the
control account disagreed sends somebody looking in the wrong place for
a week. When it breaks, it names the two shapes a break takes: a
document posted without an allocation, or an allocation without a
document.

Three fixture defects the checks found: allocations dated at `now()`
fall outside the only open period; a `UNION` of two five-parameter
halves needs ten bindings, because PostgreSQL binds positionally across
the whole statement; and the payables control carries a *credit*
balance, so comparing it raw reports every entity as out by twice the
balance — a break nobody can find because there is not one.

`balanceForward()`'s own "does this column add up" flag cannot fire
while its two queries agree, and a bite proved that. It is kept and
documented as what it is: a guard against a future edit to one of two
separately-written queries, with the check doing the addition itself so
the flag is not the only guard.
