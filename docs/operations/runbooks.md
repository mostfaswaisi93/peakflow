# Invariant alert runbooks

One section per alert declared in [`config/telemetry.php`](../../config/telemetry.php)'s
`invariant_alerts`. `bin/verify observability` asserts that every declared key
has a section here, that every watched key's **first response** names a command
the application actually registers, and that running that command **changes
nothing** — a first response that repairs is a first response that destroys the
evidence of how long a figure was wrong.

**Nothing here auto-repairs.** Drift is reported, never fixed on the way past:
a silent repair hides the bug that caused it, the bug will produce the drift
again — differently, later, after more has been built on top — and a figure
that was wrong for a week was on a report for a week, in an email, possibly in
a filed return. The repair commands exist (`ledger:rebuild-balances` without
`--check`, `privileges:verify --repair`) and each is a deliberate act with its
own audit entry, taken **after** the cause is known.

Every alert names **the entity, the period and the first divergent record**.
`App\Support\Telemetry\Alerts\Alert` refuses to be constructed without all
three, because at 03:00 the difference between an actionable page and a ledger
to read is whether those three facts are in it.

## How these are exercised

`php artisan invariants:game-day {tenant}` injects each condition below inside
a transaction it rolls back, asserts the alert fires and names the thing, and
runs each first response to prove it is non-destructive. `bin/verify
observability` does the same against a fixture on every run, which is the
difference between a game day somebody remembers to hold and one that cannot
be skipped.

---

## read-model-drift

`read_model_drift` · raised by `App\Tenant\Ledger\Alerts\ReadModelDriftWatcher`

**Fires when** a row in `account_balances` disagrees with the same figure
recomputed from `journal_lines`. Three kinds, which are three different bugs:
`missing` is an increment that never ran — usually a write path that bypassed
the trigger; `extra` is a delta applied twice, or applied and then the line
removed; `wrong` is an arithmetic error in the increment, which is the most
alarming of the three.

**Why it matters** every report in this product reads the stored balances rather
than aggregating ten million journal lines. When they drift the trial balance
is wrong, the statement of financial position is wrong, and both look exactly
as right as they did yesterday. There is no symptom.

**First response (non-destructive):** `php artisan ledger:rebuild-balances {tenant} --check`

Read the finding keys: each is `entity|period|account|dimensions`, so the first
one dates the breach and names the account. Then find the posting that
diverged — `balance_drift_findings` keeps every finding with `found_at`, so the
oldest row in it is when this started.

**Do not** run `ledger:rebuild-balances` without `--check` until you know what
caused it. The rebuild is correct and it erases the only evidence of how long
the figure was wrong, which is the fact a restatement decision turns on.

---

## hash-chain-divergence

`hash_chain_divergence` · raised by `App\Tenant\Ledger\Alerts\LedgerInvariantWatcher`

**Fires when** either of the two hash claims fails: the audit log's chain no
longer verifies (`audit_chain_broken`), or a posted entry's substance no longer
matches the fingerprint taken when it was posted
(`entry_substance_changed`).

**Why it matters** the two together are what "no posted entry has been mutated"
means. The fingerprint says the entry's substance is unchanged; the chain says
the record of who did what has not been edited. Without the chain, whoever
changed a figure could delete the audit entry showing they did. Without the
fingerprint, an intact chain proves only that nobody edited the log.

A divergence means a statement reached the data without going through the
immutability trigger: a disabled trigger, a restored dump, a direct session.
**The trial balance may still tie** — a tamper that balances is the only kind
worth doing.

**First response (non-destructive):** `php artisan audit:verify {tenant}`

Everything before the first divergence is still intact and everything after it
is unverifiable. Stop writing to the tenant, note the chain index the
divergence is at, and preserve the current state before anything else: it is
the evidence.

**Do not** re-fingerprint, re-seal or re-chain. A retroactive hash certifies
whatever the table says today, which converts an incident into a clean-looking
database with no record that anything happened.

---

## subledger-mismatch

`subledger_mismatch` · raised by `App\Tenant\Ledger\Alerts\LedgerInvariantWatcher`

**Fires when** any critical finding from `ledger:verify` that is not an
unbalanced entry or a hash divergence: a control account reached with no
posting rule recorded, suspense holding money no bank line accounts for, in
transit not equal to released-and-unreconciled, inventory not equal to its
movements, a line filed in a different period from its entry, an orphaned line.

