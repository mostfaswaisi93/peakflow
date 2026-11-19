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
    /*
     | A failure path, added by PF-243's check rather than by anybody noticing.
     |
     | `everyScheduledJobIsOperable()` asserts that every scheduled job alerts
     | on a non-zero exit, and this one - written by the task that invented the
     | rule for the watch - did not have one. It is the quieter failure of the
     | two: nobody is paged by a metric, so a collection that stopped working
     | shows up as a dashboard whose lines simply end, read weeks later as
     | "the figures have been flat".
     */
    ->onFailure(static function (): void {
        app(AlertSink::class)->raise(Alert::critical(
            key: 'scheduled_job_failed',
            tenant: '(the whole fleet)',
            entityId: Alert::NO_ENTITY,
            period: Alert::NO_PERIOD,
            firstDivergence: '`metrics:report --all` exited non-zero',
            messageAr: 'تعذّر جمع مقاييس الأعمال. لا أحد يُستدعى بسبب مقياس، ولذلك فإن توقّف '.
                'الجمع يظهر كلوحة تنتهي خطوطها عند يوم ما — تُقرأ بعد أسابيع على أنها '.
                '«الأرقام ثابتة» لا على أنها «الأرقام غير مجموعة».',
            messageEn: 'Collecting the business metrics failed. Nobody is paged by a metric, so a '.
                'collection that stopped shows up as a dashboard whose lines simply end - read weeks '.
                'later as "the figures have been flat" rather than "the figures are not being '.
                'collected".',
        ));
    })
    ->description('Collect and publish the business metrics');

/*
|------------------------------------------------------------------------------
| The recurring work - PF-243
|------------------------------------------------------------------------------
|
| PF-242 scheduled the invariant watch and stopped there. Measured immediately
| before this block was written:
|
|     $ php artisan schedule:list
|     30 * * * *  php artisan invariants:watch --all
|     17 5 * * *  php artisan metrics:report --all
|
| Ten commands whose work is defined by the passage of time ran on nothing.
| Several say so in their own docblocks - "cron fires it hourly", "cron fires
| it daily", "so it can be scheduled and alerted on" - which is the form this
| class of defect takes in a codebase that documents itself: the sentence
| describing the cadence is the only thing that implements it.
|
| `config/schedule.php` holds the expressions and the reasoning about the
| times, and names the commands that are deliberately NOT here.
|
| ## Why each one is a table row rather than ten written-out blocks
|
| Because the four operational decisions are the same for all of them -
| refuse overlap, run in the background, alert on a non-zero exit, carry a
| description - and ten copies of those four calls is ten places for one of
| them to be missing. What differs per job is its cadence and the SENTENCE
| its failure deserves, and those are exactly the two columns.
|
| The sentence is per job on purpose. "A scheduled job failed" names nothing
| anybody can act on, and the ten failures here have nothing in common:
| a customer not invoiced, a stale rate, a retention promise broken, a hash
| chain not walked. `anAlertNamesTheThing` asserts an alert says what is
| wrong; this is that rule applied to the schedule.
*/

