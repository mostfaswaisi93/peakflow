<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Hashing\ChainLink;
use App\Support\Hashing\ChainWalker;
use App\Support\Iso\CurrencyRegistry;
use App\Support\Iso\Iban;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Throwable;

/**
 * The worked examples from the specification, as executable checks.
 *
 * Every one of these appears in docs/ as prose with an expected number. This
 * class is what keeps the prose honest: if the documentation says a `JOD`
 * invoice stores 42.9629 and displays 42.963, that is asserted here against
 * the real code.
 *
 * Run by two callers - bin/verify and the PHPUnit wrappers in tests/Unit -
 * so the checks still run where the dev dependencies cannot be installed.
 *
 * @return list<string> from each method: violations, empty means pass
 */
final class WorkedExamples
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'per-rate-group rounding (BR-CO-14)' => $this->perRateGroupRounding(),
            'three-decimal currency: JOD' => $this->threeDecimalCurrency(),
            'zero-exponent currency: JPY' => $this->zeroExponentCurrency(),
            'apportionment residual' => $this->apportionmentResidual(),
            'money refusals' => $this->moneyRefusals(),
            'FX direction is never inverted' => $this->fxDirection(),
            'money on the wire is an object' => $this->moneyOnTheWire(),
            'currency exponents' => $this->currencyExponents(),
            'IBAN check digits' => $this->ibanCheckDigits(),
            'hash chain tamper detection' => $this->hashChainTamperDetection(),
        ];
    }

    /**
     * docs/architecture/money-and-precision.md - "why per-line rounding fails".
     *
     * Three lines of 10.03 at 15%: 4.51 computed per rate group, 4.50 computed
     * per line. One cent per document; across 50,000 documents a quarter it is
     * a difference between the ledger and the filed return that nobody can
     * locate, because every individual document looks correct.
     *
     * @return list<string>
     */
    public function perRateGroupRounding(): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);
        $lines = [Money::of('10.03', 'SAR'), Money::of('10.03', 'SAR'), Money::of('10.03', 'SAR')];

        $groupNet = Money::zero('SAR');
        foreach ($lines as $line) {
            $groupNet = $groupNet->plus($line);
        }

        $perGroup = $groupNet->multipliedBy('0.15', $policy)->toPresentation();

        $perLine = Money::zero('SAR');
        foreach ($lines as $line) {
            $perLine = $perLine->plus(Money::of(
                $line->amount->multipliedBy('0.15')->toScale(2, RoundingMode::HalfUp),
                'SAR',
            ));
        }

        return array_values(array_filter([
            $this->expect('group net', (string) $groupNet, '30.0900'),
            $this->expect('tax per rate group', (string) $perGroup, '4.51'),
            $this->expect('tax per line (the wrong answer)', (string) $perLine->toPresentation(), '4.50'),
        ]));
    }

    /** @return list<string> */
    public function threeDecimalCurrency(): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        $net = Money::ofRounded(BigDecimal::of('12.3456789')->multipliedBy('3'), 'JOD', $policy);
        $tax = $net->multipliedBy('0.16', $policy);
        $gross = $net->plus($tax);

        return array_values(array_filter([
            $this->expect('JOD line net stored', (string) $net, '37.0370'),
            $this->expect('JOD tax stored', (string) $tax, '5.9259'),
            $this->expect('JOD gross stored at 4dp', (string) $gross, '42.9629'),
            $this->expect('JOD gross displayed at exponent 3', (string) $gross->toPresentation(), '42.963'),
        ]));
    }

    /** @return list<string> */
    public function zeroExponentCurrency(): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);
        $net = Money::of('11430', 'JPY');
        $gross = $net->plus($net->multipliedBy('0.10', $policy));

        return array_values(array_filter([
            $this->expect('JPY gross stored at 4dp', (string) $gross, '12573.0000'),
            $this->expect('JPY gross displayed at exponent 0', (string) $gross->toPresentation(), '12573'),
        ]));
    }

    /** @return list<string> */
    public function apportionmentResidual(): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);
        $violations = [];

        $equal = Money::of('100.00', 'SAR')->allocate(['1', '1', '1'], $policy);
        $violations[] = $this->expect(
            'equal 3-way shares (residual to the first of equal largest)',
            implode(' / ', array_map(strval(...), $equal)),
            '33.3334 / 33.3333 / 33.3333',
        );
        $violations[] = $this->expect('equal 3-way sums back', (string) $this->sum($equal), '100.0000');

        $weighted = Money::of('100.00', 'SAR')->allocate(['70', '20', '10'], $policy);
        $violations[] = $this->expect('weighted sums back', (string) $this->sum($weighted), '100.0000');
        $violations[] = $this->expect('residual on the largest weight', (string) $weighted[0], '70.0000');

        $negative = Money::of('-100.00', 'SAR')->allocate(['1', '1', '1'], $policy);
        $violations[] = $this->expect('negative sums back', (string) $this->sum($negative), '-100.0000');

        return array_values(array_filter($violations));
    }

    /** @return list<string> */
    public function moneyRefusals(): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        return array_values(array_filter([
            $this->refuses('adding SAR to JOD', fn () => Money::of('1.00', 'SAR')->plus(Money::of('1.00', 'JOD'))),
            $this->refuses('a 5-decimal amount', fn () => Money::of('1.23456', 'SAR')),
            $this->refuses('an unknown currency', fn () => Money::of('1.00', 'ZZZ')),
            $this->refuses('a negative allocation weight', fn () => Money::of('10.00', 'SAR')->allocate(['-1', '2'], $policy)),
            $this->refuses('zero-total allocation weights', fn () => Money::of('10.00', 'SAR')->allocate(['0', '0'], $policy)),
        ]));
    }

    /** @return list<string> */
    public function fxDirection(): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);
        $rate = new Rate('USD', 'SAR', '3.750000000000', new DateTimeImmutable('2026-11-14'), 'central_bank');

        return array_values(array_filter([
            $this->expect('USD 1,000 converted to SAR', (string) $rate->convert(Money::of('1000.00', 'USD'), $policy), '3750.0000'),
            $this->refuses('asking a USD/SAR rate to convert SAR', fn () => $rate->convert(Money::of('1000.00', 'SAR'), $policy)),
            $this->refuses('asking a USD/SAR rate to convert EUR', fn () => $rate->convert(Money::of('1000.00', 'EUR'), $policy)),
        ]));
    }

    /** @return list<string> */
    public function moneyOnTheWire(): array
    {
        $json = json_encode(Money::of('1150.00', 'SAR'), JSON_THROW_ON_ERROR);

        $violations = [$this->expect(
            'money serializes as an object with a string amount',
            $json,
            '{"amount":"1150.0000","currency":"SAR","presentation_exponent":2}',
        )];

        if (preg_match('/"amount":\s*[0-9]/', $json) === 1) {
            $violations[] = 'money serialized a BARE NUMERIC amount, which a client will parse as a float';
        }

        return array_values(array_filter($violations));
    }

    /** @return list<string> */
    public function currencyExponents(): array
    {
        $expected = [
            'JOD' => 3, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'TND' => 3, 'LYD' => 3, 'IQD' => 3,
            'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0, 'XAF' => 0,
            'CLF' => 4, 'UYW' => 4,
            'USD' => 2, 'EUR' => 2, 'SAR' => 2, 'AED' => 2, 'EGP' => 2,
        ];

        $violations = [];
        foreach ($expected as $code => $exponent) {
            $violations[] = $this->expect($code.' exponent', (string) CurrencyRegistry::exponent($code), (string) $exponent);
        }

        foreach (['MGA', 'MRU'] as $code) {
            if (! CurrencyRegistry::isBase5($code)) {
                $violations[] = $code.' must be flagged base-5: its subunit is 1/5, so no power of ten represents it';
            }
        }

        return array_values(array_filter($violations));
    }

    /** @return list<string> */
    public function ibanCheckDigits(): array
    {
        $valid = [
            'JO94CBJO0010000000000131000302',
            'GB82WEST12345698765432',
            'DE89370400440532013000',
            'SA0380000000608010167519',
            'KW81CBKU0000000000001234560101',
            'BH67BMAG00001299123456',
            'TN5910006035183598478831',
        ];

        $invalid = [
            'GB82WEST12345698765431',   // one digit changed
            'DE89370400440532013001',   // one digit changed
            'XX1234567890',             // no such IBAN country
            'GB82WEST1234569876543',    // wrong length for GB
        ];

        $violations = [];

        foreach ($valid as $iban) {
            if (Iban::tryFrom($iban) === null) {
                $violations[] = sprintf('%s is a valid IBAN but was rejected', $iban);
            }
        }

        foreach ($invalid as $iban) {
            if (Iban::tryFrom($iban) !== null) {
                $violations[] = sprintf('%s is NOT a valid IBAN but was accepted', $iban);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function hashChainTamperDetection(): array
    {
        $documents = [];
        $previous = null;

        for ($i = 1; $i <= 5; $i++) {
            $fields = [
                'number' => sprintf('INV-2026-%06d', $i),
                'gross' => ['amount' => '100.0000', 'currency' => 'JOD'],
            ];
            $link = ChainLink::link($fields, $previous);
            $documents[] = ['reference' => $fields['number'], 'link' => $link, 'fields' => $fields];
            $previous = $link;
        }

        $walker = new ChainWalker();
        $violations = [];

        if (! $walker->walk($documents)->intact) {
            $violations[] = 'an untampered chain was reported as broken';
        }

        $altered = $documents;
        $altered[2]['fields']['gross']['amount'] = '90.0000';
        $verdict = $walker->walk($altered);

        if ($verdict->intact) {
            $violations[] = 'an ALTERED document was not detected';
        } elseif ($verdict->firstDivergenceIndex !== 3) {
            $violations[] = sprintf('alteration detected at index %s, expected 3', (string) $verdict->firstDivergenceIndex);
        }

        $removed = array_values(array_merge([$documents[0]], array_slice($documents, 2)));
        $verdict = $walker->walk($removed);

        if ($verdict->intact) {
            $violations[] = 'a REMOVED document was not detected';
        }

        return $violations;
    }

    /** @param list<Money> $parts */
    private function sum(array $parts): Money
    {
        $total = Money::zero($parts[0]->currency);

        foreach ($parts as $part) {
            $total = $total->plus($part);
        }

        return $total;
    }

    private function expect(string $label, string $actual, string $expected): ?string
    {
        return $actual === $expected
            ? null
            : sprintf('%s: got "%s", expected "%s"', $label, $actual, $expected);
    }

    private function refuses(string $label, callable $operation): ?string
    {
        try {
            $operation();
        } catch (Throwable) {
            return null;
        }

        return sprintf('%s was NOT refused', $label);
    }
}
