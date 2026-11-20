<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| When the recurring work runs - PF-243
|------------------------------------------------------------------------------
|
| PF-242 put the invariant watch on a schedule and left every BUSINESS job
| off it. Measured, immediately before this file existed:
|
|     $ php artisan schedule:list
|     30 * * * *  php artisan invariants:watch --all
|     17 5 * * *  php artisan metrics:report --all
|
| Two entries. Meanwhile the product had built, documented and tested nine
| other commands whose work is defined by the passage of time, and whose own
| docblocks say so in as many words - "cron fires it hourly", "cron fires it
| daily", "so it can be scheduled and alerted on". Nothing fired any of them.
|
| The consequences are not uniform, and that is the point of listing them:
|
|   - `invoices:generate-recurring` is how a subscription becomes an invoice.
|     Unscheduled, a monthly billing run happens when somebody remembers, and
|     the customer is invoiced late or not at all.
|   - `fx:fetch-rates` unscheduled means every document is converted at the
|     last rate anybody fetched by hand. The flag that says "this used a
|     stale rate" would be on everything, which is the same as being on
|     nothing.
|   - `api:prune-idempotency` is the only thing that keeps the published
|     24-hour retention promise. Unscheduled, every stored response body -
|     amounts, party names, the whole representation of a document - is kept
|     for ever, and the contract says it is not.
|   - `audit:verify` and `invoices:verify-chain` walk the two hash chains.
|     Unscheduled, the first walk of a chain happens during the investigation
|     that needed it - and a chain verified for the first time under
|     challenge is a chain nobody has ever verified.
|
| Expressions rather than `->daily()` calls, for the two reasons
| `config/telemetry.php` already gives: an operator can move a window on a
| large fleet without a deployment, and the schedule becomes a value a check
| can assert about.
|
| ## On the times
|
| The watch runs every hour at :30 and is the one job that must not be
| crowded out, so nothing here lands at :30. The overnight jobs are spread
| across separate minutes rather than stacked on the hour: four fleet-wide
| passes starting in the same minute is one connection pool's worth of load
| arriving at once, on the hour when the backup is also running.
|
| `fx:fetch-rates` is last of the overnight jobs on purpose. It has to finish
| before the business day starts, and it is the one whose failure is
| survivable - it falls back to the last known rate and raises an alert, so a
| provider outage at 04:25 delays nothing.
|
| ## What is deliberately NOT here
|
|   - `fx:revalue` and `revenue:recognize` POST journal entries. A period-end
|     posting that happens unattended is a posting nobody reviewed, and the
|     entries are dated into a period somebody is about to close. These stay
|     a decision a controller makes, with the figures in front of them.
|   - `tenants:discard-abandoned` DROPS databases. Its own docblock explains
|     why it is not a sweep: an abandoned attempt and a provisioning run that
|     is still going are indistinguishable from the registry, and the only
|     thing that tells them apart is how long ago it started. Scheduling it
|     would hand that judgement to cron.
|   - `mfa:status` and `sod:exceptions` take a REQUIRED tenant argument and
|     have no `--all`. They are reports somebody reads, and scheduling them
|     fleet-wide would mean changing their signatures first.
|   - `databases:seal`, `archive:drill` and `invariants:game-day` are
|     exercises an operator runs deliberately.
|
| An empty string disables one entry without touching the schedule file, for
| the deployment that has a reason - and `routes/console.php` skips it rather
| than registering a job with no expression.
|
*/

