# Events, listeners and the queue

Every posting in PeakFlow is caused by something: an invoice was issued, a payment was matched, a period was closed. The event is how the thing that happened reaches the ledger, and this document is about one split that has to be got right and one bug that has to be impossible.

The split: **a posting is synchronous, a side effect is queued.** Both halves, enforced.

The bug: **a queued job running against the wrong tenant's database.** It returns plausible rows from another customer's books and raises nothing.

---

## 1. The split

### A posting that is eventually consistent is not a ledger

An invoice that exists for four seconds with no journal entry is an invoice whose VAT is not in the return, whose receivable is not in the aging, and whose customer is not over their credit limit. Four seconds is long enough for a report to be run. A queue that is down is long enough for a day.

So a posting listener is synchronous and inside the transaction that created the document. If the posting fails, the document fails with it — which is the correct outcome. An invoice nobody can account for is worse than an invoice that was never issued, because the second one can be retried.

### And a side effect must never be able to roll a posting back

Emailing the invoice, pushing a webhook, indexing it for search, rendering a PDF, recomputing a verification: none of those are things the books depend on. A mail server being down has no business reversing a journal entry.

The test, and it is a short one:

> If this failed and nobody noticed for an hour, would the books be wrong?

If no, it is a side effect, it is queued, and its failure stays its own.

### The markers, and why they are not a convention

Two interfaces, both in `App\Support\Events`:

| Marker | On | Means |
|--------|-----|-------|
| `FinancialEvent` | the **event** | the ledger depends on this being posted now |
| `SideEffect` | the **listener** | running this late is required, not merely allowed |

`FinancialEvent` is on the event because the listener does not get a vote: the event says whether the ledger depends on it.

`SideEffect` is on the listener and is deliberately redundant with `ShouldQueue`. `ShouldQueue` means "run this later", which is most of what is needed. What it does not say is that running it later is **required** — and that distinction is the whole point the first time somebody debugging a delivery problem makes a listener synchronous "just to see the error", and commits it.

`SideEffect::whyDeferrable()` is read by nothing at runtime. It exists because a developer adding a listener has to answer the question in a sentence, and "the books are not wrong if this is an hour late" is a sentence somebody either can or cannot write.

### Both directions are build failures

`bin/verify arch` reads every listener and fails on either mistake:

```
app/.../SomeListener.php handles a financial event and implements ShouldQueue.
A posting that is eventually consistent with the document that caused it is not
a ledger - and the window is long enough for a report to be run in.

app/.../SomeListener.php is marked SideEffect and is not queued, so a failure in
it can fail or roll back the posting. A mail server being down has no business
reversing a journal entry.
```

Each message was produced by removing the corresponding marker from the real listener and running the check, not written and hoped for.

### An event carries ids, never models

A third rule, same suite. A `Period` on an event would make every listener — in `Reporting`, in `Exchange`, in a tenant's webhook payload — depend on `Ledger`'s models, which the module graph forbids most of them from doing and which couples the rest to a schema they have no business knowing.

There is a second reason that bites only on a queue. A model on an event serialises as a class and an id, and deserialises by re-reading the row — in a worker that may be running the next release, against a row that may have changed since. The listener then sees a different state from the one that caused the event, and no part of that is visible in the code.

So events carry primitives and `Support` value objects. `entityId`, `periodId`, `periodLabel`, a `Money`, a date string.

---

## 2. The tenant has to travel with the job

### The failure

PeakFlow is database-per-tenant. There is **one** `tenant` connection in `config/database.php` and its settings are rewritten per request.

A queue worker is a long-lived process handling jobs for many tenants in succession, and Laravel caches the opened PDO handle per connection **name** — which is `tenant` for all of them. A job that does not re-point the connection reads whichever tenant's database the previous job left open.

This is the worst bug this architecture can have. It is invisible in every test that runs one job at a time, it returns data that looks completely normal, and the audit trail it writes lands in the wrong customer's books.

"Each job remembers to set its tenant" is not a defence. The one that forgets is the one that leaks.

### What is actually done

`QueueTenancyServiceProvider` does three things, for every queued thing — jobs, queued listeners, mail, notifications, chains:

