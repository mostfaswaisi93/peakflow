<?php

declare(strict_types=1);

use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Contracts\AlertSink;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|------------------------------------------------------------------------------
| The invariant watch - PF-242
|------------------------------------------------------------------------------
|
| This file is where PeakFlow schedules work, and until PF-242 it scheduled
| none: it held one command and that command printed a quote. Which is the
| whole of what was wrong with the observability design - `ledger:verify`,
| `audit:verify`, `privileges:verify`, `archive:verify` and
| `ledger:rebuild-balances --check` were all built, all exit non-zero on a
| breach, and nothing invoked any of them. Every invariant in the product was
| provable and none was watched.
|
| There is no CI and no deploy pipeline in this repository and the product
| owner has asked for no GitHub Actions, so an alert cannot be "a workflow
| runs the check". It also should not be: a balance read model drifts when a
| write path bypasses a trigger, a privilege is granted by hand during an
| incident, an archive rots in storage. All of those happen BETWEEN deploys,
| and a check that ran only on the next one would find them days later.
|
| So the schedule is here, the expression is configuration, and the failure
| path is explicit.
*/

Schedule::command('invariants:watch --all')
    ->cron((string) config('telemetry.watch_schedule', '30 * * * *'))
    /*
     | Overlap is refused, not queued.
     |
     | The watch runs `ledger:verify`'s full pass per entity. On a fleet whose
     | tenants have grown, two of them overlapping means two full passes over
     | the same ledger competing for the same connection - and the second one
     | finishing after the third one started is how a scheduled check quietly
     | becomes a load problem. A skipped run is reported by the next one,
     | because every condition here is a state rather than an event.
     */
    ->withoutOverlapping()
    ->runInBackground()
    /*
     | And a failed run is itself an alert.
     |
     | `onFailure` fires when the command exits non-zero, which it does on any
     | critical alert AND on a crash. Both deserve the same page: a watch that
     | could not run is indistinguishable from a watch that found nothing, and
     | the second is the reassuring reading - so the hour this was broken
     | would be the hour every invariant reported as healthy.
     |
     | Raised through the same sink as every other alert, so a deployment that
     | has configured a destination gets this one there too rather than only
     | in a log nobody is watching.
     */
    ->onFailure(static function (): void {
        app(AlertSink::class)->raise(Alert::critical(
            key: 'watch_failed',
            tenant: '(the whole fleet)',
            entityId: Alert::NO_ENTITY,
            period: Alert::NO_PERIOD,
            firstDivergence: 'the scheduled run of `invariants:watch --all` exited non-zero',
            messageAr: 'انتهى تشغيل مراقبة الثوابت بحالة غير صفرية: إمّا أن ثابتاً انكسر، أو أن '.
                'المراقبة نفسها تعذّر تشغيلها. راجع مخرجات التشغيل: الحالتان تستحقان النظر، والثانية '.
                'أخطر لأنها تجعل كل ثابت يبدو سليماً.',
            messageEn: 'The scheduled invariant watch exited non-zero: either an invariant is broken or '.
                'the watch itself could not run. Read the run output - both deserve attention, and the '.
                'second is the worse of the two, because it makes every invariant report as healthy.',
        ));
    })
    ->description('Watch the ledger, archive and privilege invariants');

/*
|------------------------------------------------------------------------------
| The business metrics - PF-241
|------------------------------------------------------------------------------
|
| Daily rather than hourly, and at a time nobody else uses. These are figures
| a controller reads in the morning and an operator trends over weeks, not
| conditions anybody is paged for - the conditions are above. Collecting them
| every hour would multiply the cost of the one metric that aggregates every
| document in the tenant for no change in what anybody does with it.
|
| Published through the metric sink, whose committed default writes one JSON
| line per sample on the channel the allowlist taps - so a collector that
| already reads the log reads the metrics, with no push gateway to stand up.
*/

Schedule::command('metrics:report --all')
    ->dailyAt('05:17')
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Collect and publish the business metrics');
