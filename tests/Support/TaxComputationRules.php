<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Money\Money;
use App\Support\Money\RoundingBasis;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Tax\DocumentAdjustment;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\TaxComputationRefused;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tax\TaxBreakdown;
use Throwable;

/**
 * A document's tax: the breakdown, the totals and the invariants - PF-082.
 *
 * ## What is actually being claimed
 *
 * Three things, and they fail in three different ways.
 *
 * That tax is **rounded once per group**: the failure is one cent per
 * document, invisible on any single one, and a reconciliation difference
 * between the ledger and the filed return at the end of the quarter.
 *
 * That the breakdown is **`BG-23`-shaped**: the failure is a document the
 * recipient's validator rejects, found by the customer rather than by us.
 *
 * That recomputation is **identical**: the failure is a document that cannot
 * be reconciled with anything, including itself - which is worse than one that
 * is a cent off, because a cent can be explained.
 *
 * ## The generated half
 *
 * The named cases are the ones somebody thought of. The property check
 * generates documents - how many groups, how many lines, which categories,
 * what magnitudes, which adjustments - and asserts the invariants on all of
 * them, printing its seed so a failure can be re-run.
 */
final class TaxComputationRules
{
    private const DOCUMENTS = 200;

    private int $seed = 0;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $this->seed = random_int(1, PHP_INT_MAX);
        mt_srand($this->seed);