$recurring = [
    [
        'at' => 'recurring_invoices',
        'run' => 'invoices:generate-recurring --all',
        'does' => 'Draft the invoices the recurring schedules owe',
        'ar' => 'تعذّر إصدار الفواتير الدورية. كل جدول استحقّ فاتورة في هذه الساعة لم تُصدر، '.
            'والعميل لم يُفاتَر — ولا شيء آخر سيلاحظ ذلك، لأن الجدولة نفسها هي ما يُنشئ الفاتورة.',
        'en' => 'The recurring invoice run exited non-zero. Every schedule that owed an invoice this '.
            'hour has not produced one, so a customer is not invoiced - and nothing else will notice, '.
            'because this job is what turns a schedule into an invoice.',
    ],
    [
        'at' => 'dunning',
        'run' => 'dunning:run --all',
        'does' => 'Send the dunning notices that are due',
        'ar' => 'تعذّر تشغيل المتابعة. لم تُتابَع اليوم أي فاتورة متأخرة، ولا يوجد سجلّ لذلك '.
            'غير هذا التنبيه: المتابعة التي لم تُرسل لا تترك أثرًا.',
        'en' => 'The dunning run exited non-zero. Nothing overdue was chased today, and the only '.
            'record of that is this alert - a notice that was not sent leaves no trace.',
    ],
    [
        'at' => 'quotes_expire',
        'run' => 'quotes:expire --all',
        'does' => 'Close the quotes whose validity date has passed',
        'ar' => 'تعذّر إغلاق عروض السعر المنتهية. العرض الذي انتهى أمس يُرفض عند القبول — '.
            'فالتاريخ هو الذي يحكم لا العمود — لكنه يظهر مفتوحًا في كل قائمة، وقائمة فيها '.
            'خمسون عرضًا منتهيًا هي قائمة لا يقرأها أحد.',
        'en' => 'Expiring lapsed quotes failed. A quote whose last day was yesterday is still refused '.
            'on acceptance - the date governs, not the column - but it reads as open in every list, '.
            'and a list with fifty expired quotes in it is a list nobody reads.',
    ],
    [
        'at' => 'subscriptions_expire',
        'run' => 'subscriptions:expire',
        'does' => 'Expire the lapsed subscriptions and end the finished trials',
        'ar' => 'تعذّر إنهاء الاشتراكات المنتهية والتجارب المكتملة، فيبقى اشتراك انتهت مدته '.
            'ظاهرًا كساري، وتبقى تجربة مكتملة تمنح ما لم يُدفع مقابله.',
        'en' => 'Expiring lapsed subscriptions failed, so a subscription whose term has ended still '.
            'reads as active and a finished trial still grants what nobody paid for.',
    ],
    [
        'at' => 'fx_rates',
        'run' => 'fx:fetch-rates --all',
        'does' => "Fetch the day's exchange rates, falling back to the last known rate",
        'ar' => 'تعذّر جلب أسعار الصرف، أو تدهور التشغيل إلى آخر سعر معروف. لا شيء يُمنع — '.
            'المنشأة تتابع العمل على آخر سعر، وكل مستند استخدمه مُعلَّم — لكن سعر الأمس صار '.
            'سعر اليوم، وإذا تكرّر فالعلامة ستكون على كل مستند، وهي حينها كأنها على لا شيء.',
        'en' => 'Fetching rates exited non-zero, or the run degraded to the last known rate. Nothing '.
            'is blocked - a business keeps trading on the last rate and every document that uses one '.
            "is flagged - but yesterday's rate is now today's, and if it repeats the flag is on every "
            .'document, which is the same as being on none.',
    ],
    [
        'at' => 'idempotency_prune',
        'run' => 'api:prune-idempotency --all',
        'does' => 'Remove the API idempotency records whose replay window has closed',
        'ar' => 'تعذّر تقليم سجلات الـidempotency. العقد المنشور يقول إن الاستجابة تُحفظ أربعًا '.
            'وعشرين ساعة — وأنها لا تُحفظ بعدها. وهي الآن محفوظة: المبالغ وأسماء الأطراف '.
            'وتمثيل المستند كاملًا، في جدول لا يقلّمه شيء آخر.',
        'en' => 'Pruning the idempotency records failed. The published contract says a response is '.
            'stored for 24 hours - and that it is NOT stored after. It is now stored: the amounts, the '.
            'party names and the whole representation of a document, in a table nothing else prunes.',
    ],
    [
        'at' => 'audit_chain',
        'run' => 'audit:verify --all',
        'does' => 'Re-walk each tenant audit chain and report the first divergence',
        'ar' => 'سلسلة التدقيق: إمّا أن السلسلة تباعدت، أو أن المشي عليها تعذّر. الحالتان تعنيان '.
            'أن سجلّ «من فعل ماذا» لم يُتحقّق منه اليوم، وسلسلة يُمشى عليها أول مرة أثناء '.
            'تحقيق هي سلسلة لم يتحقّق منها أحد قبله.',
        'en' => 'The audit chain run exited non-zero: either the chain has diverged or the walk could '.
            'not run. Both mean the record of who did what was not verified today, and a chain first '.
            'walked during an investigation is a chain nobody had ever verified.',
    ],
    [
        'at' => 'document_chains',
        'run' => 'invoices:verify-chain --all',
        'does' => 'Re-walk each document series and report the first divergence',
        'ar' => 'سلسلة المستندات: إمّا أن تسلسل سلسلة تباعد، أو أن المشي عليها تعذّر. السلسلة '.
            'هي ما يُثبت أن فاتورة مُصدَرة لم تُعدَّل بعد إصدارها، وهو ما يُطلب إثباته عند '.
            'الخلاف لا قبله.',
        'en' => 'The document chain run exited non-zero: either a series has diverged or the walk '.
            'could not run. The chain is what proves an issued invoice was not amended after issue, '.
            'and that proof is asked for in a dispute rather than before one.',
    ],
    [
        'at' => 'attachments',
        'run' => 'attachments:verify --all',
        'does' => 'Compare every stored attachment against its recorded digest',
        'ar' => 'تعذّر التحقّق من المرفقات، أو أن مرفقًا خالف بصمته المسجَّلة. المرفق هو المستند '.
            'الذي يُسلَّم للمدقّق، وبصمة لا يقارنها شيء هي بصمة لا تُثبت شيئًا.',
        'en' => 'Verifying the attachments exited non-zero: either the pass could not run or a stored '.
            'file no longer matches its recorded digest. An attachment is the document handed to an '.
            'auditor, and a digest nothing compares against proves nothing.',
    ],
    [
        'at' => 'access_review',
        'run' => 'access:review --all',
        'does' => 'Review who holds what, and what has gone unused',
        'ar' => 'مراجعة الوصول خرجت بحالة غير صفرية: شخصٌ يحمل صلاحية تُحرّك المال بلا عامل '.
            'ثانٍ مؤكَّد. `RoleGrantService` يرفض إنشاء هذا المنح، فوجودُه يعني أنه وصل من '.
            'استيراد أو كتابةٍ مباشرة أو قبل وجود القاعدة — أي أن ضابطًا لا يمسك.',
        'en' => 'The access review exited non-zero: somebody holds a money-moving permission with '.
            'no confirmed second factor. `RoleGrantService` refuses to create that grant, so one '.
            'that exists arrived by an import, a direct write, or before the rule did - which '.
            'means a control is not holding.',
    ],
    [
        'at' => 'numbering_gaps',
        'run' => 'numbering:gaps --all',
        'does' => 'Check every numbering series for gaps, and report its headroom',
        'ar' => 'ثغرة في تسلسل الترقيم، أو تعذّر الفحص. الثغرة تعني أن رقمًا خُصّص ولم يُكتب '.
            'مستنده، والجواب الصادق على «أين الفاتورة ٢٤١٧» يُقال بينما الحادثة ما تزال في '.
            'الذاكرة، لا بعد ثمانية عشر شهرًا في اجتماع.',
        'en' => 'A gap in a numbering series, or the check could not run. A gap means a number was '.
            'allocated and its document was never written, and the honest answer to "where is invoice '.
            '2,417" has to be given while somebody still remembers the incident rather than eighteen '.
            'months later in a meeting.',
    ],
];