**Why it matters** a control account that does not reconcile to its own
subsidiary record stops being a list and becomes a number. A number cannot be
aged, chased or cleared — and it sits in the middle of the three-way tie
between the subledger, the bank and the general ledger, so every reconciliation
from then on has a difference nobody can name.

**First response (non-destructive):** `php artisan ledger:verify {tenant} --totals`

The finding names the account, the role and the entry. `--totals` prints each
control account total beside it, which is what tells you whether the subledger
or the control account moved.

**Do not** post a correcting journal to make the two agree. That freezes the
difference as an entry nobody can explain and destroys the evidence of which
side was wrong.

---

## unbalanced-entry-in-database

`unbalanced_entry_in_database` · raised by `App\Tenant\Ledger\Alerts\LedgerInvariantWatcher`

**Fires when** a non-draft journal entry's lines do not sum to zero in the
functional currency.

**Why it matters** this should be impossible. The balanced-entry rule is a
deferred constraint trigger and it cannot be violated through the database, so
a finding means the trigger has been dropped, disabled, or the data came from a
restore that skipped it. Treat it as a tampering or privilege incident, not as
a data problem.

**First response (non-destructive):** `php artisan ledger:verify {tenant}`

Then check the trigger is present and enabled — `bin/verify ledger`'s "every
ledger guard is enabled" asks exactly that — and find out what ran. Every
report printed from the affected entries since they were posted is wrong.

**Do not** delete or amend the entry. It is the evidence, and amending a posted
entry is the thing that is supposed to be impossible.

---

## suspense-above-threshold

`suspense_above_threshold` · raised by `App\Tenant\Banking\Alerts\SuspenseThresholdWatcher`

**Fires when** the suspense control account's balance exceeds
`ALERT_SUSPENSE_THRESHOLD` in the entity's functional currency.

**Why it matters** anything in suspense could be a sale, and a sale sitting in
suspense is revenue that is not in the accounts and output tax that is not on
the return. Rising suspense means matching is failing, before anybody
complains. It is also what blocks the period close, so a controller who is not
watching it meets it for the first time on the day they are trying to close.

**First response (non-destructive):** `php artisan invariants:watch {tenant} --only=suspense_above_threshold`

The alert names the oldest parked item and its age. Check the latest import's
format and the matching rules: a format change at the bank is the usual cause,
and it shows up as everything after one import date being unmatched.

**Do not** clear suspense to make the figure go away. Match the items, or have a
controller sign the balance off with a reason — the sign-off is recorded
against that figure, so a later arrival refuses again rather than inheriting a
decision made about a smaller number.

---

## in-transit-aged

`in_transit_aged` · raised by `App\Tenant\Settlement\Alerts\InTransitAgedWatcher`

**Fires when** a released payment instruction has been unclear for more than
five days.

**Why it matters** it is one of three things and each is somebody's problem: a
file that was generated and never handed to the bank, a payment the bank
rejected without telling us, or a statement line nobody matched. All three end
with a supplier who has not been paid and a ledger that says they have. The
supplier finds out; nobody here does.

**First response (non-destructive):** `php artisan invariants:watch {tenant} --only=in_transit_aged`

The alert names the instruction, the run and the bank status. Check whether the
run's payment file was ever downloaded — if it was not, the bank never saw it.

**Do not** clear the in-transit control account. The instruction is the record
of what was promised to whom.

---

## privilege-drift

`privilege_drift` · raised by `App\Platform\Alerts\PrivilegeDriftWatcher`

**Fires when** the live grants on a tenant database differ from the declared
matrix, or a table that requires row-level security no longer has a policy.

**Why it matters** a manual grant made during an incident is exactly how this
regresses — between deploys, at 02:00, by somebody who then goes to bed. The
application scope is not the only line of defence, and the hour it becomes the
only one is worth knowing about.

**First response (non-destructive):** `php artisan privileges:verify --tenant={tenant}`

It reports every drift per tenant and exits non-zero. Record **who granted
what and why** before changing anything: the grant is the evidence.

**Do not** run `privileges:verify --repair` first. It is the right fix and it
erases the grant, which is the only trace of the incident that produced it.

---