return [

    /*
    |---------------------------------------------------------------------------
    | Billing and the sales cycle
    |---------------------------------------------------------------------------
    |
    | Recurring invoices hourly, because its own docblock says cron fires it
    | hourly and the occurrence table's unique index is what makes that safe -
    | a schedule firing twice in a minute still produces one invoice per
    | schedule per occurrence date. Hourly rather than daily so that a
    | schedule due at 09:00 is invoiced in the morning rather than the
    | following midnight.
    |
    | Dunning once a day, and once only. It sends a statement per customer per
    | day by unique index, so a second run the same day chases nobody twice -
    | but a schedule that fired it hourly would be relying on that index to
    | undo an obviously wrong cadence. It sends nothing to a customer who has
    | no dunning policy assigned, which is what makes a fleet-wide schedule
    | safe: chasing is opt-in per customer, by configuration.
    |
    */

    'recurring_invoices' => (string) env('SCHEDULE_RECURRING_INVOICES', '10 * * * *'),
    'dunning' => (string) env('SCHEDULE_DUNNING', '40 6 * * *'),
    'quotes_expire' => (string) env('SCHEDULE_QUOTES_EXPIRE', '5 1 * * *'),
    'subscriptions_expire' => (string) env('SCHEDULE_SUBSCRIPTIONS_EXPIRE', '15 1 * * *'),

    /*
    |---------------------------------------------------------------------------
    | Rates, and the retention promise
    |---------------------------------------------------------------------------
    */

    'fx_rates' => (string) env('SCHEDULE_FX_RATES', '25 4 * * *'),
    'idempotency_prune' => (string) env('SCHEDULE_IDEMPOTENCY_PRUNE', '50 3 * * *'),

    /*
    |---------------------------------------------------------------------------
    | The chains and the series
    |---------------------------------------------------------------------------
    |
    | Daily for the two hash chains, because what they prove is that nothing
    | was amended, and the window in which an amendment can still be explained
    | is measured in days.
    |
    | Weekly for the two reports. Attachment divergence and a numbering gap are
    | both conditions somebody investigates rather than conditions somebody is
    | paged for, and a numbering series' headroom warning is useful months
    | ahead - which a weekly run gives just as well as a daily one, at a
    | seventh of the cost of walking every series in the fleet.
    |
    */

    'audit_chain' => (string) env('SCHEDULE_AUDIT_CHAIN', '45 2 * * *'),
    'document_chains' => (string) env('SCHEDULE_DOCUMENT_CHAINS', '5 3 * * *'),
    'attachments' => (string) env('SCHEDULE_ATTACHMENTS', '20 3 * * 0'),
    'numbering_gaps' => (string) env('SCHEDULE_NUMBERING_GAPS', '40 3 * * 1'),

    /*
    |---------------------------------------------------------------------------
    | The access review
    |---------------------------------------------------------------------------
    |
    | Monthly, on the first, because the criterion asks for a PERIODIC review
    | and a review nobody is reminded of is a review that happens the week
    | before an audit.
    |
    | Monthly rather than quarterly for one reason: it exits non-zero on a
    | money-moving permission held by somebody with no second factor, and
    | that is a control that is not holding. Waiting a quarter to find out is
    | three months of it not holding. Everything else it reports is a fact
    | about an organisation and keeps perfectly well for a month.
    |
    */

    'access_review' => (string) env('SCHEDULE_ACCESS_REVIEW', '15 5 1 * *'),

    /*
    |---------------------------------------------------------------------------
    | And the commands that are deliberately not scheduled
    |---------------------------------------------------------------------------
    |
    | A complete inventory, asserted by
    | `ObservabilityRules::everyCommandHasACadenceDecision()`: every class in
    | `app/Console/Commands` is either scheduled above, or named here with the
    | reason it is not. A command in neither is the violation - which is the
    | state ten of them were in before PF-243, and the state nothing could
    | have told us about, because an unscheduled command looks exactly like a
    | command nobody has got round to.
    |
    | The check also asserts that every key here is a REAL command, so
    | renaming one does not quietly carry its exemption across, and that no
    | command is both scheduled and listed - which would be a contradiction
    | somebody should read rather than resolve by precedence.
    |
    | A reason of "covered by invariants:watch" is not an exemption from being
    | watched. It means the invariant this command proves is proved hourly by
    | a registered watcher, so scheduling the command as well would walk the
    | same ledger twice. `everyDeclaredAlertIsWatched` is what holds that
    | claim to account.
    |
    */

    'not_scheduled' => [

        'tenants:dump' => 'an operator runs it, and the reason is what it is FOR: moving a tenant '.
            "to another installation, or taking a copy before something irreversible. Neither is ".
            'defined by the passage of time, and a dump on a schedule would be a second backup '.
            'regime beside the one the database already has - with its own retention nobody set '.
            'and its own disk nobody watches.',

        'tenants:restore' => 'an operator runs it, into a database they named, and it refuses to '.
            'overwrite a live one. A restore that could start itself is the one command in the '.
            'product that can destroy a tenant, and a schedule is a way for it to happen at '.
            "03:00 on a Sunday to a database somebody renamed.",

        'ledger:verify' => 'covered by invariants:watch: LedgerInvariantWatcher runs the same '.
            'verifier hourly, per entity, and raises the findings as alerts.',

        'ledger:rebuild-balances' => 'covered by invariants:watch: ReadModelDriftWatcher runs the '.
            'same comparison. The command also REBUILDS, which is a repair and not a check - and a '.
            'repair on a schedule is a schedule that hides what it is repairing.',

        'archive:verify' => 'covered by invariants:watch: ArchiveChecksumWatcher compares the same '.
            'digests hourly.',

        'privileges:verify' => 'covered by invariants:watch: PrivilegeDriftWatcher runs the same '.
            'PrivilegeMatrix - tenant drift and row-security drift - hourly.',

        'revenue:recognize' => 'posts journal entries. A period-end posting that happens unattended '.
            'is a posting nobody reviewed, dated into a period somebody is about to close. It stays '.
            'a decision a controller makes with the figures in front of them.',

        'fx:revalue' => 'posts journal entries, for the same reason as revenue:recognize. The '.
            'revaluation of an open balance is an accounting judgement about a date, not a chore.',

        'tenants:discard-abandoned' => 'drops databases. Its own docblock explains why it is not a '.
            'sweep: an abandoned provisioning attempt and one that is still running are '.
            'indistinguishable from the registry, and only elapsed time tells them apart. '.
            'Scheduling it would hand that judgement to cron.',

        'databases:seal' => 'changes privileges on a live cluster, as the last step of provisioning '.
            'or after a deliberate repair. Nothing about it is periodic.',

        'archive:drill' => 'a restore exercise an operator runs deliberately, and reads. Its value '.
            'is in somebody having done it, which a cron job cannot supply.',

        'invariants:game-day' => 'breaks an invariant on purpose to prove the alert fires. It is '.
            'the test of the watch, not part of it.',

        'mfa:status' => 'takes a REQUIRED tenant argument and has no --all, so it cannot be '.
            'scheduled fleet-wide without changing its signature first. Worth doing; it is a '.
            'report with a per-tenant answer, so the schedule would need to name a destination '.
            'for it as well.',

        'sod:exceptions' => 'the same: a required tenant argument, no --all, and it is part of the '.
            'compliance evidence pack rather than a condition anybody is paged for.',

        'tenants:provision' => 'creates a tenant. An operator does this.',

        'tenants:export' => 'produced when a tenant asks for its data, which is a request with a '.
            'recipient and a date. A scheduled export is a complete copy of a customer\'s database '.
            'sitting on disk that nobody asked for and nobody is waiting to collect - which is a '.
            'second place to lose it from, created monthly.',

        'compliance:report' => 'produced on request, for a date somebody names. An auditor asks for '.
            'a point in time and the pack is built for that point; a monthly one nobody asked for '.
            'is a file nobody opens, dated a day nobody cares about.',

        'tenants:migrate' => 'runs migrations across the fleet, which happens on deployment and '.
            'under supervision. A schedule that migrated tenants would apply a half-reviewed '.
            'migration at 03:00.',

    ],

];