1. **Stamps** the current tenant onto the payload at dispatch, via `Queue::createPayloadUsing`. At dispatch time the tenant is established, which is what makes this reliable where asking the job is not.
2. **Establishes** the payload's tenant before the job runs, after forgetting whatever was there. A tenant that has been suspended or is migrating is refused here, so the job fails visibly instead of running against a database mid-migration.
3. **Restores** the caller's tenant afterwards — on success, on failure and on an exception alike.

A job dispatched outside any tenant is stamped an explicit `null`. The distinction between a null stamp and a missing key matters at the other end: a stamp that is sometimes absent means the hook cannot tell a platform job from a stamping failure.

### A job with no tenant fails, and that is the requirement

Not "falls back", not "skips". The connection is forgotten first, so a tenantless job that needs tenant data gets an error naming a connection with no database. Failing loudly is the only acceptable of the two outcomes.

That claim was measured. With the forgetting removed from both hooks, a job carrying no tenant at all ran happily against the previous job's database and wrote a drift finding into it:

```
### the forgetting removed:
  CheckBalancesAfterARevaluation  32.38ms DONE     <- tenant: acme, stamped
  CheckBalancesAfterARevaluation  19.85ms DONE     <- tenant: none, read acme anyway
acme    findings for the probe entity: 2           <- two rows, one of them a trespass

### as shipped:
  CheckBalancesAfterARevaluation  28.46ms DONE
  CheckBalancesAfterARevaluation   5.06ms FAIL     <- no connection, no reading
acme    findings for the probe entity: 1
```

### Restored, not blanked — the bug the first version had

Blanking the tenant after every job is the obvious implementation. It is right in a worker and wrong everywhere else.

Under the `sync` driver a job runs **inside** a request that already has a tenant established. Blanking it left the rest of that request querying a connection with no database, and the error it produced named a missing password:

```
SQLSTATE[08006] fe_sendauth: no password supplied (Connection: tenant, Database: )
```

That is a long way from the cause, and it was found by running a sync dispatch inside a tenant and reading the ledger afterwards rather than by reasoning about it. So the hooks remember what was established before the job and put it back — on a stack, because a sync dispatch nests.

### The checks

`bin/verify queue`, five of them, each proved to fail when its guard is removed:

| Check | Fails when |
|-------|-----------|
| every payload carries the tenant it was dispatched in | the stamp is removed |
| a job with no tenant is left with no connection | the forgetting is removed from either hook |
| the hooks restore the caller rather than blanking it | the restore is replaced by a blank |
| a failed job does not leave its tenant pointed at | `exceptionOccurred` stops restoring |
| the side-effect listener is shaped for a queue | it loses its marker, its own queue, or its sentence |

The second one queues **two** jobs and runs the hooks in sequence — a tenant's, then a bare one. One job at a time cannot see this bug.

---

## 3. The listener that exists today

`CheckBalancesAfterARevaluation` is the ledger's only queued listener, and it verifies rather than posts.

A revaluation is the heaviest thing the ledger does on its own: a reversal of the previous run and a fresh multi-line entry, every line of it maintaining `account_balances` through a trigger. If the increments and the journal are ever going to disagree, that is where it shows.

It is a side effect because it compares a **cache** against the journal, and the journal is the ledger. An hour late it reports the same difference. Synchronous, it would hold the revaluation's locks for the length of a full recomputation and let a timeout in a verification roll back a posting that was correct.

Two kinds of context reach it by two routes, on purpose:

- **which database** — infrastructure, stamped on the payload
- **which entity** — domain, carried on the event

The entity is not optional. `account_balances` and `journal_lines` are both under row-level security, so with no entity established the policy matches nothing, both sides of the comparison come back empty, and they agree perfectly. A drift check that reports "clean" because it read nothing is worse than no drift check at all — which was observed while building this, when a probe read zero findings from a table that had one in it.

---

## Where this is enforced

| Claim | Enforced by |
|-------|-------------|
| a posting listener is never queued | `bin/verify arch` |
| a side-effect listener is always queued | `bin/verify arch` |
| an event carries no other module's models | `bin/verify arch` |
| no module reaches into `Ledger`'s models | `deptrac.yaml`, read by `bin/verify arch` |
| the tenant travels with the job and is restored after it | `bin/verify queue` |
| a tenantless job cannot read a tenant's database | `bin/verify queue` |