## archive-checksum-mismatch

`archive_checksum_mismatch` · raised by `App\Exchange\Alerts\ArchiveChecksumWatcher`

**Fires when** a sealed period's artefacts no longer match their manifest:
missing from storage, diverged from their hash, removed since the seal, added
since the seal, or a manifest that no longer hashes to what was sealed.

**Why it matters** these are the documents a tax authority will ask for years
from now, and nobody looks at an archive — that is the point of one. A mismatch
is storage corruption or somebody editing history, and in both cases the only
moment it can be fixed is while a backup taken before the divergence still
exists.

**First response (non-destructive):** `php artisan archive:verify {tenant}`

It re-checksums every sealed period rather than the sampled two the watch
checks each run. Then restore from a backup taken before the divergence and
compare.

**Do not** re-seal the period. Re-sealing makes the manifest describe whatever
is there now, which destroys the only record of what was sealed.

---

## statement-continuity-gap

`statement_continuity_gap` · **not yet watched**

**Not yet watched:** continuity is enforced at import rather than detected
afterwards. `StatementImport` refuses a statement whose opening balance does not
meet the previous closing balance, and a database trigger refuses it again, so a
gap cannot be imported — what is left is a statement that was never fetched at
all, which is an absence rather than a divergence. Detecting an absence needs
the bank's own statement inventory, which arrives with the feed work in
**PF-148**. Declared here so the key is not quietly dropped.

---

## clearance-rejection-spike

`clearance_rejection_spike` · **not yet watched**

**Not yet watched:** there is no transmission record to count rejections in. The
Exchange transport, its submissions, its authority responses and its dead
letter are **PF-212 to PF-229** and unbuilt, so there is no column that holds a
clearance outcome and no timestamp to measure a latency against. A rate derived
from something else — a validation failure, a draft that was never issued —
would be a figure on a dashboard that does not mean what its name says, which
is worse than an absent one.

---

## scheduled-job-failed

`scheduled_job_failed` · raised by the schedule's own `onFailure` in
`routes/console.php`

**Fires when** any of the ten recurring jobs exits non-zero: the recurring
invoice run, dunning, quote expiry, subscription expiry, the rate fetch, the
idempotency prune, either hash-chain walk, the attachment digest pass or the
numbering gap report.

**Why it matters** depends on which one, and the alert says so in its own
sentence rather than making you look it up. The three worth knowing by heart:
the recurring invoice run is what turns a schedule into an invoice, so a failed
run is a customer who is not invoiced and nothing else will notice; the
idempotency prune is the only thing that keeps the published 24-hour retention
promise, so a failed run means stored response bodies — amounts, party names —
are kept past what the contract says; and a dunning notice that was not sent
leaves no trace at all, so this alert is the only record that today's chasing
did not happen.

**First response (non-destructive):** `php artisan schedule:list`

It prints every registered job with its expression and when it is next due,
which answers the first two questions: is the job still registered at all, and
is its cron expression the one you think it is. An entry missing from that list
means `config/schedule.php` has an empty expression for it — which is how a
deployment disables one — and not that the job is broken.

Then run the named command by hand with its `--all`, and read the output: every
one of them reports per tenant and per entity rather than a total, so the line
before the failure names where it stopped.

**Do not** empty the expression in `config/schedule.php` to stop a repeating
alert. That is the supported way to disable a job and it is silent by design:
nothing then reports that the work is not happening, which for the invoice run
means billing stops with no alert attached to it. Fix the cause, or say out
loud that the job is off.

---

## watch-failed

`watch_failed` · raised by `App\Support\Telemetry\Alerts\InvariantWatch` and by
the schedule's own `onFailure`

**Fires when** a watcher throws, a tenant's database cannot be reached, or the
scheduled run of `invariants:watch --all` exits non-zero.

**Why it matters** a watch that failed silently is indistinguishable from a
watch that found nothing, and the second is the reassuring reading — so the
hour this was broken would be the hour every invariant above reported as
healthy. This is the alert that makes the others trustworthy.

**First response (non-destructive):** `php artisan invariants:watch {tenant}`

Run it by hand and read the error. A watcher that throws names itself in the
alert's first-divergence field.

**Do not** disable the watch to stop the noise. Every invariant above is
unobserved for as long as it is off, and nothing will say so.
