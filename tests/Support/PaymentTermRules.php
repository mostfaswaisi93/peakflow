<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Catalog\Models\PaymentTermInstalment;
use App\Tenant\Catalog\Services\DueDateCalculator;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What payment terms produce - PF-066.
 *
 * ## The two mistakes this is shaped against
 *
 * **"Net 30" and "end of month plus 30" treated as the same term.** They
 * differ by up to a month, both get written as "30 days" in conversation, and
 * the difference surfaces as an aging report disagreeing with the customer's
 * own ledger. So the derivation is returned with the date, and these checks
 * assert the two terms produce different answers from the same invoice.
 *
 * **A hard-coded Saturday-Sunday weekend.** Friday and Saturday are the
 * weekend across much of the Gulf, which is where this product is used, and
 * a due date on a day the bank is shut makes an invoice overdue for reasons
 * that are nobody's fault.
 *
 * ## One instalment schedule that does not add up
 *
 * A schedule summing to 0.99 invoices 99% of the total and leaves 1% that is
 * never billed and never chased. It balances, it ages correctly, and nobody
 * notices until the customer closes their account - which is why a deferred
 * constraint trigger enforces the sum rather than a form validator.
 */
final class PaymentTermRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private Carbon $invoiceDate;

    public function __construct()
    {
        // Tuesday 10 March 2026, chosen because its month end (31 March) is
        // a Tuesday too, so the weekend adjustments below are unambiguous.
        $this->invoiceDate = Carbon::parse('2026-03-10');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['payment terms (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return [
                'net and end-of-month are not the same term' => $this->termKindsDiffer(),
                'a day that does not exist in the month is clamped, not rolled' => $this->shortMonthsAreClamped(),
                "the weekend is the entity's, not the calendar's" => $this->weekendIsAPolicy(),
                'an instalment schedule must account for the whole invoice' => $this->instalmentsAddUp(),
                'a discount term is complete or absent' => $this->discountsAreComplete(),
            ];
        } catch (Throwable $e) {
            return ['payment terms could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function termKindsDiffer(): array
    {
        $calculator = app(DueDateCalculator::class);
        $violations = [];

        $net = $calculator->for($this->term('NET30'), $this->invoiceDate, $this->entityId);
        $eom = $calculator->for($this->term('EOM30'), $this->invoiceDate, $this->entityId);

        if ($net['due_dates'][0]['date'] !== '2026-04-09') {
            $violations[] = sprintf(
                'net 30 from 10 March came out as %s rather than 9 April',
                $net['due_dates'][0]['date'],
            );
        }

        if ($eom['due_dates'][0]['date'] !== '2026-04-30') {
            $violations[] = sprintf(
                'end of month plus 30 from 10 March came out as %s rather than 30 April. The order matters: '.
                'month end THEN add days. Adding days first and taking the month end produces a date up to a '.
                'month later, and both are written as "EOM 30" in practice',
                $eom['due_dates'][0]['date'],
            );
        }

        if ($net['due_dates'][0]['date'] === $eom['due_dates'][0]['date']) {
            $violations[] = 'net 30 and end-of-month-plus-30 produced the same due date, so the two terms are '.
                'being treated as one - a difference of up to a month, which shows up as an aging report '.
                'that disagrees with the customer\'s own ledger';
        }

        /*
         | A SECOND date, chosen because the two orders disagree on it.
         |
         | From 10 March the two give the same answer by coincidence: month
         | end (31 March) plus 30 is 30 April, and 10 March plus 30 (9 April)
         | taken to its month end is also 30 April. Reversing the order
         | passed this check unchanged, which made it a check about nothing.
         |
         | From 1 March they differ: month end (31 March) plus 30 is 30
         | April, while 1 March plus 30 (31 March) taken to its month end is
         | 31 March - a month apart.
         */
        $firstOfMarch = $calculator->for($this->term('EOM30'), Carbon::parse('2026-03-01'), $this->entityId);

        if ($firstOfMarch['due_dates'][0]['date'] !== '2026-04-30') {
            $violations[] = sprintf(
                'end of month plus 30 from 1 March came out as %s rather than 30 April. The order is month '.
                'end THEN add days; doing it the other way round gives 31 March, a month earlier, and the '.
                'derivation still reads correctly while the date does not',
                $firstOfMarch['due_dates'][0]['date'],
            );
        }

        /*
         | The derivation, not just the date.
         |
         | "Due 2026-04-30" is a date somebody disputes, and the dispute is
         | about the method: the customer counted 30 days and got the 24th.
         | One line saying which method was used ends it.
         */
        foreach ([
            [$net, '2026-03-10 plus 30 days'],
            [$eom, 'end of the invoice month (2026-03-31) plus 30 days'],
        ] as [$result, $expected]) {
            if (! str_contains((string) $result['due_dates'][0]['derivation'], $expected)) {
                $violations[] = sprintf(
                    'a due date was returned without saying how it was reached; expected to see "%s", got "%s"',
                    $expected,
                    (string) $result['due_dates'][0]['derivation'],
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function shortMonthsAreClamped(): array
    {
        $calculator = app(DueDateCalculator::class);

        $term = PaymentTerm::create([
            'code' => 'CHK31-'.Str::random(6),
            'name_ar' => 'اليوم 31',
            'name_en' => 'Day 31 of the following month',
            'kind' => 'fixed_day',
            'day_of_month' => 31,
            'months_forward' => 1,
        ]);

        // January plus one month is February, which has 28 days in 2026.
        $result = $calculator->for($term, Carbon::parse('2026-01-15'), $this->entityId);
        $derivation = (string) $result['due_dates'][0]['derivation'];

        if (! str_contains($derivation, 'from 2026-02-28')) {
            return [sprintf(
                'a term of "the 31st" in a 28-day month produced %s. Carbon rolls a day past the month end '.
                'into the next month, so the 31st of February becomes the 3rd of March - a due date in the '.
                'wrong month, which ages into the wrong bucket. Derivation: %s',
                (string) $result['due_dates'][0]['date'],
                $derivation,
            )];
        }

        if (! str_contains($derivation, 'clamped')) {
            return ['the day was clamped without saying so, so a due date that is not the 31st looks like a bug'];
        }

        return [];
    }

    /** @return list<string> */
    public function weekendIsAPolicy(): array
    {
        $calculator = app(DueDateCalculator::class);
        $violations = [];

        // 10 March plus 32 days is Saturday 11 April 2026.
        $term = PaymentTerm::create([
            'code' => 'CHKW-'.Str::random(6),
            'name_ar' => 'عطلة',
            'name_en' => 'Weekend probe',
            'kind' => 'net',
            'days' => 32,
        ]);

        /*
         | With no policy row, the Gulf weekend is the default.
         |
         | Friday and Saturday, because that is the working week in the
         | markets this product is built for. A Saturday-Sunday default
         | produces due dates on days the bank is shut in exactly those
         | markets, which is the mistake worth defaulting away from.
         */
        $default = $calculator->for($term, $this->invoiceDate, $this->entityId);

        if ($default['due_dates'][0]['date'] !== '2026-04-12') {
            $violations[] = sprintf(
                'with no policy, a due date falling on Saturday 11 April came out as %s. The default weekend '.
                'should be Friday and Saturday, so it moves to Sunday the 12th',
                $default['due_dates'][0]['date'],
            );
        }

        $this->setPolicy([6, 7], 'forward');

        $western = $calculator->for($term, $this->invoiceDate, $this->entityId);

        if ($western['due_dates'][0]['date'] !== '2026-04-13') {
            $violations[] = sprintf(
                'under a Saturday-Sunday weekend the same due date came out as %s rather than Monday the '.
                '13th, so the policy is not being read',
                $western['due_dates'][0]['date'],
            );
        }

        $this->setPolicy([5, 6], 'backward');

        $backward = $calculator->for($term, $this->invoiceDate, $this->entityId);

        /*
         | Thursday 9 April, not Friday the 10th.
         |
         | Moving backward from Saturday 11 April under a Friday-Saturday
         | weekend has to skip BOTH days - the Friday is a weekend day too.
         | This check first expected the Friday, which is the answer under a
         | Saturday-Sunday week and the kind of mistake the whole policy
         | exists to prevent.
         |
         | Backward exists because some contracts require it: a payment due
         | "by" month end cannot move into the next month.
         */
        if ($backward['due_dates'][0]['date'] !== '2026-04-09') {
            $violations[] = sprintf(
                'a backward policy produced %s rather than Thursday 9 April - moving back from Saturday has '.
                'to skip the Friday too, under a Friday-Saturday weekend',
                $backward['due_dates'][0]['date'],
            );
        }

        /*
         | A holiday is a row, not a rule.
         |
         | Eid moves against the Gregorian calendar and its observed dates
         | are announced, sometimes days beforehand. A system that computes
         | them is wrong in the years the announcement differs, which is most
         | of them.
         */
        $this->setPolicy([5, 6], 'forward');

        $this->connection->table('holidays')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'date' => '2026-04-12',
            'name' => 'Observed holiday',
            'confirmed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $withHoliday = $calculator->for($term, $this->invoiceDate, $this->entityId);

        if ($withHoliday['due_dates'][0]['date'] !== '2026-04-13') {
            $violations[] = sprintf(
                'a due date landing on a weekend followed by a holiday came out as %s rather than moving past '.
                'both to the 13th',
                $withHoliday['due_dates'][0]['date'],
            );
        }

        // Every day a weekend leaves nowhere to move a date to.
        $everyDay = $this->refusal(fn () => $this->connection->table('business_day_policies')
            ->where('entity_id', $this->entityId)
            ->update(['weekend_days' => json_encode([1, 2, 3, 4, 5, 6, 7])]));

        if ($everyDay === null) {
            $violations[] = 'a weekend of all seven days was accepted, which leaves no business day to move a '.
                'due date to';
        }

        return $violations;
    }

    /** @return list<string> */
    public function instalmentsAddUp(): array
    {
        $calculator = app(DueDateCalculator::class);
        $violations = [];

        $term = PaymentTerm::create([
            'code' => 'CHK3-'.Str::random(6),
            'name_ar' => 'أثلاث',
            'name_en' => 'Thirds',
            'kind' => 'instalments',
        ]);

        foreach ([
            [1, '0.333333333333', 'immediate', 0],
            [2, '0.333333333333', 'net', 30],
            [3, '0.333333333334', 'net', 60],
        ] as [$sequence, $proportion, $kind, $days]) {
            PaymentTermInstalment::create([
                'payment_term_id' => $term->getKey(),
                'sequence' => $sequence,
                'proportion' => $proportion,
                'kind' => $kind,
                'days' => $days,
            ]);
        }

        $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');

        $plan = $calculator->for(
            $term->fresh(),
            $this->invoiceDate,
            $this->entityId,
            Money::of('1000.00', 'SAR'),
        );

        if (count($plan['due_dates']) !== 3) {
            return [sprintf(
                'an instalment term produced %d due date(s) rather than three. Each instalment is separately '.
                'dunnable and separately allocatable, so collapsing them loses which one a payment settled',
                count($plan['due_dates']),
            )];
        }

        $sum = Money::zero('SAR');

        foreach ($plan['due_dates'] as $instalment) {
            $sum = $sum->plus(Money::of((string) $instalment['amount'], 'SAR'));
        }

        if (! $sum->equals(Money::of('1000.00', 'SAR'))) {
            $violations[] = sprintf(
                'three instalments of a 1,000.00 invoice sum to %s. Something has to carry the residual cent; '.
                'spreading it invents fractions of a cent and dropping it leaves a cent nobody chases',
                (string) $sum->amount,
            );
        }

        if ((string) $plan['due_dates'][2]['amount'] !== '333.3334') {
            $violations[] = sprintf(
                'the residual landed on instalment 3 as %s rather than 333.3334 - it belongs on the LAST '.
                'instalment, which is the one a customer queries least and an allocation reaches last',
                (string) $plan['due_dates'][2]['amount'],
            );
        }

        /*
         | And a schedule that does not account for the whole invoice.
         |
         | 0.5 plus 0.49 invoices 99% and leaves 1% never billed and never
         | chased. It balances, it ages correctly, and nobody notices until
         | the customer closes their account.
         */
        $short = $this->refusal(function (): void {
            $incomplete = PaymentTerm::create([
                'code' => 'CHKBAD-'.Str::random(6),
                'name_ar' => 'ناقص',
                'name_en' => 'Short schedule',
                'kind' => 'instalments',
            ]);

            PaymentTermInstalment::create([
                'payment_term_id' => $incomplete->getKey(),
                'sequence' => 1, 'proportion' => '0.5', 'kind' => 'net', 'days' => 0,
            ]);

            PaymentTermInstalment::create([
                'payment_term_id' => $incomplete->getKey(),
                'sequence' => 2, 'proportion' => '0.49', 'kind' => 'net', 'days' => 30,
            ]);

            // The sum trigger is deferred, so it fires here rather than at
            // the savepoint's end - the same reason the balanced-entry
            // check does.
            $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        if ($short === null) {
            $violations[] = 'an instalment schedule summing to 0.99 was accepted, so 1% of every invoice on '.
                'those terms is never billed';
        }

        return $violations;
    }

    /** @return list<string> */
    public function discountsAreComplete(): array
    {
        $violations = [];

        $halfATerm = $this->refusal(fn () => $this->connection->table('payment_terms')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'CHKHALF-'.Str::random(6),
            'name_ar' => 'نصف',
            'name_en' => 'Half a discount',
            'kind' => 'net',
            'days' => 30,
            'discount_percent' => '2',
            'discount_days' => null,
            'reference_date_code' => '3',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($halfATerm === null) {
            $violations[] = 'a discount percentage with no window was accepted. Half a term is worse than '.
                'none, and the missing half is the one the customer will assume in their favour';
        }

        $tooGenerous = $this->refusal(fn () => $this->connection->table('payment_terms')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'CHKFREE-'.Str::random(6),
            'name_ar' => 'مجاني',
            'name_en' => 'Too generous',
            'kind' => 'net',
            'days' => 30,
            'discount_percent' => '120',
            'discount_days' => 5,
            'reference_date_code' => '3',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($tooGenerous === null) {
            $violations[] = 'a discount of 120% was accepted, which pays the customer to buy';
        }

        /*
         | And the percentage must never pass through a float.
         |
         | `numeric(21,12)` read back as a float and multiplied is how a 2%
         | discount on a large invoice ends up a hundredth of a currency
         | unit out, repeatedly and in one direction.
         */
        $percent = PaymentTerm::query()->where('code', 'NET30-2-10')->value('discount_percent');

        if (! is_string($percent)) {
            $violations[] = sprintf(
                'the discount percentage comes back as %s rather than a decimal string, so it has been '.
                'through a float on the way',
                get_debug_type($percent),
            );
        }

        return $violations;
    }

    /** @param list<int> $weekend */
    private function setPolicy(array $weekend, string $adjustment): void
    {
        $this->connection->table('business_day_policies')->updateOrInsert(
            ['entity_id' => $this->entityId],
            [
                'id' => (string) Str::ulid(),
                'weekend_days' => json_encode($weekend),
                'adjustment' => $adjustment,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function term(string $code): PaymentTerm
    {
        /** @var PaymentTerm $term */
        $term = PaymentTerm::query()->where('code', $code)->firstOrFail();

        return $term;
    }

    /**
     * A write expected to be refused, in its own savepoint.
     *
     * A refused statement aborts the whole transaction, so without the
     * savepoint the first refusal makes every later check report "current
     * transaction is aborted".
     */
    private function refusal(callable $write): ?string
    {
        try {
            $this->connection->transaction(function () use ($write): void {
                $write();

                throw new TermWriteAccepted();
            });
        } catch (TermWriteAccepted) {
            return null;
        } catch (Throwable $e) {
            return (string) preg_replace('/\s+/', ' ', $e->getMessage());
        }

        return null;
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'TRM'.random_int(100, 999),
            'legal_name' => 'Payment terms check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}

/** Thrown to roll back a write the database ACCEPTED. */
final class TermWriteAccepted extends \RuntimeException {}
