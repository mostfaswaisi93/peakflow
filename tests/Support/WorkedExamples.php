<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Enums\SubscriptionStatus;
use App\Tenant\Ledger\Enums\FiscalCalendar;
use Illuminate\Support\Carbon;
use App\Support\Mfa\Base32;
use App\Support\Mfa\Totp;
use App\Platform\Models\Subscription;
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
            'a subscription is valid THROUGH its end date' => $this->subscriptionBoundary(),
            'TOTP against the RFC 6238 test vectors' => $this->totpTestVectors(),
            'a fiscal year starting late in the month does not overflow' => $this->fiscalYearBoundaries(),
            'format then parse round-trips, for generated amounts' => $this->moneyRoundTrips(),
            'what a form may take, and what it must refuse' => $this->typedAmountsAreReadOrRefused(),
            'a jurisdiction profile resolves a rounding policy' => $this->roundingResolvesPerProfile(),
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
    /**
     * The off-by-one that cuts a paying customer off a day early.
     *
     * A subscription ending on the 31st is usable all day on the 31st. Get it
     * wrong one way and a customer who has paid is locked out, which generates
     * a support ticket they are entirely right to open; the other way and a
     * lapsed account gets a free day.
     *
     * Checked in memory, with no database: the rule is a property of the model
     * and wants to be checkable without provisioning anything.
     *
     * @return list<string>
     */
    public function subscriptionBoundary(): array
    {
        $violations = [];

        $on = function (string $endsOn): Subscription {
            $subscription = new Subscription();
            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'starts_on' => now()->subYear()->toDateString(),
                'ends_on' => $endsOn,
            ]);

            return $subscription;
        };

        $cases = [
            'the last day of the subscription' => [now()->toDateString(), true],
            'the day after it ended' => [now()->subDay()->toDateString(), false],
            'a month from now' => [now()->addMonth()->toDateString(), true],
            'a month ago' => [now()->subMonth()->toDateString(), false],
        ];

        foreach ($cases as $label => [$endsOn, $expected]) {
            $actual = $on($endsOn)->entitlesAccess();

            if ($actual !== $expected) {
                $violations[] = sprintf(
                    'a subscription ending %s (%s) %s access; it should%s',
                    $endsOn,
                    $label,
                    $actual ? 'entitles' : 'does not entitle',
                    $expected ? '' : ' not',
                );
            }
        }

        // And a status that does not entitle access overrides the dates
        // entirely - an expired row with a future end date is still expired,
        // because somebody or something expired it on purpose.
        $suspended = $on(now()->addYear()->toDateString());
        $suspended->forceFill(['status' => SubscriptionStatus::Suspended]);

        if ($suspended->entitlesAccess()) {
            $violations[] = 'a suspended subscription with a future end date still entitles access; '.
                'the status must override the dates';
        }

        return $violations;
    }

    /**
     * The published test vectors from RFC 6238, Appendix B.
     *
     * An authentication primitive verified only against itself is verified
     * against nothing: a consistent off-by-one in the counter packing, or a
     * truncation that reads from the wrong offset, produces codes that are
     * stable, self-consistent and rejected by every authenticator app in the
     * world. These are the numbers the standard publishes, so matching them
     * means the implementation agrees with Google Authenticator, 1Password and
     * Aegis rather than with itself.
     *
     * The secret is the ASCII string "12345678901234567890", base32-encoded,
     * which is what the RFC specifies for the SHA-1 column.
     *
     * @return list<string>
     */
    public function totpTestVectors(): array
    {
        $secret = Base32::encode('12345678901234567890');

        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            // Past 2038, which is where a 32-bit counter or a signed shift
            // would quietly start producing the wrong answer.
            20000000000 => '353130',
        ];

        $violations = [];

        foreach ($vectors as $time => $expected) {
            $actual = Totp::at($secret, $time);

            if ($actual !== $expected) {
                $violations[] = sprintf(
                    'RFC 6238 vector T=%d: got %s, the standard says %s',
                    $time,
                    $actual,
                    $expected,
                );
            }
        }

        // The window accepts one step either side and nothing further. Two
        // steps out must fail, or the window has been widened - which is the
        // usual response to support tickets and the wrong one.
        $now = 1111111111;
        $code = Totp::at($secret, $now);

        if (Totp::verify($secret, $code, $now) === null) {
            $violations[] = 'a code is not accepted at the moment it was generated';
        }

        if (Totp::verify($secret, $code, $now + Totp::PERIOD) === null) {
            $violations[] = 'a code is not accepted one step later, so a slow typist is refused';
        }

        if (Totp::verify($secret, $code, $now - Totp::PERIOD) === null) {
            $violations[] = 'a code is not accepted one step earlier, so a fast clock is refused';
        }

        if (Totp::verify($secret, $code, $now + (Totp::PERIOD * 3)) !== null) {
            $violations[] = sprintf(
                'a code is still accepted %d seconds later; the window has been widened beyond one step either side',
                Totp::PERIOD * 3,
            );
        }

        // The step is returned, not a boolean, because replay prevention needs
        // to know WHICH step was used.
        if (Totp::verify($secret, $code, $now) !== Totp::stepAt($now)) {
            $violations[] = 'verify() does not return the step the code belongs to, so a replay cannot be refused';
        }

        return $violations;
    }

    /**
     * A fiscal year starting on the 29th, 30th or 31st.
     *
     * `addMonth()` on 31 January gives 3 March, because January has 31 days
     * and February does not. A fiscal calendar built that way silently moves
     * the first days of March into February - every year, for every entity
     * whose year starts late in the month, and the only symptom is that two
     * periods disagree with the bank statement by a few days of takings.
     *
     * Pure arithmetic, so it is checked here rather than against a database:
     * the bug is in the date handling and does not need a tenant to prove.
     *
     * @return list<string>
     */
    public function fiscalYearBoundaries(): array
    {
        $violations = [];

        $cases = [
            // Start date => [first period ends, second period starts]
            '2026-01-31' => ['2026-02-27', '2026-02-28'],
            '2026-01-30' => ['2026-02-27', '2026-02-28'],
            '2026-01-29' => ['2026-02-27', '2026-02-28'],
            '2024-01-31' => ['2024-02-28', '2024-02-29'],
            '2026-01-01' => ['2026-01-31', '2026-02-01'],
        ];

        foreach ($cases as $start => [$firstEnds, $secondStarts]) {
            $periods = FiscalCalendar::Monthly->periodsFrom(Carbon::parse($start));

            if ($periods[0]['ends_on'] !== $firstEnds) {
                $violations[] = sprintf(
                    'a year starting %s has its first period ending %s, expected %s',
                    $start,
                    $periods[0]['ends_on'],
                    $firstEnds,
                );
            }

            if ($periods[1]['starts_on'] !== $secondStarts) {
                $violations[] = sprintf(
                    'a year starting %s has its second period starting %s, expected %s',
                    $start,
                    $periods[1]['starts_on'],
                    $secondStarts,
                );
            }
        }

        // Twelve periods, contiguous, with no gap and no overlap anywhere.
        $periods = FiscalCalendar::Monthly->periodsFrom(Carbon::parse('2026-01-31'));

        if (count($periods) !== 12) {
            $violations[] = sprintf('a monthly year produced %d periods, not 12', count($periods));
        }

        for ($i = 1; $i < count($periods); $i++) {
            $previousEnd = Carbon::parse($periods[$i - 1]['ends_on']);
            $thisStart = Carbon::parse($periods[$i]['starts_on']);

            if (! $previousEnd->copy()->addDay()->isSameDay($thisStart)) {
                $violations[] = sprintf(
                    'period %d ends %s and period %d starts %s, leaving a gap or an overlap - a posting on a '.
                    'day in between would belong to no period at all',
                    $i,
                    $periods[$i - 1]['ends_on'],
                    $i + 1,
                    $periods[$i]['starts_on'],
                );
            }
        }

        return $violations;
    }

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

    /**
     * `format` then `parse` round-trips, for generated amounts - PF-006.
     *
     * ## Why generated and not a list
     *
     * The list somebody writes contains the cases they thought of, and the
     * interesting ones here are combinations: a three-decimal currency in an
     * Arabic locale with a negative amount, a zero-exponent currency with a
     * thousands separator, an amount whose formatted form contains a
     * non-breaking space that ICU emitted and nothing else would think to
     * type.
     *
     * So the amounts, the currencies and the locales are crossed, and the
     * seed is printed on failure.
     *
     * ## The round trip is of the DISPLAYED value
     *
     * `format()` renders the currency's own exponent, so 1234.5678 in SAR
     * displays as 1,234.57 and reads back as 1234.5700. Claiming the stored
     * value round-trips would make the property false for every amount with
     * more precision than its currency shows - which is most of them in a
     * ledger that stores four places.
     *
     * ## And the failures found by writing it
     *
     * ICU parses as far as it can and reports where it stopped, so `about
     * 12k` read back as 12 and an Arabic-formatted amount under an English
     * locale read back as its first two digits. Both were accepted before
     * this check required ICU to consume the whole string.
     *
     * @return list<string>
     */
    public function moneyRoundTrips(): array
    {
        $violations = [];
        $seed = random_int(1, PHP_INT_MAX);
        mt_srand($seed);

        $currencies = ['SAR', 'USD', 'EUR', 'JOD', 'KWD', 'JPY', 'KRW', 'GBP'];
        $locales = ['en', 'ar'];

        $amounts = ['0.0100', '1.0000', '999999999.9900'];

        for ($i = 0; $i < 40; $i++) {
            $units = mt_rand(0, 999_999_999);
            $fraction = mt_rand(0, 9999);

            $amounts[] = sprintf('%d.%04d', $units, $fraction);
            $amounts[] = sprintf('-%d.%04d', $units, $fraction);
        }

        $previous = app()->getLocale();

        try {
            foreach ($locales as $locale) {
                app()->setLocale($locale);

                foreach ($currencies as $currency) {
                    foreach ($amounts as $amount) {
                        $money = Money::of($amount, $currency);
                        $formatted = $money->format($locale);
                        $read = Money::parse($formatted, $currency, $locale);

                        if ($read === null) {
                            $violations[] = sprintf(
                                'seed %d: %s %s formatted as "%s" in %s and could not be read back',
                                $seed,
                                $amount,
                                $currency,
                                $formatted,
                                $locale,
                            );

                            continue;
                        }

                        if (! $money->toPresentation()->isEqualTo($read->toPresentation())) {
                            $violations[] = sprintf(
                                'seed %d: %s %s formatted as "%s" in %s and read back as %s',
                                $seed,
                                $amount,
                                $currency,
                                $formatted,
                                $locale,
                                (string) $read->amount,
                            );
                        }

                        if (! $money->roundTrips($locale)) {
                            $violations[] = sprintf(
                                'seed %d: roundTrips() disagrees with its own parse for %s %s in %s',
                                $seed,
                                $amount,
                                $currency,
                                $locale,
                            );
                        }

                        if (count($violations) >= 6) {
                            return $violations;
                        }
                    }
                }
            }
        } finally {
            app()->setLocale($previous);
        }

        return $violations;
    }

    /**
     * What a form may take, and what it must refuse - PF-006.
     *
     * The refusals matter more than the acceptances. `about 12k` parsed as 12
     * before the character set was whitelisted, and a form accepting it is a
     * journal line for twelve riyals that nobody can trace.
     *
     * @return list<string>
     */
    public function typedAmountsAreReadOrRefused(): array
    {
        $violations = [];

        $accepted = [
            '1,500.00' => '1500.0000',
            '1 500.00' => '1500.0000',
            '١٢٬٥٠٠٫٥٠' => '12500.5000',
            '۱۲۵۰۰٫۵۰' => '12500.5000',
            '(1,234.56)' => '-1234.5600',
            '1234.56 SAR' => '1234.5600',
            '-0.01' => '-0.0100',
            '.5' => '0.5000',
        ];

        $refused = ['about 12k', '12k', '', 'SAR', '1.2.3', '١٢ ألف', '12,00,000x'];

        foreach ($accepted as $typed => $expected) {
            $read = Money::parse((string) $typed, 'SAR', 'en');

            if ($read === null) {
                $violations[] = sprintf('"%s" was refused and is a number somebody typed', $typed);

                continue;
            }

            if ((string) $read->amount !== $expected) {
                $violations[] = sprintf(
                    '"%s" read as %s where %s belongs',
                    $typed,
                    (string) $read->amount,
                    $expected,
                );
            }
        }

        foreach ($refused as $typed) {
            $read = Money::parse($typed, 'SAR', 'en');

            if ($read !== null) {
                $violations[] = sprintf(
                    '"%s" was read as %s. A figure the software invented is a figure nobody can trace',
                    $typed,
                    (string) $read->amount,
                );
            }
        }

        return $violations;
    }

    /**
     * A jurisdiction profile resolves a rounding policy - PF-006.
     *
     * @return list<string>
     */
    public function roundingResolvesPerProfile(): array
    {
        $violations = [];
        $declared = (array) config('jurisdictions.rounding', []);

        if ($declared === []) {
            return ['no jurisdiction profile declares a rounding mode, so the resolution is a constant'];
        }

        foreach (array_keys((array) config('jurisdictions.enabled', [])) as $profile) {
            if (! array_key_exists((string) $profile, $declared)) {
                $violations[] = sprintf(
                    'profile "%s" is listed as a jurisdiction and declares no rounding mode. It falls '.
                    'back to the platform default, which is correct and silent - so the omission is '.
                    'reported here rather than discovered on an invoice',
                    (string) $profile,
                );
            }
        }

        /*
         | An unknown profile falls back rather than throwing.
         |
         | Refusing to round because a profile forgot to declare a mode would
         | make a new jurisdiction unusable until somebody noticed, and the
         | noticing would happen on an invoice.
         */
        $unknown = RoundingPolicy::forProfile('no-such-profile', Scale::Amount);

        if ($unknown->mode !== RoundingPolicy::halfUp(Scale::Amount)->mode) {
            $violations[] = 'an unknown profile does not fall back to the platform default';
        }

        /*
         | And a declared mode is actually read.
         |
         | Every shipped profile declares half-up today, so asserting the
         | resolution against them would pass with the lookup deleted. This
         | sets one and reads it back.
         */
        config(['jurisdictions.rounding.check-profile' => 'half_even']);

        $resolved = RoundingPolicy::forProfile('check-profile', Scale::Amount);

        if ($resolved->mode !== RoundingPolicy::halfEven(Scale::Amount)->mode) {
            $violations[] = sprintf(
                'a profile declaring half_even resolved to %s, so the declaration is not being read',
                $resolved->describe(),
            );
        }

        return $violations;
    }
}
