<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Money\Exceptions\UnknownRoundingBasis;
use App\Support\Money\Exceptions\UnknownRoundingMode;
use App\Support\Money\Money;
use App\Support\Money\RoundingBasis;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Tax\Services\TaxRounding;
use Throwable;

/**
 * The rounding policy, per jurisdiction, and proof it changes the answer - PF-081.
 *
 * ## What is actually being claimed
 *
 * That the policy does something. A configuration value that nothing reads is
 * a row in a file, and the way that fails is silent: every profile ships
 * half-up and per-rate-group today, so a check that asserted the shipped
 * profiles resolve correctly would pass with the entire lookup deleted.
 *
 * So two things are asserted separately. That the SHIPPED profiles produce the
 * numbers in `tests/Golden/rounding/jurisdictions.json`, which were produced
 * by the code and then written down by hand - and that a profile declaring
 * something else resolves to something else, set and read back here.
 *
 * ## Every golden example must DIFFER between the two bases
 *
 * An example where per-line and per-group agree demonstrates nothing, and
 * would sit in the fixture file looking like evidence. The check refuses one.
 */
final class RoundingPolicyRules
{
    private const GOLDEN = __DIR__.'/../Golden/rounding/jurisdictions.json';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'every shipped jurisdiction matches its committed worked example' => $this->theGoldenExamplesHold(),
            'the two bases give different, specified answers on the same lines' => $this->theBasesDiffer(),
            'a declared mode and basis are actually read' => $this->theDeclarationIsRead(),
            'an unknown profile falls back; an unknown basis refuses' => $this->fallbackAndRefusal(),
            'the scale comes from the currency, not from the jurisdiction' => $this->theScaleIsTheCurrency(),
            'every enabled profile declares a mode, a basis and a source' => $this->everyProfileIsConfigured(),
        ];
    }

    /** @return list<string> */
    public function theGoldenExamplesHold(): array
    {
        $violations = [];
        $rounding = app(TaxRounding::class);

        foreach ($this->examples() as $example) {
            $label = sprintf('%s (%s @ %s%%)', $example['profile'], $example['currency'], $example['rate']);
            $lines = array_map(
                static fn (string $amount): Money => Money::of($amount, $example['currency']),
                $example['lines'],
            );

            $net = (string) $rounding->netOf($lines);

            if ($net !== $example['net']) {
                $violations[] = sprintf('%s: net is %s and the fixture says %s', $label, $net, $example['net']);
            }

            /*
             | The profile's own policy, resolved from configuration - which is
             | the path a document takes.
             */
            $policy = $rounding->policyFor($example['profile'], $example['currency']);

            if ($policy->basis->value !== $example['basis']) {
                $violations[] = sprintf(
                    '%s resolves the basis %s and the fixture says %s. If the configuration changed on '.
                    'purpose, the fixture\'s expected tax changes with it',
                    $label, $policy->basis->value, $example['basis'],
                );
            }

            $tax = (string) $rounding->taxOn($lines, $example['rate'], $policy)->toPresentation($policy);

            if ($tax !== $example['tax']) {
                $violations[] = sprintf('%s: tax is %s and the fixture says %s', $label, $tax, $example['tax']);
            }

            // And both counterfactuals, so the fixture documents the choice.
            foreach ([RoundingBasis::PerRateGroup, RoundingBasis::PerLine] as $basis) {
                $under = $policy->withBasis($basis);
                $actual = (string) $rounding->taxOn($lines, $example['rate'], $under)->toPresentation($under);

                if ($actual !== $example[$basis->value]) {
                    $violations[] = sprintf(
                        '%s under %s: %s and the fixture says %s',
                        $label, $basis->value, $actual, $example[$basis->value],
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theBasesDiffer(): array
    {
        $violations = [];

        foreach ($this->examples() as $example) {
            if ($example['per_rate_group'] === $example['per_line']) {
                $violations[] = sprintf(
                    'the %s example gives %s under both bases, so it demonstrates nothing about the '.
                    'policy while sitting in the fixture file looking like evidence. Pick lines where '.
                    'the two answers differ',
                    $example['profile'], $example['per_rate_group'],
                );
            }
        }

        /*
         | And the drift is not one-directional.
         |
         | Worth asserting because a per-line total that was always higher
         | could be corrected afterwards with a rounding adjustment, and this
         | one cannot: it depends on the fractions of the individual lines.
         */
        $higher = 0;
        $lower = 0;

        foreach ($this->examples() as $example) {
            $group = (float) $example['per_rate_group'];
            $line = (float) $example['per_line'];
            $group > $line ? $higher++ : $lower++;
        }

        if ($higher === 0 || $lower === 0) {
            $violations[] = sprintf(
                'every example drifts the same way (%d higher per group, %d lower), which suggests the '.
                'fixtures were chosen to agree with each other rather than to show that the drift has '.
                'no direction',
                $higher, $lower,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDeclarationIsRead(): array
    {
        $violations = [];

        config(['jurisdictions.rounding.pf081-check' => [
            'mode' => 'half_even',
            'basis' => 'per_line',
        ]]);

        $policy = RoundingPolicy::forProfile('pf081-check', Scale::Amount);

        if ($policy->mode !== RoundingPolicy::halfEven(Scale::Amount)->mode) {
            $violations[] = sprintf(
                'a profile declaring half_even resolved %s, so the mode is not being read', $policy->describe(),
            );
        }

        if ($policy->basis !== RoundingBasis::PerLine) {
            $violations[] = sprintf(
                'a profile declaring per_line resolved %s, so the basis is not being read. Every shipped '.
                'profile declares per_rate_group, so this is the only check that would notice the lookup '.
                'being deleted',
                $policy->describe(),
            );
        }

        // A bare string is still a mode, which is what the configuration held
        // before the basis existed.
        config(['jurisdictions.rounding.pf081-string' => 'down']);
        $bare = RoundingPolicy::forProfile('pf081-string', Scale::Amount);

        if ($bare->mode !== RoundingPolicy::down(Scale::Amount)->mode) {
            $violations[] = 'a profile declaring a bare mode string is no longer read';
        }

        if ($bare->basis !== RoundingBasis::PerRateGroup) {
            $violations[] = 'a profile declaring only a mode does not get the EN 16931 default basis';
        }

        config(['jurisdictions.rounding.pf081-check' => null, 'jurisdictions.rounding.pf081-string' => null]);

        return $violations;
    }

    /** @return list<string> */
    public function fallbackAndRefusal(): array
    {
        $violations = [];

        $unknown = RoundingPolicy::forProfile('no-such-jurisdiction', Scale::Amount);

        if ($unknown->mode !== RoundingPolicy::halfUp(Scale::Amount)->mode
            || $unknown->basis !== RoundingBasis::PerRateGroup) {
            $violations[] = sprintf(
                'an unconfigured profile resolved %s rather than the platform default. Refusing to round '.
                'because a profile has no row would make a new jurisdiction unusable until somebody '.
                'noticed, and the noticing happens on an invoice',
                $unknown->describe(),
            );
        }

        /*
         | A basis somebody WROTE and spelled wrong is the opposite case: a
         | decision that was made and then silently discarded.
         */
        config(['jurisdictions.rounding.pf081-typo' => ['mode' => 'half_up', 'basis' => 'per_lines']]);

        try {
            $resolved = RoundingPolicy::forProfile('pf081-typo', Scale::Amount);
            $violations[] = sprintf(
                'the basis "per_lines" was accepted and resolved %s. A misspelled basis that falls back '.
                'is a cent on a return that nobody can trace',
                $resolved->describe(),
            );
        } catch (UnknownRoundingBasis) {
            // Correct.
        } catch (Throwable $e) {
            $violations[] = 'a misspelled basis was refused by the wrong thing: '.$e->getMessage();
        }

        config(['jurisdictions.rounding.pf081-typo' => ['mode' => 'half_sideways', 'basis' => 'per_line']]);

        try {
            RoundingPolicy::forProfile('pf081-typo', Scale::Amount);
            $violations[] = 'the mode "half_sideways" was accepted';
        } catch (UnknownRoundingMode) {
            // Correct.
        } catch (Throwable $e) {
            $violations[] = 'a misspelled mode was refused by the wrong thing: '.$e->getMessage();
        }

        config(['jurisdictions.rounding.pf081-typo' => null]);

        return $violations;
    }

    /**
     * The scale is the currency's exponent, in every jurisdiction.
     *
     * @return list<string>
     */
    public function theScaleIsTheCurrency(): array
    {
        $violations = [];
        $rounding = app(TaxRounding::class);

        $cases = [
            ['JPY', '100', '10', '10'],      // no subunit at all
            ['SAR', '100', '15', '15.00'],
            ['JOD', '100', '16', '16.000'],  // three decimals
        ];

        foreach ($cases as [$currency, $amount, $rate, $expected]) {
            $policy = $rounding->policyFor('generic', $currency);
            $tax = (string) $rounding
                ->taxOn([Money::of($amount, $currency)], $rate, $policy)
                ->toPresentation($policy);

            if ($tax !== $expected) {
                $violations[] = sprintf(
                    '%s %s at %s%% gave %s and should give %s. The number of decimals is ISO 4217\'s, '.
                    'not the jurisdiction\'s',
                    $currency, $amount, $rate, $tax, $expected,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function everyProfileIsConfigured(): array
    {
        $violations = [];
        $declared = config('jurisdictions.rounding');
        $declared = \is_array($declared) ? $declared : [];

        foreach (array_keys((array) config('jurisdictions.enabled', [])) as $profile) {
            $profile = (string) $profile;
            $row = $declared[$profile] ?? null;

            if (! \is_array($row)) {
                $violations[] = sprintf(
                    'profile "%s" declares no rounding row. It falls back to the platform default, which '.
                    'is correct and silent - so the omission is reported here rather than discovered on '.
                    'an invoice',
                    $profile,
                );

                continue;
            }

            foreach (['mode', 'basis', 'source'] as $key) {
                if (! \is_string($row[$key] ?? null) || trim((string) $row[$key]) === '') {
                    $violations[] = sprintf('profile "%s" declares no %s', $profile, $key);
                }
            }

            $source = (string) ($row['source'] ?? '');

            if ($source !== '' && ! str_contains($source, 'EN 16931') && ! str_contains($source, 'unverified')) {
                $violations[] = sprintf(
                    'profile "%s" cites "%s" for its rounding, which names neither EN 16931 nor the fact '.
                    'that the basis is unverified. The basis decides a cent per document and has to be '.
                    'traceable to something',
                    $profile, $source,
                );
            }
        }

        return $violations;
    }

    /** @return list<array{profile: string, note: string, currency: string, rate: string, lines: list<string>, net: string, per_rate_group: string, per_line: string, basis: string, tax: string}> */
    private function examples(): array
    {
        $contents = is_file(self::GOLDEN) ? file_get_contents(self::GOLDEN) : false;

        if ($contents === false) {
            throw new \RuntimeException('the golden rounding fixtures are missing: '.self::GOLDEN);
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! \is_array($decoded) || ! \is_array($decoded['examples'] ?? null) || $decoded['examples'] === []) {
            throw new \RuntimeException('the golden rounding fixtures hold no examples');
        }

        /** @var list<array{profile: string, note: string, currency: string, rate: string, lines: list<string>, net: string, per_rate_group: string, per_line: string, basis: string, tax: string}> */
        return array_values($decoded['examples']);
    }
}
