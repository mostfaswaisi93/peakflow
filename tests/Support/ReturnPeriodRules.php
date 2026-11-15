<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tax\Enums\FilingFrequency;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\ReturnFormUnusable;
use App\Tenant\Tax\Exceptions\ReturnPeriodRefused;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\Services\ReturnBoxes;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Return periods and box mapping - PF-085.
 *
 * ## The scenario these are built around
 *
 * One Saudi entity, registered for VAT in Saudi Arabia and for general sales
 * tax in Jordan, filing monthly in one and quarterly in the other, while its
 * own books close monthly. Three different calendars over one set of
 * documents, which is ordinary and is what makes deriving a return period from
 * an accounting period wrong.
 *
 * ## The failure that is worth the most
 *
 * A document whose tax point falls in a filed period, quietly reported in the
 * next one. It is silent, both returns are mis-stated, nobody chose it and
 * nothing records it - and it is the behaviour a system drifts into, because
 * refusing a document feels unhelpful and moving it feels like a fix.
 */
final class ReturnPeriodRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private string $otherEntityId = '';

    private ?Entity $entity = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['return periods (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->runEach([
                'the registration\'s frequency decides the windows' => fn (): array => $this->frequencyDecides(),
                'one entity files monthly in one jurisdiction and quarterly in another' => fn (): array => $this->twoCalendarsAtOnce(),
                'a document falls into a period by its tax point' => fn (): array => $this->byTheTaxPoint(),
                'a tax point in a filed period is refused, not moved' => fn (): array => $this->aFiledPeriodRefuses(),
                'a filed period is immutable, enforced by the database' => fn (): array => $this->filedIsImmutable(),
                'generation refuses without a declared frequency or a registration' => fn (): array => $this->generationRefuses(),
                'overlapping periods are refused by the database' => fn (): array => $this->overlapsAreRefused(),
                'every form has boxes with a side, both labels and a source' => fn (): array => $this->theFormsAreUsable(),
                'every tax code maps to a box that exists on its own form' => fn (): array => $this->codesMapToBoxes(),
                'the next open period is where an adjustment will land' => fn (): array => $this->theNextOpenPeriod(),
                'another entity cannot see these periods' => fn (): array => $this->rowLevelSecurityApplies(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function frequencyDecides(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);

        $sa = $periods->generateYear($this->entity, 'SA', 2026);
        $jo = $periods->generateYear($this->entity, 'JO', 2026);

        if (\count($sa) !== 12) {
            $violations[] = sprintf('a monthly registration generated %d periods, not 12', \count($sa));
        }

        if (\count($jo) !== 4) {
            $violations[] = sprintf('a quarterly registration generated %d periods, not 4', \count($jo));
        }

        $labels = array_map(static fn (ReturnPeriod $p): string => $p->label, $jo);

        if ($labels !== ['2026-Q1', '2026-Q2', '2026-Q3', '2026-Q4']) {
            $violations[] = 'the quarterly labels are '.implode(', ', $labels);
        }

        /*
         | The windows abut exactly and leave no gap.
         |
         | A day in neither period is a day whose documents cannot be
         | reported at all, and a day in both is a document counted twice.
         */
        $previous = null;

        foreach ($sa as $period) {
            if ($previous !== null && $period->startsOn()->toDateString() !== $previous->toDateString()) {
                $violations[] = sprintf(
                    'the monthly periods do not abut: one ends before %s and the next starts on %s',
                    $previous->toDateString(), $period->startsOn()->toDateString(),
                );
            }

            $previous = $period->endsBefore();
        }

        if ($previous === null || $previous->toDateString() !== '2027-01-01') {
            $violations[] = sprintf(
                'the year\'s last period ends before %s, and should end before 2027-01-01',
                $previous?->toDateString() ?? 'nothing',
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function twoCalendarsAtOnce(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);

        $periods->generateYear($this->entity, 'SA', 2026);
        $periods->generateYear($this->entity, 'JO', 2026);

        $date = CarbonImmutable::parse('2026-05-15');

        $sa = $periods->forTaxPoint($this->entityId, 'SA', $date);
        $jo = $periods->forTaxPoint($this->entityId, 'JO', $date);

        if ($sa?->label !== '2026-05' || $jo?->label !== '2026-Q2') {
            $violations[] = sprintf(
                'one supply on %s fell into "%s" in SA and "%s" in JO, and should fall into 2026-05 and '.
                '2026-Q2. Monthly VAT on an entity that files quarterly elsewhere is ordinary, and '.
                'deriving either window from the accounting calendar gets one of them wrong',
                $date->toDateString(), $sa?->label ?? 'nothing', $jo?->label ?? 'nothing',
            );
        }

        if ($sa !== null && $jo !== null && $sa->frequency === $jo->frequency) {
            $violations[] = 'both registrations came out with the same frequency, so the frequency is '.
                'not being read from the registration';
        }

        return $violations;
    }

    /** @return list<string> */
    public function byTheTaxPoint(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);
        $periods->generateYear($this->entity, 'SA', 2026);

        /*
         | The case the whole design turns on.
         |
         | A document raised on 1 June for a supply delivered on 30 May: the
         | tax point is in May and the accounting date is in June. It belongs
         | to May's RETURN and June's accounting period, and a system that
         | used one date for both questions reports it in June.
         */
        $cases = [
            ['2026-05-30', '2026-05'],
            ['2026-06-01', '2026-06'],
            ['2026-05-01', '2026-05'],
            ['2026-05-31', '2026-05'],
        ];

        foreach ($cases as [$taxPoint, $expected]) {
            $period = $periods->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse($taxPoint));

            if ($period?->label !== $expected) {
                $violations[] = sprintf(
                    'a tax point of %s fell into "%s" and should fall into %s',
                    $taxPoint, $period?->label ?? 'nothing', $expected,
                );
            }
        }

        // A date outside every generated period is refused rather than guessed.
        try {
            $periods->assertReportable($this->entityId, 'SA', CarbonImmutable::parse('2027-03-01'));

            $violations[] = 'a tax point in a year with no periods was accepted. A period created by '.
                'whichever document arrived first takes its frequency from a guess, and that frequency '.
                'decides the window every later document falls into';
        } catch (ReturnPeriodRefused) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function aFiledPeriodRefuses(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);
        $periods->generateYear($this->entity, 'SA', 2026);

        $may = $periods->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse('2026-05-15'));

        if ($may === null) {
            return ['May did not generate'];
        }

        $periods->file($may, str_repeat('0', 26), 'SUB-1');

        try {
            $accepted = $periods->assertReportable(
                $this->entityId, 'SA', CarbonImmutable::parse('2026-05-20'),
            );

            $violations[] = sprintf(
                'a tax point inside a filed period was accepted into "%s". If that is the next open '.
                'period, both returns are now mis-stated - and nothing records that a supply was moved '.
                'between them',
                $accepted->label,
            );
        } catch (ReturnPeriodRefused $e) {
            if (! str_contains($e->getMessage(), 'already been filed')) {
                $violations[] = 'a filed period refused for the wrong reason: '.$e->getMessage();
            }

            if (! str_contains($e->getMessage(), 'PF-087')) {
                $violations[] = 'the refusal does not say what to do instead, so the next person moves '.
                    'the document by hand: '.$e->getMessage();
            }
        }

        // And June is still open.
        try {
            $june = $periods->assertReportable($this->entityId, 'SA', CarbonImmutable::parse('2026-06-10'));

            if ($june->label !== '2026-06') {
                $violations[] = 'a June tax point did not land in June';
            }
        } catch (Throwable $e) {
            $violations[] = 'filing May closed June as well: '.$e->getMessage();
        }

        // Filing twice is an amendment, not a filing.
        try {
            $periods->file($may->refresh(), str_repeat('0', 26), 'SUB-2');
            $violations[] = 'the same period was filed twice. A second filing is an amendment, which is '.
                'a different legal act with its own reference';
        } catch (ReturnPeriodRefused) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function filedIsImmutable(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);
        $periods->generateYear($this->entity, 'SA', 2026);

        $may = $periods->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse('2026-05-15'));
        \assert($may !== null);
        $periods->file($may, str_repeat('0', 26), 'SUB-1');

        $attempts = [
            'moving the window' => ['period' => ReturnPeriod::range(
                CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-07-01'),
            )],
            'relabelling it' => ['label' => '2026-06'],
            'changing the frequency' => ['frequency' => 'quarterly'],
            'reopening it' => ['state' => 'open', 'filed_at' => null, 'filed_by' => null],
        ];

        foreach ($attempts as $what => $changes) {
            $savepoint = 'rtn_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                ReturnPeriod::query()->whereKey($may->getKey())->update($changes);

                $violations[] = sprintf(
                    '%s on a filed period succeeded. The figures that were filed would no longer '.
                    'correspond to any period, so nobody could explain them',
                    $what,
                );
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), 'filed')) {
                    $violations[] = sprintf('%s was refused by something else: %s', $what, $e->getMessage());
                }
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        /*
         | Enforced by the database, not by the model.
         |
         | A correction script, a migration and a psql session all write rows
         | the same way, and the trigger is the only thing standing between
         | them and a filed return whose window has moved.
         */
        $trigger = DB::connection('tenant')->selectOne(
            "select tgname from pg_trigger where tgrelid = 'return_periods'::regclass
               and tgname = 'return_periods_are_immutable_once_filed'",
        );

        if ($trigger === null) {
            $violations[] = 'the immutability is not a trigger, so it only holds for writes that go '.
                'through the model';
        }

        return $violations;
    }

    /** @return list<string> */
    public function generationRefuses(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);

        // A registration with no declared frequency.
        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'EG',
            'scheme' => 'VAT',
            'identifier' => 'EG-99',
            'effective_from' => '2020-01-01',
            'is_primary' => false,
        ]);

        try {
            $periods->generateYear($this->entity, 'EG', 2026);

            $violations[] = 'periods were generated for a registration that does not say how often it '.
                'files. Defaulting to monthly creates eleven periods nobody asked for and files the '.
                'first of them';
        } catch (ReturnPeriodRefused $e) {
            if (! str_contains($e->getMessage(), 'how often')) {
                $violations[] = 'a missing frequency was refused for the wrong reason: '.$e->getMessage();
            }
        }

        // A jurisdiction with no registration at all.
        try {
            $periods->generateYear($this->entity, 'FR', 2026);

            $violations[] = 'periods were generated for a jurisdiction this entity is not registered '.
                'in. That is a return nobody is obliged to submit and the authority would not accept';
        } catch (ReturnPeriodRefused) {
            // Correct.
        }

        // Generating the same year twice.
        $periods->generateYear($this->entity, 'SA', 2026);

        try {
            $periods->generateYear($this->entity, 'SA', 2026);

            $violations[] = 'the same year was generated twice. If the frequency changed, the existing '.
                'periods are the ones documents have already been reported in, and replacing them moves '.
                'supplies between returns';
        } catch (ReturnPeriodRefused) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function overlapsAreRefused(): array
    {
        \assert($this->entity !== null);
        app(ReturnPeriods::class)->generateYear($this->entity, 'SA', 2026);

        try {
            ReturnPeriod::create([
                'entity_id' => $this->entityId,
                'jurisdiction' => 'SA',
                'frequency' => 'quarterly',
                'label' => '2026-Q2',
                'state' => 'open',
                'period' => ReturnPeriod::range(
                    CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-07-01'),
                ),
            ]);
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'return_periods_no_overlap')
                ? []
                : ['an overlapping period was refused by something else: '.$e->getMessage()];
        }

        return ['a quarterly period was stored across three monthly ones. A document now falls into two '.
            'returns, and the second one filed double-counts it'];
    }

    /** @return list<string> */
    public function theFormsAreUsable(): array
    {
        $violations = [];
        $boxes = app(ReturnBoxes::class);
        $forms = config('tax_returns.returns');

        foreach (array_keys(\is_array($forms) ? $forms : []) as $form) {
            $form = (string) $form;

            try {
                $definition = $boxes->boxes($form);
            } catch (ReturnFormUnusable $e) {
                $violations[] = sprintf('the %s form is unusable: %s', $form, $e->getMessage());

                continue;
            }

            if ($boxes->boxesOnTheSide($form, 'output') === []) {
                $violations[] = sprintf('the %s form has no output box', $form);
            }

            if ($boxes->boxesOnTheSide($form, 'input') === []) {
                $violations[] = sprintf('the %s form has no input box', $form);
            }

            if ($boxes->boxesOnTheSide($form, 'net') === []) {
                $violations[] = sprintf(
                    'the %s form has no net box, so there is nothing that says what is owed', $form,
                );
            }

            $source = $boxes->sourceFor($form);

            if (! str_contains($source, 'unverified') && ! str_contains($source, 'published')
                && ! str_contains($source, 'EN 16931')) {
                $violations[] = sprintf(
                    'the %s form cites "%s", which names neither a published form nor the fact that the '.
                    'numbering is unverified', $form, $source,
                );
            }

            foreach (array_keys($definition) as $box) {
                foreach (['ar', 'en'] as $locale) {
                    if ($boxes->labelFor($form, (string) $box, $locale) === null) {
                        $violations[] = sprintf('box %s on %s has no %s label', $box, $form, $locale);
                    }
                }
            }
        }

        // And a jurisdiction with no form of its own falls back explicitly.
        if ($boxes->formFor('FR') !== 'generic') {
            $violations[] = 'a jurisdiction with no form of its own does not fall back to the generic one';
        }

        if ($boxes->formFor('SA') !== 'sa' || $boxes->formFor('JO') !== 'jo') {
            $violations[] = 'the shipped jurisdictions do not resolve their own forms';
        }

        return $violations;
    }

    /** @return list<string> */
    public function codesMapToBoxes(): array
    {
        $violations = [];
        $boxes = app(ReturnBoxes::class);

        $unmapped = $boxes->codesWithoutABox($this->entityId);

        if ($unmapped !== []) {
            $violations[] = 'a seeded code is unmapped, which the fixture does not intend: '.$unmapped[0];
        }

        /*
         | And the check has to FIND one.
         |
         | A method that returns an empty list because it looks at nothing
         | would pass the assertion above forever. So a code with no mapping
         | is added here and the check has to report it.
         */
        $savepoint = 'rtnbox_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            TaxCode::create([
                'entity_id' => $this->entityId,
                'code' => 'SA-UNMAPPED',
                'category' => TaxCategory::Standard->value,
                'jurisdiction' => 'SA',
                'label_ar' => 'بدون صندوق',
                'label_en' => 'With no box',
                'reporting_boxes' => [],
                'active' => true,
            ]);

            $found = $boxes->codesWithoutABox($this->entityId);

            if ($found === []) {
                $violations[] = 'a tax code with no box mapping was not reported. The code is complete, '.
                    'correct and unfileable, and the discovery happens on the day of a deadline';
            }

            // A code pointing at a box the form does not have.
            TaxCode::create([
                'entity_id' => $this->entityId,
                'code' => 'SA-WRONGBOX',
                'category' => TaxCategory::Standard->value,
                'jurisdiction' => 'SA',
                'label_ar' => 'صندوق غير موجود',
                'label_en' => 'A box that does not exist',
                'reporting_boxes' => ['sa' => ['output' => 'box_the_form_does_not_have']],
                'active' => true,
            ]);

            $found = $boxes->codesWithoutABox($this->entityId);

            if (! str_contains(implode(' ', $found), 'does not have')) {
                $violations[] = 'a code mapped to a box the form does not have was not reported. The '.
                    'figure would be computed and then dropped';
            }

            // And an output figure mapped to an input box.
            TaxCode::create([
                'entity_id' => $this->entityId,
                'code' => 'SA-CROSSED',
                'category' => TaxCategory::Standard->value,
                'jurisdiction' => 'SA',
                'label_ar' => 'جانب معكوس',
                'label_en' => 'The wrong side',
                'reporting_boxes' => ['sa' => ['output' => 'standard_purchases']],
                'active' => true,
            ]);

            $found = $boxes->codesWithoutABox($this->entityId);

            if (! str_contains(implode(' ', $found), 'refund request')) {
                $violations[] = 'output tax mapped to an input box was not reported, and that is a '.
                    'refund request rather than a mis-filing';
            }
        } catch (Throwable $e) {
            $violations[] = 'the box-mapping check could not be exercised: '.$e->getMessage();
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function theNextOpenPeriod(): array
    {
        $violations = [];
        $periods = app(ReturnPeriods::class);

        \assert($this->entity !== null);
        $periods->generateYear($this->entity, 'SA', 2026);

        $may = $periods->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse('2026-05-15'));
        \assert($may !== null);
        $periods->file($may, str_repeat('0', 26));

        $next = $periods->nextOpenAfter($may->refresh());

        if ($next?->label !== '2026-06') {
            $violations[] = sprintf(
                'the next open period after a filed May is "%s" and should be 2026-06. An adjustment to '.
                'a filed period lands there, so getting it wrong files the correction in the wrong return',
                $next?->label ?? 'nothing',
            );
        }

        // Filing June too moves the answer on.
        if ($next !== null) {
            $periods->file($next, str_repeat('0', 26));

            if ($periods->nextOpenAfter($may->refresh())?->label !== '2026-07') {
                $violations[] = 'with May and June filed, the next open period is not July';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function rowLevelSecurityApplies(): array
    {
        $violations = [];
        $context = app(EntityContext::class);

        \assert($this->entity !== null);
        app(ReturnPeriods::class)->generateYear($this->entity, 'SA', 2026);

        $context->use(Entity::query()->findOrFail($this->otherEntityId));

        try {
            $leaked = ReturnPeriod::withoutEntityScope()->where('entity_id', $this->entityId)->count();

            if ($leaked !== 0) {
                $violations[] = sprintf(
                    'another entity read %d of this entity\'s return periods with the global scope '.
                    'removed. One entity filing another\'s return is not a hypothetical in a group that '.
                    'shares a finance team',
                    $leaked,
                );
            }
        } finally {
            $context->use(Entity::query()->findOrFail($this->entityId));
        }

        return $violations;
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'RTN'.random_int(100, 999),
            'legal_name' => 'Return period check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $other = Entity::create([
            'code' => 'RTO'.random_int(100, 999),
            'legal_name' => 'Return period check, other entity',
            'country' => 'JO',
            'functional_currency' => 'JOD',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->otherEntityId = (string) $other->getKey();
        $this->entity = $entity;
        $context->use($entity);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => '2020-01-01',
            'is_primary' => true,
            'filing_frequency' => FilingFrequency::Monthly->value,
        ]);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'JO',
            'scheme' => 'GST',
            'identifier' => 'JO-123456',
            'effective_from' => '2020-01-01',
            'is_primary' => false,
            'filing_frequency' => FilingFrequency::Quarterly->value,
        ]);

        // One mapped code, so the mapping check is exercised against something
        // that is right as well as something that is wrong.
        TaxCode::create([
            'entity_id' => $this->entityId,
            'code' => 'SA-S-15',
            'category' => TaxCategory::Standard->value,
            'jurisdiction' => 'SA',
            'label_ar' => 'النسبة الأساسية',
            'label_en' => 'Standard rate',
            'reporting_boxes' => ['sa' => ['output' => 'standard_sales', 'input' => 'standard_purchases']],
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'rtnr_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $results;
    }
}
