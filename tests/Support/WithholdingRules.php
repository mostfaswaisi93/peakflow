<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tax\Exceptions\WithholdingRefused;
use App\Tenant\Tax\Models\WithholdingCertificate;
use App\Tenant\Tax\Models\WithholdingRate;
use App\Tenant\Tax\Services\Withholding;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Withholding tax: the rate, the deduction, the certificate - PF-083.
 *
 * ## The scenario these are built around
 *
 * A Saudi entity pays an Egyptian consultant. The statutory rate for
 * professional services is one figure, the consultant holds a treaty rate that
 * is lower, and a third supplier holds an exemption certificate. All three are
 * rows in one table covering the same dates, and the payment has to resolve to
 * exactly one of them - by a rule, not by whichever row the index returns.
 *
 * ## The invariant that is easiest to break by being helpful
 *
 * An invoice total that does not equal the cash received is CORRECT when
 * withholding applies. The tempting fix - treating the shortfall as a short
 * payment - either leaves the invoice open forever or writes the difference
 * off as bad debt, and both look like housekeeping while the payee quietly
 * loses a credit it was entitled to claim.
 *
 * So one rule here is architectural: nothing in the document-total path may
 * mention withholding at all.
 */
final class WithholdingRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private string $otherEntityId = '';

    private string $consultantId = '';

    private string $exemptId = '';

    private CarbonImmutable $on;

    public function __construct()
    {
        $this->on = CarbonImmutable::parse('2026-05-10');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['withholding (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->runEach([
                'the most specific rate wins, and a party beats an item type' => fn (): array => $this->specificityDecides(),
                'no rate configured is not a rate of zero' => fn (): array => $this->noRateIsNotZero(),
                'the base is the regime\'s choice: the net or the gross' => fn (): array => $this->theBaseIsAChoice(),
                'the deduction comes off the payment, never off the document' => fn (): array => $this->itIsNotOnTheDocument(),
                'a certificate carries the period of the deduction' => fn (): array => $this->theCertificatePeriod(),
                'a deduction of zero is not certified' => fn (): array => $this->zeroIsNotCertified(),
                'overlapping rates are refused by the database' => fn (): array => $this->overlapsAreRefused(),
                'the rate table refuses what it cannot mean' => fn (): array => $this->theTableRefuses(),
                'withholding reports on its own, from the certificates' => fn (): array => $this->itReportsSeparately(),
                'another entity cannot see these rates' => fn (): array => $this->rowLevelSecurityApplies(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function specificityDecides(): array
    {
        $violations = [];
        $withholding = app(Withholding::class);

        $cases = [
            // [party, item type] => the rate that must win
            ['any party, any item', null, null, '15'],
            ['any party, professional services', null, 'professional_services', '5'],
            ['the consultant, any item', $this->consultantId, null, '10'],

            /*
             | The case the ordering exists for.
             |
             | Both the 10% party row and the 5% item row match. The party row
             | wins, because a party-specific rate is normally a treaty rate or
             | an exemption and a generic rule about an item type must not
             | override it. The reverse ordering would withhold 5% from a
             | supplier whose agreement says 10% - or, worse, 20% from one
             | holding a certificate that says 5%.
             */
            ['the consultant, professional services', $this->consultantId, 'professional_services', '10'],

            // Both columns set is the most specific of all.
            ['the exempt supplier, professional services', $this->exemptId, 'professional_services', '0'],

            // And narrowing on the item type that has no row falls back.
            ['the exempt supplier, royalties', $this->exemptId, 'royalties', '15'],
        ];

        foreach ($cases as [$label, $party, $item, $expected]) {
            $rate = $withholding->rateFor($this->entityId, 'SA', $this->on, $party, $item);

            if ($rate === null) {
                $violations[] = sprintf('%s resolved no rate and should resolve %s%%', $label, $expected);

                continue;
            }

            $actual = (string) $rate->percentage()->strippedOfTrailingZeros();

            if ($actual !== $expected) {
                $violations[] = sprintf('%s resolved %s%% and should resolve %s%%', $label, $actual, $expected);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function noRateIsNotZero(): array
    {
        $violations = [];
        $withholding = app(Withholding::class);

        $unconfigured = $withholding->rateFor($this->entityId, 'JO', $this->on);

        if ($unconfigured !== null) {
            $violations[] = sprintf(
                'a jurisdiction with no rows resolved %s. "Nobody has configured this jurisdiction" and '.
                '"this party is exempt" are different facts, and the payer that withholds nothing '.
                'because of the first is liable for what it should have withheld',
                $unconfigured->describe(),
            );
        }

        $exempt = $withholding->rateFor($this->entityId, 'SA', $this->on, $this->exemptId, 'professional_services');

        if ($exempt === null || ! $exempt->isZero()) {
            $violations[] = 'an exemption recorded as a zero rate did not resolve, so the only way to '.
                'record one would be to leave the jurisdiction unconfigured';
        }

        // And the zero rate produces a zero deduction, not a refusal.
        if ($exempt !== null) {
            $deduction = $withholding->deduct(Money::of('1000.00', 'SAR'), Money::of('150.00', 'SAR'), $exempt);

            if (! $deduction->isZero() || ! $deduction->payable()->equals(Money::of('1000.00', 'SAR'))) {
                $violations[] = sprintf(
                    'an exempt payment withheld %s and should withhold nothing', (string) $deduction->withheld,
                );
            }
        }

        // A rate before its validity begins does not apply.
        $early = $withholding->rateFor($this->entityId, 'SA', CarbonImmutable::parse('2025-06-01'));

        if ($early !== null) {
            $violations[] = 'a rate whose validity begins in 2026 applied to a 2025 payment, so the '.
                'effective dating is not being read';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theBaseIsAChoice(): array
    {
        $violations = [];
        $withholding = app(Withholding::class);

        $net = $withholding->rateFor($this->entityId, 'SA', $this->on, $this->consultantId);
        $gross = $withholding->rateFor($this->entityId, 'EG', $this->on);

        if ($net === null || $gross === null) {
            return ['the net-base and gross-base rates did not both resolve'];
        }

        $invoice = Money::of('1000.00', 'SAR');
        $vat = Money::of('150.00', 'SAR');

        $onNet = $withholding->deduct($invoice, $vat, $net);
        $onGross = $withholding->deduct($invoice, $vat, $gross);

        foreach ([
            ['the net base', $onNet, '1000.00', '100.00', '900.00'],
            ['the gross base', $onGross, '1150.00', '115.00', '1035.00'],
        ] as [$label, $deduction, $base, $withheld, $payable]) {
            foreach ([
                'base' => [(string) $deduction->base->toPresentation(), $base],
                'withheld' => [(string) $deduction->withheld->toPresentation(), $withheld],
                'payable' => [(string) $deduction->payable()->toPresentation(), $payable],
            ] as $what => [$actual, $expected]) {
                if ($actual !== $expected) {
                    $violations[] = sprintf('%s: %s is %s and should be %s', $label, $what, $actual, $expected);
                }
            }
        }

        if ($onNet->withheld->equals($onGross->withheld)) {
            $violations[] = 'the two bases withheld the same amount, so the base is not reaching the '.
                'computation. The difference between them is the whole VAT amount, not a rounding '.
                'difference';
        }

        return $violations;
    }

    /**
     * The architectural half: nothing in the document path knows about this.
     *
     * @return list<string>
     */
    public function itIsNotOnTheDocument(): array
    {
        $violations = [];

        foreach ([
            'app/Tenant/Tax/Services/TaxCalculator.php',
            'app/Tenant/Tax/TaxBreakdown.php',
            'app/Tenant/Tax/TaxBreakdownEntry.php',
            'app/Tenant/Tax/TaxableLine.php',
        ] as $relative) {
            $path = \dirname(__DIR__, 2).'/'.$relative;
            $source = is_file($path) ? (string) file_get_contents($path) : '';

            if (str_contains($source, 'Withholding') || str_contains($source, 'withholding_')) {
                $violations[] = sprintf(
                    '%s refers to withholding. It belongs to the settlement path: the moment a document '.
                    'total can see it, somebody puts it on an invoice - and an invoice that nets off '.
                    'withholding understates the receivable and the VAT base at the same time',
                    $relative,
                );
            }
        }

        /*
         | And the arithmetic the settlement path depends on.
         |
         | The receivable is settled for the FULL base and the cash is short
         | by the deduction. A deduction object whose payable did not reconcile
         | to its own base is one that cannot be allocated against anything.
         */
        $withholding = app(Withholding::class);
        $rate = $withholding->rateFor($this->entityId, 'SA', $this->on, $this->consultantId);

        if ($rate !== null) {
            $deduction = $withholding->deduct(Money::of('1000.00', 'SAR'), Money::of('150.00', 'SAR'), $rate);

            if (! $deduction->payable()->plus($deduction->withheld)->equals($deduction->base)) {
                $violations[] = 'the payable plus the withheld does not come back to the base, so the '.
                    'allocation cannot close the receivable in full';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theCertificatePeriod(): array
    {
        $violations = [];
        $withholding = app(Withholding::class);
        $rate = $withholding->rateFor($this->entityId, 'SA', $this->on, $this->consultantId);

        if ($rate === null) {
            return ['the consultant rate did not resolve'];
        }

        $deduction = $withholding->deduct(Money::of('1000.00', 'SAR'), Money::of('150.00', 'SAR'), $rate);

        /*
         | An invoice raised in March, paid in May.
         |
         | The certificate is a May certificate. A system that reported it in
         | March gives the payee a credit the authority will not match, and the
         | payee finds out when its own return is queried.
         */
        $paidOn = CarbonImmutable::parse('2026-05-10');
        $certificate = $withholding->certify($this->entityId, $this->consultantId, $deduction, $paidOn, 'TRF-1');

        if ($certificate->period !== '2026-05') {
            $violations[] = sprintf(
                'a payment made on %s produced a certificate for period %s. The credit is claimed in the '.
                'period the deduction was made',
                $paidOn->toDateString(), $certificate->period,
            );
        }

        if ($certificate->number === '' || ! str_contains($certificate->number, 'WHT')) {
            $violations[] = sprintf(
                'the certificate number is "%s". The payee files it with its own return and the '.
                'authority matches the two, so it needs an identity from the numbering series rather '.
                'than a ULID',
                $certificate->number,
            );
        }

        foreach ([
            'base' => [(string) $certificate->base()->toPresentation(), '1000.00'],
            'withheld' => [(string) $certificate->withheld()->toPresentation(), '100.00'],
            'paid' => [(string) $certificate->paid()->toPresentation(), '900.00'],
        ] as $what => [$actual, $expected]) {
            if ($actual !== $expected) {
                $violations[] = sprintf('the certificate %s is %s and should be %s', $what, $actual, $expected);
            }
        }

        if ($certificate->payment_reference !== 'TRF-1') {
            $violations[] = 'the payment reference was not recorded, so nobody can tie the certificate '.
                'to the transfer it came from';
        }

        return $violations;
    }

    /** @return list<string> */
    public function zeroIsNotCertified(): array
    {
        $withholding = app(Withholding::class);
        $exempt = $withholding->rateFor($this->entityId, 'SA', $this->on, $this->exemptId, 'professional_services');

        if ($exempt === null) {
            return ['the exemption row did not resolve'];
        }

        $nothing = $withholding->deduct(Money::of('100.00', 'SAR'), Money::of('15.00', 'SAR'), $exempt);

        try {
            $certificate = $withholding->certify($this->entityId, $this->exemptId, $nothing, $this->on);
        } catch (WithholdingRefused) {
            return [];
        } catch (Throwable $e) {
            return ['a zero deduction was refused by the wrong thing: '.$e->getMessage()];
        }

        return [sprintf(
            'certificate %s was issued for a deduction of nothing. It tells the payee it may claim '.
            'nothing, which it can work out without a document - and an exemption belongs on the rate '.
            'table where it is dated and sourced',
            $certificate->number,
        )];
    }

    /** @return list<string> */
    public function overlapsAreRefused(): array
    {
        $attempts = [
            'two "any party, any item" rows' => ['rate' => '20.000000'],
            'two rows for the same party' => ['rate' => '20.000000', 'party_id' => $this->consultantId],
            'two rows for the same item type' => ['rate' => '20.000000', 'item_type' => 'professional_services'],
        ];

        $violations = [];

        foreach ($attempts as $what => $attrs) {
            $savepoint = 'wht_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $this->rate($attrs);

                $violations[] = sprintf(
                    '%s covering the same dates were both stored. "Which rate applied then" now has two '.
                    'answers, and a payment will take whichever the index returns first',
                    $what,
                );
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), 'withholding_rates_no_overlap')) {
                    $violations[] = sprintf('%s was refused by something else: %s', $what, $e->getMessage());
                }
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        /*
         | And a successor that abuts rather than overlaps is accepted.
         |
         | Otherwise the constraint would make a rate change impossible, which
         | is the failure mode that gets a constraint dropped.
         */
        $savepoint = 'wht_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            WithholdingRate::query()
                ->where('entity_id', $this->entityId)
                ->whereNull('party_id')
                ->whereNull('item_type')
                ->update(['validity' => WithholdingRate::range(
                    CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-07-01'),
                )]);

            $this->rate([
                'rate' => '20.000000',
                'validity' => WithholdingRate::range(CarbonImmutable::parse('2026-07-01')),
            ]);
        } catch (Throwable $e) {
            $violations[] = 'a successor rate starting the day the predecessor ends was refused, which '.
                'would make a rate change impossible: '.$e->getMessage();
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function theTableRefuses(): array
    {
        $cases = [
            'a rate above one hundred percent' => ['rate' => '150.000000', 'item_type' => 'rent'],
            'a negative rate' => ['rate' => '-5.000000', 'item_type' => 'rent'],
            'a base nothing implements' => ['rate' => '5.000000', 'item_type' => 'rent', 'base' => 'whatever'],
            'a rate with no documented source' => ['rate' => '5.000000', 'item_type' => 'rent', 'source' => '   '],
            'a validity with no start' => ['rate' => '5.000000', 'item_type' => 'rent', 'validity' => '(,2027-01-01)'],
        ];

        $violations = [];

        foreach ($cases as $what => $attrs) {
            $savepoint = 'wht_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $this->rate($attrs);
                $violations[] = sprintf('%s was stored', $what);
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function itReportsSeparately(): array
    {
        $violations = [];
        $withholding = app(Withholding::class);
        $rate = $withholding->rateFor($this->entityId, 'SA', $this->on, $this->consultantId);

        if ($rate === null) {
            return ['the consultant rate did not resolve'];
        }

        $deduction = $withholding->deduct(Money::of('1000.00', 'SAR'), Money::of('150.00', 'SAR'), $rate);

        $withholding->certify($this->entityId, $this->consultantId, $deduction, CarbonImmutable::parse('2026-05-10'), 'A');
        $withholding->certify($this->entityId, $this->consultantId, $deduction, CarbonImmutable::parse('2026-05-20'), 'B');
        $june = $withholding->certify($this->entityId, $this->consultantId, $deduction, CarbonImmutable::parse('2026-06-02'), 'C');

        $may = $withholding->totalsFor($this->entityId, '2026-05', 'SAR');

        if ((string) $may['total']->toPresentation() !== '200.00' || $may['certificates'] !== 2) {
            $violations[] = sprintf(
                'May totalled %s across %d certificates and should total 200.00 across 2. A withholding '.
                'return is filed on its own cycle, so a June deduction must not fall into May',
                (string) $may['total']->toPresentation(), $may['certificates'],
            );
        }

        /*
         | A voided certificate drops out.
         |
         | The payee cannot claim against it, so a total that still included it
         | would overstate the credit the authority is being asked to match.
         */
        $june->update(['status' => 'void']);
        $after = $withholding->totalsFor($this->entityId, '2026-06', 'SAR');

        if (! $after['total']->isZero() || $after['certificates'] !== 0) {
            $violations[] = sprintf(
                'a voided certificate is still in the June total of %s. The payee cannot claim against '.
                'it', (string) $after['total']->toPresentation(),
            );
        }

        if (\count($may['by_party']) !== 1) {
            $violations[] = 'the per-party breakdown is missing, and a withholding return has to list '.
                'the payees';
        }

        return $violations;
    }

    /** @return list<string> */
    public function rowLevelSecurityApplies(): array
    {
        $violations = [];
        $context = app(EntityContext::class);

        $mine = WithholdingRate::query()->where('entity_id', $this->entityId)->count();

        if ($mine === 0) {
            return ['this entity cannot see its own rates'];
        }

        $context->use(Entity::query()->findOrFail($this->otherEntityId));

        try {
            $leaked = WithholdingRate::withoutEntityScope()
                ->where('entity_id', $this->entityId)
                ->count();

            if ($leaked !== 0) {
                $violations[] = sprintf(
                    'another entity read %d of this entity\'s withholding rates with the global scope '.
                    'removed. Cross-company leakage inside one tenant is harder to notice than '.
                    'cross-tenant leakage, because the data looks plausible',
                    $leaked,
                );
            }

            $own = WithholdingRate::withoutEntityScope()->count();

            if ($own !== 0) {
                $violations[] = sprintf(
                    'the other entity sees %d rate rows in total, so the policy is not filtering', $own,
                );
            }
        } finally {
            $context->use(Entity::query()->findOrFail($this->entityId));
        }

        return $violations;
    }

    /** @param  array<string, string|null>  $attrs */
    private function rate(array $attrs): WithholdingRate
    {
        return WithholdingRate::create(array_merge([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'base' => 'net',
            'validity' => WithholdingRate::range(CarbonImmutable::parse('2026-01-01')),
            'source' => 'KSA Income Tax Law - article unverified here',
        ], $attrs));
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'WHT'.random_int(100, 999),
            'legal_name' => 'Withholding check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $other = Entity::create([
            'code' => 'WHO'.random_int(100, 999),
            'legal_name' => 'Withholding check, other entity',
            'country' => 'JO',
            'functional_currency' => 'JOD',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->otherEntityId = (string) $other->getKey();
        $context->use($entity);

        $consultant = Party::create([
            'code' => 'WHT-CON-'.random_int(100, 999),
            'legal_name' => 'Consultant with a treaty rate',
            'is_supplier' => true,
            'country' => 'EG',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $exempt = Party::create([
            'code' => 'WHT-EX-'.random_int(100, 999),
            'legal_name' => 'Supplier holding an exemption',
            'is_supplier' => true,
            'country' => 'EG',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $this->consultantId = (string) $consultant->getKey();
        $this->exemptId = (string) $exempt->getKey();

        $this->rate(['rate' => '15.000000']);
        $this->rate(['rate' => '5.000000', 'item_type' => 'professional_services']);
        $this->rate(['rate' => '10.000000', 'party_id' => $this->consultantId]);
        $this->rate(['rate' => '0.000000', 'party_id' => $this->exemptId, 'item_type' => 'professional_services']);
        $this->rate(['rate' => '10.000000', 'jurisdiction' => 'EG', 'base' => 'gross']);
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'whtr_'.bin2hex(random_bytes(4));
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