foreach ($recurring as $job) {
    $expression = (string) config('schedule.'.$job['at'], '');

    /*
     | An empty expression is "not scheduled in this deployment", and it is
     | skipped rather than registered.
     |
     | Registering a job with an empty cron expression is not a disabled job:
     | `->cron('')` is an invalid expression, and the scheduler's answer to one
     | is to throw while working out what is due - which takes down the whole
     | minute, every other job in it included. So the one broken entry would
     | stop the invariant watch as well.
     */
    if ($expression === '') {
        continue;
    }

    Schedule::command($job['run'])
        ->cron($expression)
        /*
         | Overlap refused, for the reason the watch refuses it: every one of
         | these makes a fleet-wide pass, and a second pass starting before the
         | first has finished is two of them competing for one connection pool.
         */
        ->withoutOverlapping()
        ->runInBackground()
        ->onFailure(static function () use ($job): void {
            app(AlertSink::class)->raise(Alert::critical(
                key: 'scheduled_job_failed',
                tenant: '(the whole fleet)',
                entityId: Alert::NO_ENTITY,
                period: Alert::NO_PERIOD,
                firstDivergence: sprintf('`%s` exited non-zero', $job['run']),
                messageAr: $job['ar'],
                messageEn: $job['en'],
            ));
        })
        ->description($job['does']);
}
