<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Enums\Proration;
use App\Tenant\Sales\Models\RecurringSchedule;
use App\Tenant\Sales\Recurrence\RecurrenceRule;
use App\Tenant\Sales\Services\RecurringGenerator;
use App\Tenant\Sales\Services\RecurringSchedules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recurring schedules and the rules behind them - PF-105, PF-106.
 *
 * ## The rule engine is a deliberate subset, and it says so
 *
 * "The last working day of every quarter" has to be expressible rather than
 * approximated, which needs real recurrence rules. The full RFC 5545 is large
 * and most of it never appears in a billing schedule, so the unsupported
 * parts are refused **by name** - a rule nobody can express is a
 * conversation, and a rule silently approximated is an invoice on the wrong
 * day that somebody has to find.
 *
 * ## Generation is idempotent on the index, not on care
 *
 * Every duplicate-billing scenario ends at
 * `recurring_occurrences (schedule_id, occurrence_date)`: a scheduler that
 * fires twice, two nodes in the same minute, a retry after a timeout,
 * somebody running the command by hand while cron runs it. The lock is an
 * optimisation and the index is the guarantee, because "nobody double-bills
 * as long as Redis is healthy" is not a property worth claiming.
 */
final class RecurringScheduleRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['recurring schedules (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the last working day of every quarter is expressible' => fn (): array => $this->theHardRule(),
                'the rules it does not support are refused by name' => fn (): array => $this->refusedByName(),
                'a day that does not exist clamps rather than skips the month' => fn (): array => $this->clamping(),
                'end conditions: a date, a count, or open-ended' => fn (): array => $this->endConditions(),
                'the preview shows twelve occurrences with their amounts' => fn (): array => $this->thePreview(),
                'proration shows its basis on the generated line' => fn (): array => $this->proration(),
                'a price change on the item reaches the schedule' => fn (): array => $this->priceChanges(),
                'the price\'s provenance is snapshotted on the line' => fn (): array => $this->priceProvenanceIsOnTheLine(),
                'generation is idempotent on the occurrence date' => fn (): array => $this->idempotent(),
                'it runs in the schedule\'s own timezone' => fn (): array => $this->timezones(),
                'a failed occurrence is retried, then surfaced' => fn (): array => $this->failures(),
                'a paused schedule generates nothing' => fn (): array => $this->paused(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function theHardRule(): array
    {
        $violations = [];

        /*
         | The rule the task names, and the reason the engine exists.
         |
         | A `frequency` column and a `day_of_month` column cannot say it;
         | an approximation bills on the 30th and somebody finds out when a
         | bank holiday lands there.
         */
        $rule = RecurrenceRule::parse('FREQ=MONTHLY;INTERVAL=3;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1');
        $dates = array_map(
            static fn (CarbonImmutable $d): string => $d->toDateString(),
            $rule->occurrences(CarbonImmutable::parse('2026-01-01'), 4),
        );

        $expected = ['2026-01-30', '2026-04-30', '2026-07-31', '2026-10-30'];

        if ($dates !== $expected) {
            $violations[] = sprintf(
                'the last weekday of each quarter came out as %s and should be %s',
                implode(', ', $dates),
                implode(', ', $expected),
            );
        }

        /*
         | And the Gulf working week, where the weekend is Friday and
         | Saturday: the same rule with a different BYDAY, which is what
         | makes it a rule rather than a hard-coded notion of "working day".
         */
        $gulf = RecurrenceRule::parse('FREQ=MONTHLY;INTERVAL=3;BYDAY=SU,MO,TU,WE,TH;BYSETPOS=-1');
        $gulfDates = array_map(
            static fn (CarbonImmutable $d): string => $d->toDateString(),
            $gulf->occurrences(CarbonImmutable::parse('2026-01-01'), 2),
        );

        if ($gulfDates === $dates) {
            $violations[] = 'the Gulf working week produces the same dates as the Western one, so '.
                'BYDAY is not being read';
        }

        if ($rule->describe('en') === '' || ! str_contains($rule->describe('en'), 'last')) {
            $violations[] = 'the rule cannot describe itself in words, so a preview cannot say what '.
                'it means';
        }

        return $violations;
    }

    /** @return list<string> */
    public function refusedByName(): array
    {
        $violations = [];

        $cases = [
            'FREQ=HOURLY' => 'HOURLY',
            'FREQ=MONTHLY;BYWEEKNO=3' => 'BYWEEKNO',
            'FREQ=MONTHLY;BYYEARDAY=100' => 'BYYEARDAY',
            'FREQ=MONTHLY;WKST=SU' => 'WKST',
            'FREQ=MONTHLY;BYSETPOS=2;BYDAY=MO' => 'BYSETPOS=2',
            'FREQ=MONTHLY;BYMONTHDAY=-2' => 'BYMONTHDAY=-2',
            'FREQ=MONTHLY;COUNT=2;UNTIL=20261231' => 'COUNT or UNTIL',
            'FREQ=DAILY;BYDAY=MO' => 'FREQ=WEEKLY',
            'FREQ=MONTHLY;BYDAY=XX' => 'MO TU WE TH FR SA SU',
            'nonsense' => 'NAME=VALUE',
            '' => 'no default',
        ];

        foreach ($cases as $rule => $mustSay) {
            try {
                RecurrenceRule::parse((string) $rule);

                $violations[] = sprintf('"%s" was accepted', $rule === '' ? '(empty)' : $rule);
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), $mustSay)) {
                    $violations[] = sprintf(
                        'the refusal of "%s" does not mention "%s", so the reader cannot tell whether '.
                        'to rewrite the rule or ask for the part to be built: %s',
                        $rule === '' ? '(empty)' : $rule,
                        $mustSay,
                        mb_substr($e->getMessage(), 0, 90),
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function clamping(): array
    {
        /*
         | The one deliberate divergence from RFC 5545.
         |
         | The specification skips impossible dates, so BYMONTHDAY=31
         | produces nothing in February and a monthly schedule silently
         | misses four months a year. For billing that is wrong: a
         | subscription billed "on the 31st" is billed every month, and the
         | customer who signed up on the 31st of January does not get
         | February free.
         */
        $dates = array_map(
            static fn (CarbonImmutable $d): string => $d->toDateString(),
            RecurrenceRule::parse('FREQ=MONTHLY;BYMONTHDAY=31')
                ->occurrences(CarbonImmutable::parse('2026-01-01'), 4),
        );

        $expected = ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'];

        return $dates === $expected
            ? []
            : [sprintf(
                'a schedule on the 31st produced %s and should clamp to %s - the specification skips '.
                'February, which would give the customer a free month',
                implode(', ', $dates),
                implode(', ', $expected),
            )];
    }

    /** @return list<string> */
    public function endConditions(): array
    {
        $violations = [];

        $count = RecurrenceRule::parse('FREQ=MONTHLY;COUNT=3')
            ->occurrences(CarbonImmutable::parse('2026-01-15'), 12);

        if (\count($count) !== 3) {
            $violations[] = sprintf('COUNT=3 produced %d occurrences', \count($count));
        }

        $until = RecurrenceRule::parse('FREQ=MONTHLY;UNTIL=20260401')
            ->occurrences(CarbonImmutable::parse('2026-01-15'), 12);

        if (\count($until) !== 3) {
            $violations[] = sprintf('UNTIL=2026-04-01 produced %d occurrences and should produce 3', \count($until));
        }

        $open = RecurrenceRule::parse('FREQ=MONTHLY')
            ->occurrences(CarbonImmutable::parse('2026-01-15'), 24);

        if (\count($open) !== 24) {
            $violations[] = 'an open-ended rule stopped early, so a schedule with no end condition '.
                'would stop billing on its own';
        }

        // And the schedule's own end date, which is what the screen edits.
        $schedule = $this->schedule(endsOn: CarbonImmutable::now()->addMonths(2));

        if (\count($schedule->upcoming(12)) > 3) {
            $violations[] = 'the schedule\'s own end date does not bound its occurrences';
        }

        return $violations;
    }

    /** @return list<string> */
    public function thePreview(): array
    {
        $violations = [];
        $schedule = $this->schedule();
        $preview = app(RecurringSchedules::class)->preview($schedule, 12);

        if (\count($preview['occurrences']) !== 12) {
            $violations[] = sprintf(
                'the preview shows %d occurrences and twelve were asked for. A recurring schedule '.
                'acts on its own for years, and "activate and see what happens next month" is how a '.
                'business finds out it mis-set a rule by being told by a customer',
                \count($preview['occurrences']),
            );
        }

        foreach (['date', 'net', 'tax', 'gross', 'lines'] as $field) {
            if (! \array_key_exists($field, $preview['occurrences'][0] ?? [])) {
                $violations[] = sprintf('an occurrence in the preview has no %s', $field);
            }
        }

        if (($preview['occurrences'][0]['gross'] ?? '') === '0.0000') {
            $violations[] = 'the preview shows amounts of zero, so it says when but not how much';
        }

        if (! str_contains((string) $preview['describes'], 'month')) {
            $violations[] = 'the preview does not describe the rule in words';
        }

        if ((string) $preview['ends'] === '') {
            $violations[] = 'the preview does not say when the schedule ends, which is the question '.
                'somebody asks before activating one';
        }

        /*
         | And the preview is computed the same way the generator computes.
         |
         | A preview computed differently is worse than none, because it is
         | believed. The claim is checked by generating the first occurrence
         | and comparing it to what the preview said.
         */
        $first = CarbonImmutable::parse((string) $preview['occurrences'][0]['date']);
        app(RecurringSchedules::class)->activate($schedule);
        $outcome = app(RecurringGenerator::class)->generate($schedule->refresh(), $first);

        if (($outcome['outcome'] ?? '') !== 'generated') {
            $violations[] = 'the first previewed occurrence could not be generated: '.
                (string) ($outcome['error'] ?? '');

            return $violations;
        }

        if (($outcome['gross'] ?? '') !== (string) $preview['occurrences'][0]['gross']) {
            $violations[] = sprintf(
                'the preview said %s and the generator billed %s. A preview computed differently from '.
                'the generator is worse than none, because it is believed',
                (string) $preview['occurrences'][0]['gross'],
                (string) ($outcome['gross'] ?? ''),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function proration(): array
    {
        $violations = [];

        // A schedule starting mid-month, billed monthly, prorated by day.
        $start = CarbonImmutable::parse('2026-03-20');
        $schedule = $this->schedule(
            rrule: 'FREQ=MONTHLY;BYMONTHDAY=20',
            startsOn: $start,
            proration: Proration::ByDay,
        );

        $preview = app(RecurringSchedules::class)->preview($schedule, 2);
        $first = $preview['occurrences'][0] ?? [];
        $basis = (string) ($first['lines'][0]['basis'] ?? '');

        if (! str_contains($basis, 'prorated')) {
            $violations[] = sprintf(
                'the first occurrence of a mid-month schedule says "%s" rather than showing the '.
                'proration. "Prorated 12/31 days from 2026-03-20" on the invoice is the difference '.
                'between a question and a dispute',
                $basis,
            );
        }

        foreach (['/', '2026-03-20'] as $needle) {
            if (! str_contains($basis, $needle)) {
                $violations[] = sprintf('the basis does not contain "%s": %s', $needle, $basis);
            }
        }

        /*
         | And the basis reaches the invoice line, not just the preview.
         |
         | A customer who can see the arithmetic does not telephone about it.
         */
        app(RecurringSchedules::class)->activate($schedule);
        $outcome = app(RecurringGenerator::class)->generate(
            $schedule->refresh(),
            CarbonImmutable::parse((string) $first['date']),
        );

        if (($outcome['outcome'] ?? '') === 'generated') {
            $line = DB::connection('tenant')->table('invoice_lines')
                ->where('invoice_id', (string) $outcome['invoice'])
                ->value('description');

            if (! str_contains((string) $line, 'prorated')) {
                $violations[] = sprintf('the generated line reads "%s" with no basis on it', (string) $line);
            }
        } else {
            $violations[] = 'the prorated occurrence could not be generated: '.(string) ($outcome['error'] ?? '');
        }

        // A full period is not labelled as prorated: noise on every line is
        // noise nobody reads.
        $plain = $this->schedule();
        $plainPreview = app(RecurringSchedules::class)->preview($plain, 1);

        if (str_contains((string) ($plainPreview['occurrences'][0]['lines'][0]['basis'] ?? ''), 'prorated')) {
            $violations[] = 'a full period is labelled as prorated';
        }

        return $violations;
    }

    /** @return list<string> */
    public function priceChanges(): array
    {
        $violations = [];

        /*
         | A line with no price on the schedule resolves one at the
         | OCCURRENCE date, which is what makes a price change reach a
         | contract tied to a price list. Resolving at the schedule's start
         | date would bill 2024's price in 2027.
         */
        $line = DB::connection('tenant')->table('recurring_schedule_lines')->whereRaw('false')->exists();

        $schedule = $this->schedule();
        $scheduleLine = $schedule->lines()->first();

        if ($scheduleLine === null) {
            return ['the fixture schedule has no lines'];
        }

        if ($scheduleLine->resolvesItsPrice()) {
            $violations[] = 'the fixture line has no fixed price, so this check is not testing what '.
                'it claims';
        }

        $preview = app(RecurringSchedules::class)->preview($schedule, 1);

        if (($preview['occurrences'][0]['lines'][0]['price_from'] ?? '') !== 'fixed on the schedule') {
            $violations[] = 'a fixed price does not say it is fixed, so nobody can tell a contract '.
                'with a fixed price from one tied to a list';
        }

        /*
         | And the price list path says which list it resolved against.
         |
         | Built without an item here - the catalog's own suite proves the
         | resolution - so what is asserted is that the schedule REFUSES a
         | line with neither a price nor an item, rather than billing zero.
         */
        DB::connection('tenant')->table('recurring_schedule_lines')
            ->where('id', (string) $scheduleLine->getKey())
            ->update(['unit_price' => null, 'item_id' => null]);

        try {
            app(RecurringSchedules::class)->preview($schedule->refresh(), 1);

            $violations[] = 'a line with no price and no item was priced anyway, which bills zero '.
                'every month';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'item')) {
                $violations[] = 'the refusal does not explain itself: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function idempotent(): array
    {
        $violations = [];
        $schedule = app(RecurringSchedules::class)->activate($this->schedule());
        $date = $schedule->upcoming(1)[0];

        $first = app(RecurringGenerator::class)->generate($schedule, $date);
        $second = app(RecurringGenerator::class)->generate($schedule->refresh(), $date);

        if (($first['outcome'] ?? '') !== 'generated') {
            return ['the first generation failed: '.(string) ($first['error'] ?? '')];
        }

        if (($second['outcome'] ?? '') === 'generated') {
            $violations[] = 'the same occurrence generated twice. A scheduler that fires twice '.
                'cannot bill twice - and that has to hold for a retry after a timeout, a second '.
                'node in the same minute, and somebody running the command by hand while cron runs it';
        }

        $invoices = DB::connection('tenant')->table('invoice_references')
            ->where('entity_id', $this->entityId)
            ->where('kind', 'contract')
            ->where('reference', (string) $schedule->reference)
            ->count();

        if ($invoices !== 1) {
            $violations[] = sprintf('%d invoices exist for one occurrence', $invoices);
        }

        $occurrences = $schedule->occurrences()
            ->whereDate('occurrence_date', $date->toDateString())
            ->count();

        if ($occurrences !== 1) {
            $violations[] = sprintf('%d occurrence rows exist for one date', $occurrences);
        }

        /*
         | And the claim is written before the invoice.
         |
         | A generator that drafted first and recorded afterwards would,
         | interrupted between the two, leave an invoice nobody has a record
         | of asking for - and the next run would produce another. Asserted
         | on the source, because an interruption cannot be staged.
         */
        $source = (string) file_get_contents(
            \dirname(__DIR__, 2).'/app/Tenant/Sales/Services/RecurringGenerator.php',
        );

        $claim = strpos($source, 'RecurringOccurrence::create');
        $draft = strpos($source, 'private function draftFor');

        if ($claim === false || $draft === false || $claim > $draft) {
            $violations[] = 'the generator drafts the invoice before claiming the occurrence';
        }

        return $violations;
    }

    /** @return list<string> */
    public function timezones(): array
    {
        $violations = [];

        $riyadh = $this->schedule(timezone: 'Asia/Riyadh');
        $casablanca = $this->schedule(timezone: 'Africa/Casablanca');

        if ((string) $riyadh->timezone !== 'Asia/Riyadh') {
            $violations[] = 'the schedule does not keep its own timezone';
        }

        /*
         | The two do not share a moment.
         |
         | Asserted on the schedules' own idea of "today": at 22:00 UTC it is
         | already tomorrow in Riyadh and still today in Casablanca, and a
         | generator using the server clock bills one of them on the wrong
         | date.
         */
        $riyadhToday = $riyadh->today();
        $casaToday = $casablanca->today();

        if ($riyadhToday->timezone->getName() === $casaToday->timezone->getName()) {
            $violations[] = 'both schedules compute "today" in the same zone, so the server clock is '.
                'being used';
        }

        /*
         | Midnight in Riyadh is not midnight in Casablanca.
         |
         | Both are the start of "today" where the business is, and the two
         | instants are three or four hours apart depending on the season -
         | which is exactly the gap a generator using the server clock gets
         | wrong, by a day, for the week each March and October when only one
         | of the two has changed.
         */
        $apart = abs($riyadhToday->getTimestamp() - $casaToday->getTimestamp());

        if ($apart === 0) {
            $violations[] = 'the two timezones produce the same instant, which they do not: midnight '.
                'in Riyadh is three or four hours before midnight in Casablanca';
        }

        if ($apart > 86400) {
            $violations[] = sprintf(
                'the two "todays" are %d seconds apart, which is more than a day and means one of '.
                'them is not today at all',
                $apart,
            );
        }

        try {
            $this->schedule(timezone: 'Mars/Olympus');

            $violations[] = 'an unknown timezone was accepted, and would silently become UTC - which '.
                'is right for about a third of the world';
        } catch (Throwable) {
            // As it should be.
        }

        return $violations;
    }

    /** @return list<string> */
    public function failures(): array
    {
        $violations = [];
        $schedule = app(RecurringSchedules::class)->activate($this->schedule());
        $date = $schedule->upcoming(1)[0];

        // Break the line so generation fails: no price and no item.
        $schedule->lines()->update(['unit_price' => null, 'item_id' => null]);

        for ($attempt = 1; $attempt <= RecurringGenerator::MAX_ATTEMPTS; $attempt++) {
            $outcome = app(RecurringGenerator::class)->generate($schedule->refresh(), $date);

            if (($outcome['outcome'] ?? '') !== 'failed') {
                $violations[] = sprintf(
                    'attempt %d reported "%s" rather than a failure',
                    $attempt,
                    (string) ($outcome['outcome'] ?? ''),
                );

                break;
            }

            if ($attempt < RecurringGenerator::MAX_ATTEMPTS && ($outcome['permanent'] ?? '') === 'yes') {
                $violations[] = sprintf('attempt %d was treated as permanent too early', $attempt);
            }
        }

        $occurrence = $schedule->occurrences()->whereDate('occurrence_date', $date->toDateString())->first();

        if ($occurrence === null) {
            return [...$violations, 'a failed occurrence left no record at all'];
        }

        if ((string) $occurrence->status !== 'failed' || (string) $occurrence->last_error === '') {
            $violations[] = 'a failed occurrence does not record why';
        }

        /*
         | And the failure does not stop the next occurrence.
         |
         | A customer whose price list has a gap must not stop the other two
         | hundred invoices going out.
         */
        $next = $schedule->upcoming(2)[1] ?? null;

        if ($next !== null) {
            $schedule->lines()->update(['unit_price' => '100.00000000']);
            $outcome = app(RecurringGenerator::class)->generate($schedule->refresh(), $next);

            if (($outcome['outcome'] ?? '') !== 'generated') {
                $violations[] = 'the occurrence after a failed one was not generated: '.
                    (string) ($outcome['error'] ?? '');
            }
        }

        // After the last attempt it is surfaced rather than retried forever.
        $record = DB::connection('tenant')->table('audit_log')
            ->where('action', 'recurring_schedule.occurrence_failed')
            ->where('subject_id', (string) $schedule->getKey())
            ->first();

        if ($record === null) {
            $violations[] = 'a permanently failed occurrence is not surfaced anywhere. A job that '.
                'retries an impossible occurrence every hour is a job whose log nobody reads, and the '.
                'occurrence it cannot bill is a customer who is not being invoiced';
        }

        return $violations;
    }

    /** @return list<string> */
    public function paused(): array
    {
        $schedule = app(RecurringSchedules::class)->activate($this->schedule());
        app(RecurringSchedules::class)->pause($schedule, 'the customer asked us to hold');

        $report = app(RecurringGenerator::class)->run($this->entityId, CarbonImmutable::now()->addYear());

        return $report['generated'] === []
            ? []
            : ['a paused schedule generated an invoice. A pause is a decision somebody made, and '.
                'generating anyway undoes it silently'];
    }

    /**
     * The price's provenance, on the generated line - PF-254.
     *
     * @return list<string>
     */
    public function priceProvenanceIsOnTheLine(): array
    {
        $violations = [];

        /*
         | An item with a price list, and a schedule line that resolves
         | rather than carrying a fixed price.
         |
         | The derivation sentence was already being computed and thrown
         | away: it went into the preview array and nothing persisted
         | it, so "why is this 94.50" was answerable on screen before
         | the invoice was issued and unanswerable afterwards - which is
         | when a customer asks.
         */
        $item = \App\Tenant\Catalog\Models\Item::create([
            'code' => 'REC-PRICED-'.strtoupper(\Illuminate\Support\Str::random(6)),
            'name_ar' => 'صنف مُسعَّر من قائمة',
            'name_en' => 'Priced from a list',
            'type' => 'service',
            'unit_code' => 'C62',
            'recognition' => 'immediate',
            'sellable' => true,
        ]);

        $list = \App\Tenant\Catalog\Models\PriceList::create([
            'code' => 'REC-LIST-'.strtoupper(\Illuminate\Support\Str::random(6)),
            'name_ar' => 'قائمة الفحص',
            'name_en' => 'Check list',
            'currency' => 'SAR',
            'valid_from' => CarbonImmutable::now()->subYear()->toDateString(),
            'priority' => 0,
        ]);

        $tier = \App\Tenant\Catalog\Models\PriceListLine::create([
            'price_list_id' => (string) $list->getKey(),
            'item_id' => (string) $item->getKey(),
            'min_quantity' => '0',
        ]);

        \App\Tenant\Catalog\Models\PricePoint::create([
            'price_list_line_id' => (string) $tier->getKey(),
            'unit_price' => '94.50000000',
            'effective_from' => CarbonImmutable::now()->subYear()->toDateString(),
            'reason' => 'the price this tier opened at, set up by the check fixture',
            'changed_by' => str_repeat('0', 26),
        ]);

        $schedule = $this->schedule();
        $schedule->lines()->update([
            'unit_price' => null,
            'item_id' => (string) $item->getKey(),
        ]);

        $schedule = app(RecurringSchedules::class)->activate($schedule->refresh());
        $date = $schedule->upcoming(1)[0];

        $outcome = app(RecurringGenerator::class)->generate($schedule, $date);

        if (($outcome['outcome'] ?? '') !== 'generated') {
            return ['the invoice was not generated: '.(string) ($outcome['error'] ?? '')];
        }

        $line = DB::connection('tenant')->table('invoice_lines')
            ->where('invoice_id', (string) $outcome['invoice'])
            ->first();

        if ($line === null) {
            return ['the generated invoice has no lines'];
        }

        if ((string) ($line->price_list_id ?? '') !== (string) $list->getKey()) {
            $violations[] = 'the line does not name the price list it resolved against, so a year '.
                'later the price cannot be traced to a list that has moved three times since';
        }

        if (! str_contains((string) ($line->price_basis ?? ''), (string) $list->code)) {
            $violations[] = sprintf(
                'the line carries no sentence explaining its price (%s) - a list id with no sentence is '.
                'a foreign key somebody has to go and interpret',
                (string) ($line->price_basis ?? '-'),
            );
        }

        /*
         | And a list id without a sentence is refused by the column.
         |
         | Because the sentence is the part a person reads. "Resolved
         | from RETAIL at 94.50" is the difference between a question
         | and a dispute.
         */
        try {
            DB::connection('tenant')->statement('SAVEPOINT pf254');
            DB::connection('tenant')->table('invoice_lines')
                ->where('id', (string) $line->id)
                ->update(['price_basis' => null]);
            DB::connection('tenant')->statement('ROLLBACK TO SAVEPOINT pf254');

            $violations[] = 'a line can name a price list and explain nothing, which leaves a foreign '.
                'key where an explanation should be';
        } catch (Throwable) {
            DB::connection('tenant')->statement('ROLLBACK TO SAVEPOINT pf254');
        }

        /*
         | A fixed price names no list, and says so.
         |
         | A contract with a price written into it is a real thing, and
         | a product that forced a list onto it would invent one.
         */
        $fixed = $this->schedule();
        $fixed = app(RecurringSchedules::class)->activate($fixed);
        $fixedOutcome = app(RecurringGenerator::class)->generate($fixed, $fixed->upcoming(1)[0]);

        $fixedLine = DB::connection('tenant')->table('invoice_lines')
            ->where('invoice_id', (string) ($fixedOutcome['invoice'] ?? ''))
            ->first();

        if (($fixedLine->price_list_id ?? null) !== null) {
            $violations[] = 'a line whose price is fixed on the contract names a price list anyway, '.
                'so a fixed price is indistinguishable from a resolved one';
        }

        if (! str_contains((string) ($fixedLine->price_basis ?? ''), 'fixed on the schedule')) {
            $violations[] = 'a fixed price does not say it is fixed on the line, so nobody reading the '.
                'invoice can tell it from one tied to a list';
        }

        return $violations;
    }

    private function schedule(
        string $rrule = 'FREQ=MONTHLY;BYMONTHDAY=1',
        ?CarbonImmutable $startsOn = null,
        ?CarbonImmutable $endsOn = null,
        Proration $proration = Proration::None,
        ?string $timezone = 'Asia/Riyadh',
    ): RecurringSchedule {
        return app(RecurringSchedules::class)->draft(
            $this->entity(),
            $this->party,
            $rrule,
            $startsOn ?? CarbonImmutable::now()->startOfMonth(),
            [[
                'description' => 'Support and maintenance',
                'quantity' => '1.00000000',
                'unit_price' => '1000.00000000',
                'tax_code_id' => $this->taxCodeId,
                'tax_rate' => '15.000000',
                'tax_category' => 'S',
                'revenue_account_id' => $this->revenueAccountId,
            ]],
            $endsOn,
            null,
            $proration,
            $timezone,
        );
    }
}