        return [
            'tax is accumulated unrounded and rounded once per group' => $this->roundedOncePerGroup(),
            'the breakdown is one entry per category and rate, in order' => $this->theBreakdownShape(),
            'a mixed-rate document, to the cent' => $this->aMixedRateDocument(),
            'a negative line reduces its own group' => $this->aNegativeLine(),
            'a line discounted to nothing keeps its group' => $this->aFreeLine(),
            'document adjustments are apportioned before tax, residual by rule' => $this->apportionment(),
            'the computation refuses what it cannot compute' => $this->theRefusals(),
            'for any generated document, the invariants hold' => $this->theProperty(),
            'any generated document recomputes identically' => $this->recomputationIsIdentical(),
        ];
    }

    /** @return list<string> */
    public function roundedOncePerGroup(): array
    {
        $violations = [];
        $lines = array_fill(0, 3, TaxableLine::of(Money::of('10.03', 'SAR'), TaxCategory::Standard, '15'));

        $perGroup = $this->calculator()->compute($lines, $this->policy(RoundingBasis::PerRateGroup));
        $perLine = $this->calculator()->compute($lines, $this->policy(RoundingBasis::PerLine));

        foreach ([
            ['per group', $perGroup, '30.09', '4.51', '34.60'],
            ['per line', $perLine, '30.09', '4.50', '34.59'],
        ] as [$label, $breakdown, $net, $tax, $gross]) {
            foreach ([
                'net' => [(string) $breakdown->net->toPresentation(), $net],
                'tax' => [(string) $breakdown->tax->toPresentation(), $tax],
                'gross' => [(string) $breakdown->gross()->toPresentation(), $gross],
            ] as $what => [$actual, $expected]) {
                if ($actual !== $expected) {
                    $violations[] = sprintf(
                        'three lines of 10.03 at 15%%, %s: %s is %s and should be %s',
                        $label, $what, $actual, $expected,
                    );
                }
            }
        }

        if ((string) $perGroup->tax->toPresentation() === (string) $perLine->tax->toPresentation()) {
            $violations[] = 'the two bases agree on the one document that exists to tell them apart, so '.
                'the basis is not reaching the computation';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theBreakdownShape(): array
    {
        $violations = [];

        $lines = [
            TaxableLine::of(Money::of('100.00', 'SAR'), TaxCategory::Standard, '15'),
            TaxableLine::of(Money::of('50.00', 'SAR'), TaxCategory::Standard, '5'),
            TaxableLine::of(Money::of('10.00', 'SAR'), TaxCategory::Standard, '15.00'),
            TaxableLine::of(Money::of('30.00', 'SAR'), TaxCategory::ZeroRated, '0', 'vatex-sa-32'),
        ];

        $breakdown = $this->calculator()->compute($lines, $this->policy(), [], 'en');

        if ($breakdown->count() !== 3) {
            $violations[] = sprintf(
                'a document with 15%%, 5%%, 15.00%% and zero-rated lines produced %d entries and should '.
                'produce 3. `15` and `15.00` are one rate; a breakdown that splits them reports two '.
                'entries for one rate, and one that merges the 5%% into the 15%% reports a rate that '.
                'applies to neither',
                $breakdown->count(),
            );
        }

        $order = array_map(
            static fn (object $entry): string => $entry->category->value.'@'.$entry->ratePercent,
            $breakdown->entries,
        );

        if ($order !== ['S@15', 'S@5', 'Z@0']) {
            $violations[] = sprintf(
                'the entries came out as %s and should be in first-appearance order (S@15, S@5, Z@0). A '.
                'sort on equal keys is where "recomputing gives identical output" quietly stops being '.
                'true', implode(', ', $order),
            );
        }

        $standard = $breakdown->entryFor('S', '15');

        if ($standard === null || (string) $standard->taxableAmount->toPresentation() !== '110.00') {
            $violations[] = 'the 15% entry does not carry 110.00, so the two 15% lines were not merged';
        }

        $zero = $breakdown->entryFor('Z', '0');

        if ($zero === null || $zero->reasonCode !== 'vatex-sa-32' || $zero->reasonText === null) {
            $violations[] = 'the zero-rated entry carries no reason code and text. EN 16931 needs '.
                'BT-121 and BT-120 on it, and an invoice that says 0% with no reason cannot be defended';
        }

        $json = $breakdown->jsonSerialize();

        foreach (['BT-109', 'BT-110', 'BT-112', 'BG-23'] as $field) {
            if (! array_key_exists($field, $json)) {
                $violations[] = sprintf('the serialised breakdown has no %s', $field);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function aMixedRateDocument(): array
    {
        $lines = [
            TaxableLine::of(Money::of('100.00', 'SAR'), TaxCategory::Standard, '15'),
            TaxableLine::of(Money::of('50.00', 'SAR'), TaxCategory::Standard, '5'),
            TaxableLine::of(Money::of('30.00', 'SAR'), TaxCategory::ZeroRated, '0', 'vatex-sa-32'),
            TaxableLine::of(Money::of('20.00', 'SAR'), TaxCategory::Exempt, '0', 'vatex-sa-edu'),
        ];

        $breakdown = $this->calculator()->compute($lines, $this->policy(), [], 'en');

        return $this->expectTotals($breakdown, '200.00', '17.50', [
            'S@15' => ['100.00', '15.00'],
            'S@5' => ['50.00', '2.50'],
            'Z@0' => ['30.00', '0.00'],
            'E@0' => ['20.00', '0.00'],
        ]);
    }

    /** @return list<string> */
    public function aNegativeLine(): array
    {
        $violations = $this->expectTotals(
            $this->calculator()->compute([
                TaxableLine::of(Money::of('100.00', 'SAR'), TaxCategory::Standard, '15'),
                TaxableLine::of(Money::of('-40.00', 'SAR'), TaxCategory::Standard, '15'),
            ], $this->policy()),
            '60.00', '9.00', ['S@15' => ['60.00', '9.00']],
        );

        /*
         | And a negative line in its OWN group produces a negative entry.
         |
         | Not an error: a credit note line, or a returned item billed at a
         | different rate from the rest of the document. What would be an error
         | is dropping it, or taking its absolute value.
         */
        $mixed = $this->calculator()->compute([
            TaxableLine::of(Money::of('100.00', 'SAR'), TaxCategory::Standard, '15'),
            TaxableLine::of(Money::of('-40.00', 'SAR'), TaxCategory::Standard, '5'),
        ], $this->policy());

        $entry = $mixed->entryFor('S', '5');

        if ($entry === null || (string) $entry->taxAmount->toPresentation() !== '-2.00') {
            $violations[] = sprintf(
                'a -40.00 line at 5%% produced %s rather than -2.00 of tax. A credit line is a negative '.
                'entry, not a dropped one and not an absolute value',
                $entry === null ? 'no entry' : (string) $entry->taxAmount->toPresentation(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function aFreeLine(): array
    {
        $breakdown = $this->calculator()->compute([
            TaxableLine::of(Money::of('100.00', 'SAR'), TaxCategory::Standard, '15'),
            TaxableLine::of(Money::of('0.00', 'SAR'), TaxCategory::Standard, '15'),
        ], $this->policy());

        $violations = $this->expectTotals($breakdown, '100.00', '15.00', ['S@15' => ['100.00', '15.00']]);

        /*
         | A line discounted to nothing is still a line of the document, and
         | its group still exists. The case worth checking is a document whose
         | ONLY line is free: the entry has to survive, because a receiving
         | system validates that every line's category appears in BG-23.
         */
        $free = $this->calculator()->compute([
            TaxableLine::of(Money::of('0.00', 'SAR'), TaxCategory::Standard, '15'),
        ], $this->policy());

        if ($free->count() !== 1) {
            $violations[] = 'a document whose only line is discounted to nothing produced no breakdown '.
                'entry. Every line\'s category has to appear in BG-23, or the recipient\'s validator '.
                'rejects the document';
        }

        if (! $free->tax->isZero() || ! $free->net->isZero()) {
            $violations[] = 'a document of one free line does not total zero';
        }

        return $violations;
    }

    /** @return list<string> */
    public function apportionment(): array
    {
        $violations = [];

        /*
         | 10.00 across groups of 100 and 50 does not divide evenly.
         |
         | 10 x 100/150 is 6.666666..., taken toward zero at the storage scale
         | that is 6.6666, and 3.3333 for the other - 9.9999 allocated with
         | 0.0001 left. `Money::allocate()` puts the residual on the heaviest
         | weight, so the 100 group carries 6.6667.
         |
         | Asserted to four decimal places, because the whole point is that
         | the residual lands somewhere by rule rather than being lost.
         */
        $lines = [
            TaxableLine::of(Money::of('100.00', 'SAR'), TaxCategory::Standard, '15'),
            TaxableLine::of(Money::of('50.00', 'SAR'), TaxCategory::Standard, '5'),
        ];

        $breakdown = $this->calculator()->compute(
            $lines,
            $this->policy(),
            [DocumentAdjustment::allowance(Money::of('10.00', 'SAR'), 'early settlement')],
        );

        $expected = ['S@15' => '93.3333', 'S@5' => '46.6667'];

        foreach ($breakdown->entries as $entry) {
            $key = $entry->category->value.'@'.$entry->ratePercent;
            $actual = (string) $entry->taxableAmount;

            if (($expected[$key] ?? null) !== $actual) {
                $violations[] = sprintf(
                    '%s carries a taxable amount of %s and should carry %s. The residual of an uneven '.
                    'apportionment has to land on a named group by rule, not wherever the loop ended',
                    $key, $actual, $expected[$key] ?? '(no expectation)',
                );
            }
        }

        if ((string) $breakdown->net !== '140.0000') {
            $violations[] = sprintf(
                'the apportioned net is %s and should be 140.0000 exactly. A document-level discount '.
                'that does not sum back to itself is a document whose total nobody can reproduce',
                (string) $breakdown->net,
            );
        }

        // A charge goes the other way.
        $charged = $this->calculator()->compute(
            $lines,
            $this->policy(),
            [DocumentAdjustment::charge(Money::of('15.00', 'SAR'), 'freight')],
        );

        if ((string) $charged->net !== '165.0000') {
            $violations[] = sprintf('a 15.00 charge gave a net of %s, not 165.0000', (string) $charged->net);
        }

        /*
         | And the per-line basis must see the apportioned amounts too.
         |
         | Otherwise a per-line jurisdiction would compute tax on the
         | pre-discount lines and report a tax total that does not match its
         | own taxable amounts.
         */
        $perLine = $this->calculator()->compute(
            $lines,
            $this->policy(RoundingBasis::PerLine),
            [DocumentAdjustment::allowance(Money::of('10.00', 'SAR'))],
        );

        if (! $perLine->satisfiesBrCo14()) {
            $violations[] = 'a per-line document with an apportioned discount violates BR-CO-14';
        }

        if ((string) $perLine->net !== '140.0000') {
            $violations[] = sprintf(
                'the per-line basis apportioned to %s rather than 140.0000, so the lines did not move '.
                'with their group', (string) $perLine->net,
            );
        }

        /*
         | And its TAX has to come from the apportioned lines.
         |
         | The net alone does not prove it: the group's net is apportioned
         | whether or not its lines were, so a per-line document could report
         | a taxable amount of 140.00 with tax computed on 150.00 and still
         | satisfy BR-CO-14 - every entry consistent with itself, and the
         | document consistent with nothing.
         |
         | Each group here holds exactly one line, so the two bases MUST
         | agree. 93.3333 at 15% is 14.00 and 46.6667 at 5% is 2.33.
         */
        if ((string) $perLine->tax->toPresentation() !== '16.33') {
            $violations[] = sprintf(
                'the per-line tax is %s and should be 16.33 - 14.00 on the apportioned 93.3333 plus '.
                '2.33 on 46.6667. A per-line document whose tax was computed from the pre-discount '.
                'lines reports a taxable amount the tax does not come from',
                (string) $perLine->tax->toPresentation(),
            );
        }

        $perGroup = $this->calculator()->compute(
            $lines,
            $this->policy(),
            [DocumentAdjustment::allowance(Money::of('10.00', 'SAR'))],
        );

        if (! $perLine->tax->equals($perGroup->tax)) {
            $violations[] = sprintf(
                'with one line per rate group the two bases gave %s and %s. They cannot differ: there '.
                'is nothing to round twice',
                (string) $perLine->tax->toPresentation(), (string) $perGroup->tax->toPresentation(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function theRefusals(): array
    {
        $violations = [];

        $cases = [
            'a document with no lines' => fn (): TaxBreakdown => $this->calculator()->compute([], $this->policy()),
            'two currencies on one document' => fn (): TaxBreakdown => $this->calculator()->compute([
                TaxableLine::of(Money::of('10.00', 'SAR'), TaxCategory::Standard, '15'),
                TaxableLine::of(Money::of('10.00', 'EUR'), TaxCategory::Standard, '15'),
            ], $this->policy()),
            'a zero category carrying a rate' => fn (): TaxBreakdown => $this->calculator()->compute([
                TaxableLine::of(Money::of('10.00', 'SAR'), TaxCategory::ZeroRated, '15'),
            ], $this->policy()),
            'a standard-rated line at zero' => fn (): TaxBreakdown => $this->calculator()->compute([
                TaxableLine::of(Money::of('10.00', 'SAR'), TaxCategory::Standard, '0'),
            ], $this->policy()),
            'an adjustment in another currency' => fn (): TaxBreakdown => $this->calculator()->compute([
                TaxableLine::of(Money::of('10.00', 'SAR'), TaxCategory::Standard, '15'),
            ], $this->policy(), [DocumentAdjustment::allowance(Money::of('1.00', 'EUR'))]),
            'a discount with nothing positive to spread it across' => fn (): TaxBreakdown => $this->calculator()->compute([
                TaxableLine::of(Money::of('-10.00', 'SAR'), TaxCategory::Standard, '15'),
            ], $this->policy(), [DocumentAdjustment::allowance(Money::of('1.00', 'SAR'))]),
        ];

        foreach ($cases as $what => $attempt) {
            try {
                $breakdown = $attempt();

                $violations[] = sprintf(
                    '%s was computed rather than refused, and produced a net of %s. Every one of these '.
                    'returns a valid-looking object that would be filed',
                    $what, (string) $breakdown->net,
                );
            } catch (TaxComputationRefused) {
                continue;
            } catch (Throwable $e) {
                $violations[] = sprintf('%s was refused by %s rather than by the calculator: %s',
                    $what, $e::class, $e->getMessage());
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theProperty(): array
    {
        $violations = [];

        for ($case = 0; $case < self::DOCUMENTS; $case++) {
            [$lines, $adjustments, $currency] = $this->generateDocument();
            $policy = $this->policy(mt_rand(0, 1) === 1 ? RoundingBasis::PerLine : RoundingBasis::PerRateGroup);

            try {
                $breakdown = $this->calculator()->compute($lines, $policy, $adjustments);
            } catch (TaxComputationRefused $e) {
                // A generated document the calculator legitimately refuses.
                continue;
            } catch (Throwable $e) {
                $violations[] = sprintf('seed %d case %d: %s', $this->seed, $case, $e->getMessage());

                if (\count($violations) >= 5) {
                    break;
                }

                continue;
            }

            if (! $breakdown->tax->equals($breakdown->sumOfEntries())) {
                $violations[] = sprintf(
                    'seed %d case %d: BR-CO-14 fails - the tax total is %s and the breakdown sums to %s',
                    $this->seed, $case, (string) $breakdown->tax, (string) $breakdown->sumOfEntries(),
                );
            }

            if (! $breakdown->net->equals($breakdown->sumOfTaxableAmounts())) {
                $violations[] = sprintf(
                    'seed %d case %d: the document net is %s and the taxable amounts sum to %s',
                    $this->seed, $case, (string) $breakdown->net, (string) $breakdown->sumOfTaxableAmounts(),
                );
            }

            $expected = $this->netOf($lines, $adjustments, $currency);

            if (! $breakdown->net->equals($expected)) {
                $violations[] = sprintf(
                    'seed %d case %d: the net is %s and the lines plus adjustments come to %s',
                    $this->seed, $case, (string) $breakdown->net, (string) $expected,
                );
            }

            if (! $breakdown->gross()->equals($breakdown->net->plus($breakdown->tax))) {
                $violations[] = sprintf('seed %d case %d: the gross is not net plus tax', $this->seed, $case);
            }

            if (\count($violations) >= 5) {
                break;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function recomputationIsIdentical(): array
    {
        $violations = [];

        for ($case = 0; $case < self::DOCUMENTS; $case++) {
            [$lines, $adjustments] = $this->generateDocument();
            $policy = $this->policy(mt_rand(0, 1) === 1 ? RoundingBasis::PerLine : RoundingBasis::PerRateGroup);

            try {
                $first = json_encode($this->calculator()->compute($lines, $policy, $adjustments), JSON_THROW_ON_ERROR);
                $second = json_encode($this->calculator()->compute($lines, $policy, $adjustments), JSON_THROW_ON_ERROR);
            } catch (TaxComputationRefused) {
                continue;
            } catch (Throwable $e) {
                $violations[] = sprintf('seed %d case %d: %s', $this->seed, $case, $e->getMessage());

                continue;
            }

            if ($first !== $second) {
                $violations[] = sprintf(
                    'seed %d case %d: the same document computed twice gave different output. A document '.
                    'that cannot be reconciled with itself is worse than one that is a cent off, because '.
                    'a cent can be explained',
                    $this->seed, $case,
                );

                if (\count($violations) >= 3) {
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * A document of between one and four rate groups.
     *
     * @return array{list<TaxableLine>, list<DocumentAdjustment>, string}
     */
    private function generateDocument(): array
    {
        $currency = ['SAR', 'EUR', 'JOD', 'JPY'][mt_rand(0, 3)];
        $taxable = ['15', '5', '21', '20', '14', '16'];
        $zero = [TaxCategory::ZeroRated, TaxCategory::Exempt, TaxCategory::OutOfScope, TaxCategory::ReverseCharge];

        $lines = [];

        foreach (range(1, mt_rand(1, 4)) as $ignored) {
            $isZero = mt_rand(0, 3) === 0;
            $category = $isZero ? $zero[mt_rand(0, 3)] : TaxCategory::Standard;
            $rate = $isZero ? '0' : $taxable[mt_rand(0, 5)];

            foreach (range(1, mt_rand(1, 5)) as $alsoIgnored) {
                $magnitude = 10 ** mt_rand(0, 5);
                $amount = (string) \Brick\Math\BigDecimal::ofUnscaledValue(mt_rand(0, 9999) * $magnitude, 4);

                $lines[] = TaxableLine::of(
                    Money::of(mt_rand(0, 9) === 0 ? '-'.$amount : $amount, $currency),
                    $category,
                    $rate,
                );
            }
        }

        $adjustments = [];

        if (mt_rand(0, 1) === 1) {
            $amount = (string) \Brick\Math\BigDecimal::ofUnscaledValue(mt_rand(1, 999999), 4);

            $adjustments[] = mt_rand(0, 1) === 1
                ? DocumentAdjustment::allowance(Money::of($amount, $currency))
                : DocumentAdjustment::charge(Money::of($amount, $currency));
        }

        return [$lines, $adjustments, $currency];
    }

    /**
     * @param  list<TaxableLine>  $lines
     * @param  list<DocumentAdjustment>  $adjustments
     */
    private function netOf(array $lines, array $adjustments, string $currency): Money
    {
        $net = Money::zero($currency);

        foreach ($lines as $line) {
            $net = $net->plus($line->net);
        }

        foreach ($adjustments as $adjustment) {
            $net = $net->plus($adjustment->signed());
        }

        return $net;
    }

    /**
     * @param  array<string, array{string, string}>  $entries
     * @return list<string>
     */
    private function expectTotals(
        TaxBreakdown $breakdown,
        string $net,
        string $tax,
        array $entries,
    ): array {
        $violations = [];

        if ((string) $breakdown->net->toPresentation() !== $net) {
            $violations[] = sprintf('the net is %s and should be %s',
                (string) $breakdown->net->toPresentation(), $net);
        }

        if ((string) $breakdown->tax->toPresentation() !== $tax) {
            $violations[] = sprintf('the tax is %s and should be %s',
                (string) $breakdown->tax->toPresentation(), $tax);
        }

        if (! $breakdown->satisfiesBrCo14()) {
            $violations[] = sprintf(
                'BR-CO-14 fails: the tax total is %s and the breakdown sums to %s',
                (string) $breakdown->tax, (string) $breakdown->sumOfEntries(),
            );
        }

        foreach ($entries as $key => [$taxable, $entryTax]) {
            [$category, $rate] = explode('@', $key);
            $entry = $breakdown->entryFor($category, $rate);

            if ($entry === null) {
                $violations[] = sprintf('there is no %s entry', $key);

                continue;
            }

            if ((string) $entry->taxableAmount->toPresentation() !== $taxable) {
                $violations[] = sprintf('%s: taxable is %s and should be %s',
                    $key, (string) $entry->taxableAmount->toPresentation(), $taxable);
            }

            if ((string) $entry->taxAmount->toPresentation() !== $entryTax) {
                $violations[] = sprintf('%s: tax is %s and should be %s',
                    $key, (string) $entry->taxAmount->toPresentation(), $entryTax);
            }
        }

        if ($breakdown->count() !== \count($entries)) {
            $violations[] = sprintf('the breakdown has %d entries and should have %d',
                $breakdown->count(), \count($entries));
        }

        return $violations;
    }

    private function calculator(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    private function policy(RoundingBasis $basis = RoundingBasis::PerRateGroup): RoundingPolicy
    {
        return RoundingPolicy::halfUp(Scale::Amount, $basis);
    }
}
