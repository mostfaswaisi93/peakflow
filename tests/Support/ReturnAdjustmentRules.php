<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Tax\Enums\FilingFrequency;
use App\Tenant\Tax\Exceptions\AdjustmentRefused;
use App\Tenant\Tax\Models\ReturnAdjustment;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\Services\ReturnAdjustments;
use App\Tenant\Tax\Services\ReturnGenerator;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Corrections to a filed return - PF-087.
 *
 * ## The scenario these are built around
 *
 * May has been filed. In July somebody finds that an invoice was reported in
 * the wrong box. The filed return cannot change - the trigger makes sure of
 * that - so the correction is a row that lands in the next open period and
 * carries a reference to May, and it appears on a schedule the authority
 * reads.
 *
 * ## What is easy to get wrong
 *
 * Reporting the correction and leaving it out of the net due. The figures all
 * look right - the boxes are the ledger's, the schedule is there - and the
 * amount filed is wrong by exactly the correction, which is the one number the
 * authority checks first.
 */
final class ReturnAdjustmentRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private ?Entity $entity = null;

    private ?ReturnPeriod $may = null;

    private ?ReturnPeriod $june = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['return adjustments (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->runEach([
                'a correction lands in the next open period, naming what it corrects' => fn (): array => $this->itLandsInTheNextOpenPeriod(),
                'a period that was never filed has nothing to correct' => fn (): array => $this->anUnfiledPeriodRefuses(),
                'the correction is in the net due, not only on the schedule' => fn (): array => $this->itReachesTheNetDue(),
                'the schedule accompanies the return and is under its hash' => fn (): array => $this->theScheduleIsFiled(),
                'a correction needs a reason a person can read' => fn (): array => $this->theReasonIsRequired(),
                'a correction of nothing is refused' => fn (): array => $this->zeroIsRefused(),
                'a box the form does not have is refused' => fn (): array => $this->theBoxMustExist(),
                'a regime that amends refuses a next-period adjustment' => fn (): array => $this->amendingRegimesRefuse(),
                'an adjustment reported in a filed return cannot be changed' => fn (): array => $this->reportedAdjustmentsAreImmutable(),
                'the filed return itself is untouched' => fn (): array => $this->theFiledReturnIsUntouched(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function itLandsInTheNextOpenPeriod(): array
    {
        $violations = [];

        \assert($this->entity !== null && $this->may !== null);

        $adjustment = $this->adjust('standard_sales', '1000.0000', '150.0000');

        if ((string) $adjustment->reported_in_period_id !== (string) $this->june?->getKey()) {
            $violations[] = 'the correction did not land in the next open period. One reported two '.
                'quarters late is a correction the authority will ask about';
        }

        if ((string) $adjustment->corrects_period_id !== (string) $this->may->getKey()) {
            $violations[] = 'the correction does not name the period it corrects, so nobody can trace '.
                'it to a filing';
        }

        /*
         | Filing June moves the answer on, which is the behaviour a
         | correction found in September needs.
         */
        \assert($this->june !== null);
        app(ReturnPeriods::class)->file($this->june, str_repeat('0', 26));

        $later = $this->adjust('standard_sales', '10.0000', '1.5000');
        $july = app(ReturnPeriods::class)->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse('2026-07-10'));

        if ((string) $later->reported_in_period_id !== (string) $july?->getKey()) {
            $violations[] = 'with June filed, a new correction did not move to July';
        }

        return $violations;
    }

    /** @return list<string> */
    public function anUnfiledPeriodRefuses(): array
    {
        \assert($this->entity !== null && $this->june !== null);

        try {
            app(ReturnAdjustments::class)->record(
                $this->entity, $this->june, 'standard_sales',
                Money::of('100.0000', 'SAR'), Money::of('15.0000', 'SAR'),
                'An invoice was reported in the wrong box.',
            );
        } catch (AdjustmentRefused $e) {
            return str_contains($e->getMessage(), 'has not been filed')
                ? []
                : ['an unfiled period refused for the wrong reason: '.$e->getMessage()];
        }

        return ['a correction was recorded against a period that was never filed. That is an ordinary '.
            'document in that period, and reporting it as a correction tells an authority about a '.
            'figure it never received'];
    }

    /** @return list<string> */
    public function itReachesTheNetDue(): array
    {
        $violations = [];

        \assert($this->entity !== null && $this->june !== null);

        $before = app(ReturnGenerator::class)->generate($this->entity, $this->june);

        $this->adjust('standard_sales', '1000.0000', '150.0000');

        $after = app(ReturnGenerator::class)->generate($this->entity, $this->june);

        if ((string) $after->adjustmentTax()->toPresentation() !== '150.00') {
            $violations[] = sprintf(
                'the return reports %s of adjustments and should report 150.00',
                (string) $after->adjustmentTax()->toPresentation(),
            );
        }

        $expected = (string) $before->netDue()->plus(Money::of('150.0000', 'SAR'))->toPresentation();

        if ((string) $after->netDue()->toPresentation() !== $expected) {
            $violations[] = sprintf(
                'the net due is %s and should be %s. A correction reported in a period is part of what '.
                'that period owes - a net due computed without it is the wrong figure filed on purpose, '.
                'and it is the one number the authority checks first',
                (string) $after->netDue()->toPresentation(), $expected,
            );
        }

        // A negative correction goes the other way.
        $this->adjust('standard_sales', '-2000.0000', '-300.0000');

        $both = app(ReturnGenerator::class)->generate($this->entity, $this->june);

        if ((string) $both->adjustmentTax()->toPresentation() !== '-150.00') {
            $violations[] = sprintf(
                'two corrections of +150.00 and -300.00 netted to %s rather than -150.00, so the sign '.
                'is not being carried',
                (string) $both->adjustmentTax()->toPresentation(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function theScheduleIsFiled(): array
    {
        $violations = [];

        \assert($this->entity !== null && $this->june !== null);

        $this->adjust('standard_sales', '1000.0000', '150.0000', 'The March invoice INV-220 was reported '.
            'in the zero-rated box; it is a standard-rated domestic sale.');

        $return = app(ReturnGenerator::class)->generate($this->entity, $this->june);

        if (\count($return->adjustments) !== 1) {
            $violations[] = sprintf(
                'the return carries %d schedule rows and should carry 1', \count($return->adjustments),
            );

            return $violations;
        }

        $row = $return->adjustments[0];

        foreach (['corrects' => '2026-05', 'box' => 'standard_sales', 'tax' => '150.0000'] as $key => $expected) {
            if (($row[$key] ?? null) !== $expected) {
                $violations[] = sprintf(
                    'the schedule row\'s %s is "%s" and should be "%s"', $key, $row[$key] ?? 'missing', $expected,
                );
            }
        }

        if (! str_contains((string) ($row['reason'] ?? ''), 'INV-220')) {
            $violations[] = 'the schedule does not carry the reason, which is what an authority reads';
        }

        /*
         | And it is inside the canonical form, under the hash.
         |
         | A correction reported in a return and absent from its snapshot
         | would be a figure in the net due that the reprint could not
         | explain.
         */
        if (! str_contains($return->canonical(), 'INV-220')) {
            $violations[] = 'the schedule is not in the canonical form, so the filed snapshot would not '.
                'explain its own net due';
        }

        $withoutIt = app(ReturnGenerator::class)->generate($this->entity, $this->june);

        if ($withoutIt->hash() !== $return->hash()) {
            $violations[] = 'two generations of the same return disagree';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theReasonIsRequired(): array
    {
        $violations = [];

        foreach (['', '   ', 'adjustment', 'fix'] as $reason) {
            $savepoint = 'adj_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $this->adjust('standard_sales', '100.0000', '15.0000', $reason);

                $violations[] = sprintf(
                    'the reason "%s" was accepted. The schedule is read by an authority, and a '.
                    'correction with no explanation invites the question the schedule exists to answer',
                    $reason,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        // And the database refuses it too, not only the service.
        $savepoint = 'adj_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            ReturnAdjustment::create([
                'entity_id' => $this->entityId,
                'jurisdiction' => 'SA',
                'corrects_period_id' => (string) $this->may?->getKey(),
                'reported_in_period_id' => (string) $this->june?->getKey(),
                'box' => 'standard_sales',
                'currency' => 'SAR',
                'net_amount' => '100.0000',
                'tax_amount' => '15.0000',
                'reason' => 'fix',
            ]);

            $violations[] = 'the database stored a correction with a four-character reason. A correction '.
                'script and a psql session write rows the same way as the service does';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function zeroIsRefused(): array
    {
        try {
            $this->adjust('standard_sales', '0.0000', '0.0000');
        } catch (Throwable) {
            return [];
        }

        return ['a correction of nothing was recorded. It changes nothing and would still appear on the '.
            'schedule, inviting the question the schedule exists to answer'];
    }

    /** @return list<string> */
    public function theBoxMustExist(): array
    {
        try {
            $this->adjust('a_box_the_form_does_not_have', '100.0000', '15.0000');
        } catch (AdjustmentRefused $e) {
            return str_contains($e->getMessage(), 'no box')
                ? []
                : ['an unknown box refused for the wrong reason: '.$e->getMessage()];
        } catch (Throwable $e) {
            return ['an unknown box was refused by something else: '.$e->getMessage()];
        }

        return ['a correction was recorded into a box the form does not have, which the portal rejects'];
    }

    /** @return list<string> */
    public function amendingRegimesRefuse(): array
    {
        $violations = [];
        $adjustments = app(ReturnAdjustments::class);

        \assert($this->entity !== null && $this->may !== null);

        foreach (['facturx' => true, 'eta' => true, 'zatca' => false, 'generic' => false] as $profile => $amends) {
            if ($adjustments->regimeAmends((string) $profile) !== $amends) {
                $violations[] = sprintf(
                    'the %s profile reports regimeAmends() as %s. The difference is procedural and the '.
                    'figures are the same: a system that implements only one files a correction the '.
                    'authority cannot match, or re-opens returns in a regime that does not allow it',
                    $profile, $amends ? 'false' : 'true',
                );
            }
        }

        try {
            $adjustments->record(
                $this->entity, $this->may, 'standard_sales',
                Money::of('100.0000', 'SAR'), Money::of('15.0000', 'SAR'),
                'An invoice was reported in the wrong box.',
                null,
                'facturx',
            );

            $violations[] = 'a next-period correction was recorded under a regime that amends. The '.
                'authority is expecting a new submission for the original period, with its own '.
                'reference, and this figure matches nothing';
        } catch (AdjustmentRefused $e) {
            if (! str_contains($e->getMessage(), 'AMENDING')) {
                $violations[] = 'an amending regime refused for the wrong reason: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function reportedAdjustmentsAreImmutable(): array
    {
        $violations = [];

        $adjustment = $this->adjust('standard_sales', '1000.0000', '150.0000');

        // While the period it is reported in is open, it can still be corrected.
        try {
            ReturnAdjustment::query()->whereKey($adjustment->getKey())->update(['tax_amount' => '160.0000']);
        } catch (Throwable $e) {
            $violations[] = 'an adjustment in an OPEN period could not be corrected, which would make a '.
                'typo unfixable: '.$e->getMessage();
        }

        // Once the period is filed, it is part of a statement already made.
        \assert($this->june !== null);
        app(ReturnPeriods::class)->file($this->june, str_repeat('0', 26));

        foreach ([
            'changing a figure' => ['tax_amount' => '999.0000'],
            'changing the reason' => ['reason' => 'Something else entirely, at length.'],
        ] as $what => $changes) {
            $savepoint = 'adj_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                ReturnAdjustment::query()->whereKey($adjustment->getKey())->update($changes);

                $violations[] = sprintf(
                    '%s on an adjustment reported in a FILED return succeeded. That moves a figure '.
                    'inside a statement already made to an authority, which is the thing adjustments '.
                    'exist to avoid',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theFiledReturnIsUntouched(): array
    {
        $violations = [];

        \assert($this->may !== null);

        $before = $this->may->refresh();
        $this->adjust('standard_sales', '1000.0000', '150.0000');
        $after = $this->may->refresh();

        if ($before->state !== $after->state || $before->label !== $after->label) {
            $violations[] = 'recording a correction changed the filed period itself';
        }

        if (! $after->isFiled()) {
            $violations[] = 'the corrected period is no longer filed, so the correction reopened it - '.
                'which is a different legal act from correcting it';
        }

        return $violations;
    }

    private function adjust(
        string $box,
        string $net,
        string $tax,
        string $reason = 'An invoice was reported in the wrong box and is corrected here.',
    ): ReturnAdjustment {
        \assert($this->entity !== null && $this->may !== null);

        return app(ReturnAdjustments::class)->record(
            $this->entity,
            $this->may->refresh(),
            $box,
            Money::of($net, 'SAR'),
            Money::of($tax, 'SAR'),
            $reason,
            null,
            'zatca',
        );
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'ADJ'.random_int(100, 999),
            'legal_name' => 'Return adjustment check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
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

        app(ReturnPeriods::class)->generateYear($entity, 'SA', 2026);

        $periods = app(ReturnPeriods::class);
        $this->may = $periods->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse('2026-05-15'));
        $this->june = $periods->forTaxPoint($this->entityId, 'SA', CarbonImmutable::parse('2026-06-15'));

        \assert($this->may !== null);
        $periods->file($this->may, str_repeat('0', 26), 'SUB-MAY');
        $this->may = $this->may->refresh();
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'adjr_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $this->may = $this->may?->fresh();
                $this->june = $this->june?->fresh();

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
