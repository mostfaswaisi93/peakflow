<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Iso\CountryRegistry;
use App\Support\Iso\CurrencyRegistry;
use App\Support\Iso\Data\VendoredRegistry;
use App\Support\Iso\Exceptions\RegistryDataUnusable;
use App\Support\Iso\LanguageRegistry;
use App\Support\Iso\UnitDimension;
use App\Support\Iso\UnitRegistry;
use App\Support\Lang\NumberingSystem;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Locale;
use MessageFormatter;
use NumberFormatter;
use Throwable;

/**
 * The vendored standards data, and what it is allowed to say - PF-007.
 *
 * ## Why a registry needs checks at all
 *
 * Because it is data that looks like configuration and behaves like code. A
 * currency's exponent decides what every invoice in that currency rounds to,
 * and the whole value of the number is in one character: `JOD` at 3 places is
 * `1,234.567` and at 2 places is `1,234.57`, which is a 1000x error on a
 * figure that still looks like money and still balances against itself.
 *
 * So the 36 currencies whose exponent is not 2 are asserted BY NAME here,
 * from literals written in this file. That is the point: if the check derived
 * them from the file it would assert the file equals itself.
 *
 * ## The divergence that is the interesting one
 *
 * ICU ships with PHP and has its own currency data, from CLDR - which makes it
 * a second source, and the first thing a second source does is disagree.
 *
 * It disagrees 21 times, and the disagreements are not errors. CLDR publishes
 * the fraction digits a currency is USED with, and deliberately departs from
 * ISO 4217 where inflation has retired a subunit: nobody prices in Iraqi fils
 * any more, so CLDR says IQD has 0 decimals where ISO 4217 says 3. Both are
 * right about different questions.
 *
 * PeakFlow answers ISO 4217's question, because the receiving systems do:
 * EN 16931 and ISO 20022 validate the minor unit against ISO 4217, and an
 * IQD amount rendered to CLDR's 0 decimals is rejected by a customer's
 * software rather than by ours. So the divergences are enumerated and frozen.
 * A new one fails this suite, which is a prompt to look rather than a verdict
 * - an ICU upgrade is the likeliest cause, and the question it raises is
 * whether a currency's subunit has actually been retired.
 */
final class IsoRegistryRules
{
    /**
     * Every currency whose exponent is not 2, written out.
     *
     * From the ISO 4217 code list, not from the file under test. Four of the
     * three-decimal currencies are target markets, which is why this is a
     * list and not a spot check.
     *
     * @var array<string, int>
     */
    private const UNUSUAL_EXPONENTS = [
        // Three decimals. The normal case in the Gulf and the Levant.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        // Four decimals.
        'CLF' => 4, 'UYW' => 4,
        // No subunit at all.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0,
        'XAF' => 0, 'XOF' => 0, 'XPF' => 0, 'XDR' => 0, 'XSU' => 0, 'XUA' => 0,
        // The metals and the "no currency" code, which have no subunit either.
        'XAU' => 0, 'XAG' => 0, 'XPT' => 0, 'XPD' => 0, 'XXX' => 0,
        // Base-5 subunits. ISO exponent 2, subunit a fifth - see below.
        'MGA' => 2, 'MRU' => 2,
    ];

    /**
     * The two currencies no power of ten describes.
     *
     * One ariary is five iraimbilanja; one ouguiya is five khoums. Their ISO
     * exponent is 2 by convention, so a reader that computed the subunit
     * divisor as `10 ** exponent` would be wrong for exactly these two and
     * right for the other 172 - which is why the divisor is a column.
     *
     * @var list<string>
     */
    private const BASE_5 = ['MGA', 'MRU'];

    /**
     * Where CLDR's fraction digits differ from ISO 4217's, as of ICU 74.
     *
     * `code => [iso, cldr]`. Frozen deliberately: see the class docblock.
     *
     * @var array<string, array{int, int}>
     */
    private const CLDR_DIVERGENCES = [
        'AFN' => [2, 0], 'ALL' => [2, 0], 'IQD' => [3, 0], 'IRR' => [2, 0], 'KPW' => [2, 0],
        'LAK' => [2, 0], 'LBP' => [2, 0], 'MGA' => [2, 0], 'MMK' => [2, 0], 'RSD' => [2, 0],
        'SOS' => [2, 0], 'SYP' => [2, 0], 'YER' => [2, 0],
        'XDR' => [0, 2], 'XSU' => [0, 2], 'XUA' => [0, 2],
        'XAU' => [0, 2], 'XAG' => [0, 2], 'XPT' => [0, 2], 'XPD' => [0, 2], 'XXX' => [0, 2],
    ];

    /** Country codes checked by hand, with their alpha-3 and numeric forms. */
    /** @var array<string, array{string, string}> */
    private const COUNTRY_SPOT_CHECKS = [
        'SA' => ['SAU', '682'],
        'JO' => ['JOR', '400'],
        'AE' => ['ARE', '784'],
        'EG' => ['EGY', '818'],
        'AL' => ['ALB', '008'],  // Leading zeros: `008` is Albania, `8` is nothing.
        'GB' => ['GBR', '826'],
        'US' => ['USA', '840'],
    ];

    private string $temporary = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $this->temporary = sys_get_temp_dir().'/peakflow-iso-'.Str::random(8);
        mkdir($this->temporary, 0o700, true);

        try {
            return [
                'the data on disk is the data somebody recorded' => $this->provenanceAgreesWithDisk(),
                'every registry says where it came from, and what was not checked' => $this->everyEntryStatesItsProvenance(),
                'no registry carries its own data in a constant' => $this->noRegistryHoldsItsOwnData(),
                'every unusual exponent is named, not derived' => $this->theUnusualExponentsAreNamed(),
                'the two base-5 subunits are not computed from a power of ten' => $this->baseFiveIsNotComputed(),
                'every code is one ICU also knows' => $this->everyCodeIsKnownToIcu(),
                'where CLDR disagrees with ISO 4217, the disagreement is known' => $this->cldrDivergencesAreKnown(),
                'money renders the ISO exponent, never CLDR\'s' => $this->moneyRendersTheIsoExponent(),
                'a malformed row makes the file unusable rather than the data wrong' => $this->theLoaderRefusesBadRows(),
                'the digit shape is built in exactly one place' => $this->digitShapeIsBuiltInOnePlace(),
                'a configured `latn` actually produces Latin digits' => $this->latnIsWrittenOut(),
                'every served locale is one the product is translated into' => $this->servedLocalesAreReal(),
                'Arabic-first survives an empty environment' => $this->theDefaultLocaleIsServed(),
                'a unit knows what it measures, and refuses the ones it does not' => $this->unitsKnowWhatTheyMeasure(),
            ];
        } finally {
            foreach (glob($this->temporary.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->temporary);
        }
    }

    /** @return list<string> */
    private function provenanceAgreesWithDisk(): array
    {
        return VendoredRegistry::disagreements();
    }

    /** @return list<string> */
    private function everyEntryStatesItsProvenance(): array
    {
        $required = ['standard', 'publisher', 'obtained', 'rows', 'cross_checked', 'unverified', 'sha256'];
        $problems = [];

        foreach (VendoredRegistry::manifestFiles() as $file => $entry) {
            foreach ($required as $key) {
                if (! isset($entry[$key]) || $entry[$key] === '' || $entry[$key] === null) {
                    $problems[] = sprintf(
                        '%s has no "%s" in provenance.json. "%s" is required because a registry whose '.
                        'source, or whose UNVERIFIED part, is unstated is one nobody can answer a question '.
                        'about when a figure is disputed.',
                        $file,
                        $key,
                        $key,
                    );
                }
            }

            $name = str_replace('.tsv', '', (string) $file);

            if (! isset($entry['rows'])) {
                continue;
            }

            $actual = \count(VendoredRegistry::rows($name));

            if ((int) $entry['rows'] !== $actual) {
                $problems[] = sprintf(
                    'provenance.json says %s has %d rows; it has %d. The count is recorded so that a row '.
                    'lost to a bad merge is visible as a number rather than as a currency that stopped '.
                    'existing.',
                    $file,
                    (int) $entry['rows'],
                    $actual,
                );
            }
        }

        return $problems;
    }

    /**
     * No registry class may carry its data inline any more.
     *
     * This is the rule PF-007 actually asks for, so it is enforced rather than
     * described. The classes held packed string constants - 174 currencies on
     * 30 lines - and the packing was what made them unreviewable.
     *
     * @return list<string>
     */
    private function noRegistryHoldsItsOwnData(): array
    {
        $problems = [];
        $base = \dirname(__DIR__, 2);

        foreach (glob($base.'/app/Support/Iso/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/const\s+PACKED\s*=/', $source) === 1) {
                $problems[] = sprintf(
                    '%s still declares a PACKED constant. The registries are vendored files with a '.
                    'provenance record; a constant beside them is a second source that no manifest '.
                    'covers and no digest protects.',
                    basename($path),
                );
            }

            /*
             | Any single string literal long enough to be a table.
             |
             | Catches the packed form coming back under another name, which
             | is what would happen if somebody "optimised" a file read away.
             */
            if (preg_match('/\'[A-Z0-9:|,\.\s]{400,}\'/', $source) === 1) {
                $problems[] = sprintf(
                    '%s contains a string literal long enough to be a data table. If it is one, it belongs '.
                    'in database/iso with an entry in provenance.json.',
                    basename($path),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theUnusualExponentsAreNamed(): array
    {
        $problems = [];

        foreach (self::UNUSUAL_EXPONENTS as $code => $expected) {
            if (! CurrencyRegistry::exists($code)) {
                $problems[] = sprintf('%s is not in the currency registry at all.', $code);

                continue;
            }

            $actual = CurrencyRegistry::exponent($code);

            if ($actual !== $expected) {
                $problems[] = sprintf(
                    '%s has exponent %d; ISO 4217 says %d. At %d places an amount in this currency is '.
                    'out by a factor of %s, and it still looks like money.',
                    $code,
                    $actual,
                    $expected,
                    $actual,
                    10 ** abs($expected - $actual),
                );
            }
        }

        /*
         | And the other direction: nothing may have become unusual without
         | being written down here. A currency that quietly acquired a third
         | decimal place would otherwise change what every invoice in it
         | rounds to, with no line in any diff that says so.
         */
        foreach (CurrencyRegistry::all() as $code => $meta) {
            $unusual = $meta['exponent'] !== 2 || \in_array($code, self::BASE_5, true);

            if ($unusual && ! isset(self::UNUSUAL_EXPONENTS[$code])) {
                $problems[] = sprintf(
                    '%s has exponent %d but is not in this suite\'s list of unusual currencies. Either the '.
                    'file is wrong or the list is out of date - and the list is what an invoice is checked '.
                    'against.',
                    $code,
                    $meta['exponent'],
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function baseFiveIsNotComputed(): array
    {
        $problems = [];

        foreach (self::BASE_5 as $code) {
            if (CurrencyRegistry::subunitDivisor($code) !== 5) {
                $problems[] = sprintf(
                    '%s has a subunit divisor of %d. One ariary is five iraimbilanja and one ouguiya is '.
                    'five khoums; a divisor of 10^exponent is right for 172 currencies and wrong for '.
                    'these two, which is why it is a column and not a calculation.',
                    $code,
                    CurrencyRegistry::subunitDivisor($code),
                );
            }

            if (! CurrencyRegistry::isBase5($code)) {
                $problems[] = sprintf('%s does not report itself as base-5.', $code);
            }
        }

        foreach (CurrencyRegistry::all() as $code => $meta) {
            if (\in_array($code, self::BASE_5, true)) {
                continue;
            }

            $expected = 10 ** $meta['exponent'];

            if ($meta['subunit_divisor'] !== $expected) {
                $problems[] = sprintf(
                    '%s has exponent %d and subunit divisor %d, which do not agree. Only MGA and MRU are '.
                    'allowed to disagree, and both are listed.',
                    $code,
                    $meta['exponent'],
                    $meta['subunit_divisor'],
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function everyCodeIsKnownToIcu(): array
    {
        $problems = [];

        foreach (self::COUNTRY_SPOT_CHECKS as $alpha2 => [$alpha3, $numeric]) {
            if (CountryRegistry::alpha3($alpha2) !== $alpha3) {
                $problems[] = sprintf(
                    '%s has alpha-3 %s; ISO 3166-1 says %s.',
                    $alpha2,
                    (string) CountryRegistry::alpha3($alpha2),
                    $alpha3,
                );
            }

            if (CountryRegistry::numeric($alpha2) !== $numeric) {
                $problems[] = sprintf(
                    '%s has numeric code "%s"; ISO 3166-1 says "%s". The leading zeros are part of it - '.
                    '"008" is Albania and "8" is nothing, and the numeric form is what the e-invoicing '.
                    'and ISO 20022 payloads carry.',
                    $alpha2,
                    (string) CountryRegistry::numeric($alpha2),
                    $numeric,
                );
            }
        }

        foreach (array_keys(CountryRegistry::all()) as $alpha2) {
            $name = Locale::getDisplayRegion('-'.$alpha2, 'en');

            if (! \is_string($name) || $name === '' || $name === $alpha2) {
                $problems[] = sprintf(
                    '%s is in countries.tsv but ICU does not recognise it as a region. ICU is the only '.
                    'second source this environment has for ISO 3166-1, so a code it rejects is a '.
                    'transcription error until somebody shows otherwise.',
                    $alpha2,
                );
            }
        }

        foreach (CurrencyRegistry::codes() as $code) {
            $name = Locale::getDisplayName('@currency='.$code, 'en');

            if (! \is_string($name) || $name === '' || $name === $code) {
                $problems[] = sprintf(
                    '%s is in currencies.tsv but ICU does not know it. See above - a code with no second '.
                    'source is a transcription error until shown otherwise.',
                    $code,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function cldrDivergencesAreKnown(): array
    {
        $problems = [];
        $found = [];

        foreach (CurrencyRegistry::all() as $code => $meta) {
            $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $code);
            $cldr = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

            if ($cldr === false || $cldr === $meta['exponent']) {
                continue;
            }

            $found[$code] = [$meta['exponent'], $cldr];
        }

        foreach ($found as $code => [$iso, $cldr]) {
            if (! isset(self::CLDR_DIVERGENCES[$code])) {
                $problems[] = sprintf(
                    'CLDR now disagrees with ISO 4217 about %s: ISO %d, CLDR %d, and this suite does not '.
                    'know about it. An ICU upgrade is the likeliest cause. The question to answer is '.
                    'whether the subunit has actually been retired in practice - and the answer does not '.
                    'change what PeakFlow renders, because EN 16931 and ISO 20022 validate against ISO 4217.',
                    $code,
                    $iso,
                    $cldr,
                );

                continue;
            }

            if (self::CLDR_DIVERGENCES[$code] !== [$iso, $cldr]) {
                $problems[] = sprintf(
                    'The %s divergence has moved: recorded as ISO %d / CLDR %d, now ISO %d / CLDR %d.',
                    $code,
                    self::CLDR_DIVERGENCES[$code][0],
                    self::CLDR_DIVERGENCES[$code][1],
                    $iso,
                    $cldr,
                );
            }
        }

        foreach (self::CLDR_DIVERGENCES as $code => $pair) {
            if (! isset($found[$code])) {
                $problems[] = sprintf(
                    '%s is recorded as a CLDR divergence (ISO %d / CLDR %d) and no longer is. Remove it, '.
                    'so the list stays a list of real ones.',
                    $code,
                    $pair[0],
                    $pair[1],
                );
            }
        }

        return $problems;
    }

    /**
     * The consequence check for the divergence above.
     *
     * IQD is the one that matters: ISO 4217 gives it three decimals and CLDR
     * gives it none, so a formatter left to its own defaults renders an Iraqi
     * invoice as `1,235` where `1,234.568` belongs. The guard is the explicit
     * MIN/MAX fraction digits in Money::format, and this is what fires if
     * somebody removes them as redundant.
     *
     * @return list<string>
     */
    private function moneyRendersTheIsoExponent(): array
    {
        $problems = [];

        /*
         | With the symbol, because that is the form that breaks.
         |
         | Measured, by removing the explicit fraction digits from
         | Money::format and reading the output: IQD rendered "IQD 1,235" and
         | AFN rendered "AFN 1,235", because ICU's CURRENCY style applies
         | CLDR's digits and CLDR gives both of them none. JOD stayed right,
         | which is the trap - CLDR agrees with ISO about JOD, so a spot check
         | on the obvious three-decimal currency proves nothing. Only the 21
         | divergent codes break, and this is two of them.
         */
        $withSymbol = [
            'IQD' => '1,234.568',
            'AFN' => '1,234.57',
            'JOD' => '1,234.568',
            'SAR' => '1,234.57',
        ];

        foreach ($withSymbol as $code => $expected) {
            $rendered = Money::of('1234.5678', $code)->format('en');

            /*
             | Compared on the digits alone.
             |
             | ICU separates the code from the amount with a no-break space,
             | and which one it uses is a CLDR detail that changes between
             | versions - so comparing the whole string would make this check
             | fail on an ICU upgrade for a reason that has nothing to do with
             | what it is asserting. The claim is about the decimals.
             */
            if ($this->digitsOf($rendered) !== $this->digitsOf($expected)) {
                $problems[] = sprintf(
                    '%s 1234.5678 rendered as "%s"; the ISO 4217 exponent gives "%s". ICU\'s currency '.
                    'style uses CLDR\'s fraction digits unless told otherwise, and an e-invoice with the '.
                    'wrong number of decimals is rejected by the receiving system rather than by ours.',
                    $code,
                    $rendered,
                    $expected,
                );
            }
        }

        /*
         | And the minimum, which is the other half of the same guard.
         |
         | Without it an amount of exactly 1234.50 renders as "1,234.5" - a
         | money column where some rows have two decimals and some have one,
         | on a document somebody is asked to pay.
         */
        $trailing = [
            'SAR' => '1,234.50',
            'JOD' => '1,234.500',
            'JPY' => '1,235',
        ];

        foreach ($trailing as $code => $expected) {
            $rendered = Money::of('1234.50', $code)->format('en', false);

            if ($this->digitsOf($rendered) !== $this->digitsOf($expected)) {
                $problems[] = sprintf(
                    '%s 1234.50 rendered as "%s" rather than "%s". The trailing zeros are the currency\'s '.
                    'exponent; dropping them gives a money column where some rows have two decimals and '.
                    'some have one.',
                    $code,
                    $rendered,
                    $expected,
                );
            }
        }

        return $problems;
    }

    /** The figure, with the symbol, the code and ICU's choice of space taken off. */
    private function digitsOf(string $rendered): string
    {
        return (string) preg_replace('/[^0-9.,]/u', '', $rendered);
    }

    /**
     * Five ways a vendored file can be wrong, each refused.
     *
     * The point of each is that the alternative is silent. A short row would
     * default an exponent to 0 or 2; a padded code would become a currency
     * that does not exist; a duplicate key means a reviewed change to the
     * loser has no effect at all.
     *
     * @return list<string>
     */
    private function theLoaderRefusesBadRows(): array
    {
        $problems = [];

        $cases = [
            'a row short of a field' => "code\tnumeric\texponent\nJOD\t400\n",
            'a row with an extra field' => "code\tnumeric\texponent\nJOD\t400\t3\t3\n",
            'a whitespace-padded code' => "code\tnumeric\texponent\n JOD\t400\t3\n",
            'a duplicate key' => "code\tnumeric\texponent\nJOD\t400\t3\nJOD\t400\t2\n",
            'nothing but a header' => "code\tnumeric\texponent\n",
        ];

        foreach ($cases as $description => $contents) {
            $refused = false;

            try {
                $this->parseProbe('probe', $contents);
            } catch (RegistryDataUnusable) {
                $refused = true;
            } catch (Throwable $e) {
                $problems[] = sprintf(
                    '%s was refused with %s rather than RegistryDataUnusable: %s',
                    $description,
                    $e::class,
                    $e->getMessage(),
                );

                continue;
            }

            if (! $refused) {
                $problems[] = sprintf(
                    '%s was accepted. The row would then be read with a missing or wrong field, and a '.
                    'currency exponent that defaults is a currency that rounds wrongly on every document.',
                    $description,
                );
            }
        }

        /*
         | And the registries' own value checks, which are the layer below.
         |
         | A structurally perfect row can still say the exponent is "two" or
         | the direction is "sideways", and both cast or compare to something
         | usable - 0 and `ltr` - which is how a bad row becomes a wrong page
         | rather than an error.
         */
        $valueCases = [
            'an exponent that is not a digit' => ['currencies', "code\tnumeric\texponent\tsubunit_divisor\nJOD\t400\ttwo\t1000\n"],
            'a two-letter currency code' => ['currencies', "code\tnumeric\texponent\tsubunit_divisor\nJO\t400\t3\t1000\n"],
            'a numeric country code that lost its leading zeros' => ['countries', "alpha2\talpha3\tnumeric\nAL\tALB\t8\n"],
            'a direction that is not ltr or rtl' => ['languages', "tag\tdirection\tnumbering\tendonym\tplurals\nar\tsideways\tarab\tالعربية\tone,other\n"],
            'plural categories without `other`' => ['languages', "tag\tdirection\tnumbering\tendonym\tplurals\nar\trtl\tarab\tالعربية\tone,two\n"],
            'a dimension the enum does not have' => ['units', "code\tdimension\ten\tar\nHUR\tvelocity\thour\tساعة\n"],
        ];

        foreach ($valueCases as $description => [$registry, $contents]) {
            $refused = false;

            try {
                $this->loadInto($registry, $contents);
            } catch (RegistryDataUnusable) {
                $refused = true;
            } catch (Throwable $e) {
                $problems[] = sprintf('%s raised %s: %s', $description, $e::class, $e->getMessage());

                continue;
            }

            if (! $refused) {
                $problems[] = sprintf(
                    '%s was accepted by the %s registry. A value that casts to something usable is the '.
                    'worst kind of bad data: it produces a wrong document rather than an error.',
                    $description,
                    $registry,
                );
            }
        }

        return $problems;
    }

    /**
     * Parse a probe through the real reader.
     *
     * The reader resolves its own directory, so a probe cannot be fed to it
     * as a file. Reflection on the private parser is deliberate: the
     * alternative is a second copy of the parsing rules in the test, which
     * would pass while the real one was broken.
     *
     * @return list<array<string, string>>
     */
    private function parseProbe(string $name, string $contents): array
    {
        $reflection = new \ReflectionMethod(VendoredRegistry::class, 'parse');

        /** @var list<array<string, string>> $rows */
        $rows = $reflection->invoke(null, $name, $contents);

        return $rows;
    }

    /**
     * Run a probe file's rows through a registry's value checks.
     *
     * Same reasoning as above: the registries cache their table in a static,
     * so the probe is pushed into that static, the registry is asked to read
     * it, and the static is restored. Nothing else can exercise the value
     * checks against a row that is structurally valid and semantically wrong.
     */
    private function loadInto(string $registry, string $contents): void
    {
        $class = match ($registry) {
            'currencies' => CurrencyRegistry::class,
            'countries' => CountryRegistry::class,
            'languages' => LanguageRegistry::class,
            'units' => UnitRegistry::class,
            default => throw new \LogicException('No such registry: '.$registry),
        };

        $parsedProperty = new \ReflectionProperty(VendoredRegistry::class, 'parsed');
        $tableProperty = new \ReflectionProperty($class, 'table');

        /** @var array<string, list<array<string, string>>> $savedParsed */
        $savedParsed = $parsedProperty->getValue();
        $savedTable = $tableProperty->getValue();

        try {
            $parsedProperty->setValue(null, [$registry => $this->parseProbe($registry, $contents)]);
            $tableProperty->setValue(null, null);

            $class::all();
        } finally {
            $parsedProperty->setValue(null, $savedParsed);
            $tableProperty->setValue(null, $savedTable);
        }
    }

    /** @return list<string> */
    private function digitShapeIsBuiltInOnePlace(): array
    {
        $problems = [];
        $base = \dirname(__DIR__, 2);
        $sites = [];

        foreach ($this->sourceFiles($base.'/app') as $path) {
            /*
             | Tokenised, not grepped.
             |
             | The first version of this matched the literal `'-u-nu-'` and
             | missed the form a second site would actually take. Proved by
             | writing `'-u-nu-latn'` into another class: the subtag was
             | there, built by hand, and the rule said one site. Comments
             | mentioning the subtag - including the one explaining this -
             | would also have counted if this read the raw text.
             */
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (! \is_array($token)) {
                    continue;
                }

                if (! \in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                if (str_contains($token[1], '-u-nu-') || str_contains($token[1], '-nu-')) {
                    $sites[str_replace($base.'/', '', $path)] = true;

                    break;
                }
            }
        }

        if (\count($sites) > 1) {
            $problems[] = sprintf(
                'The numbering-system subtag is built in %d places (%s). One page\'s quantity and its line '.
                'total then come from two decisions, and a document showing "١٢" beside "123.45" on the '.
                'same row does not read as a styling bug - it reads as a document nobody assembled on '.
                'purpose. It belongs in Support\\Lang\\NumberingSystem.',
                \count($sites),
                implode(', ', array_keys($sites)),
            );
        }

        if ($sites === []) {
            $problems[] = 'Nothing builds the numbering-system subtag at all, so this rule is asserting nothing.';
        }

        return $problems;
    }

    /**
     * `latn` has to be written out, and this is why.
     *
     * Skipping the subtag when the configured shape is `latn` looks like an
     * obvious simplification and is wrong: CLDR's default numbering system for
     * `ar` is `arab`, so a bare `ar` locale renders Arabic-Indic digits. The
     * configuration says Western digits are the regional convention for
     * financial documents, and without the explicit subtag it says it without
     * doing it.
     *
     * @return list<string>
     */
    private function latnIsWrittenOut(): array
    {
        $problems = [];

        if (NumberingSystem::apply('ar') !== 'ar-u-nu-latn') {
            $problems[] = sprintf(
                'NumberingSystem::apply("ar") returned "%s" under a `latn` configuration. A bare "ar" '.
                'renders ١٢٣٤ - measured - so the subtag has to be written out even when it looks like '.
                'a no-op.',
                NumberingSystem::apply('ar'),
            );
        }

        $rendered = Money::of('1234.56', 'SAR')->format('ar', false);

        if (preg_match('/[0-9]/', $rendered) !== 1) {
            $problems[] = sprintf(
                'Money::format under locale `ar` produced "%s", which contains no Western digit, while '.
                'config(money.display.digits) is "%s". The setting exists because financial documents in '.
                'the region conventionally use Western digits even in Arabic text; a setting that does not '.
                'take effect is worse than one that is not offered.',
                $rendered,
                (string) config('money.display.digits'),
            );
        }

        // And the tenant-level shapes still reach ICU as themselves.
        foreach (['arabic_indic' => 'arab', 'eastern_arabic_indic' => 'arabext', 'western' => 'latn'] as $shape => $icu) {
            if (NumberingSystem::apply('ar', $shape) !== 'ar-u-nu-'.$icu) {
                $problems[] = sprintf(
                    'The tenant digit shape "%s" resolved to "%s" rather than ar-u-nu-%s. `arab` and '.
                    '`arabext` are frequently treated as one thing and are not: a Saudi statement in '.
                    '`arabext` is wrong in a way that reads as a font problem, so nobody reports it.',
                    $shape,
                    NumberingSystem::apply('ar', $shape),
                    $icu,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function servedLocalesAreReal(): array
    {
        $problems = [];
        $base = \dirname(__DIR__, 2);

        foreach (LanguageRegistry::served() as $tag) {
            if (! is_file($base.'/lang/'.$tag.'.json')) {
                $problems[] = sprintf(
                    'The registry serves "%s" and there is no lang/%s.json. A served locale is a promise '.
                    'the product is translated; without the file the picker offers a language that renders '.
                    'in another one.',
                    $tag,
                    $tag,
                );
            }

            if (Locale::getDisplayLanguage($tag, 'en') === $tag) {
                $problems[] = sprintf('ICU does not recognise "%s" as a language tag.', $tag);
            }

            /*
             | Plural categories, measured rather than trusted.
             |
             | ICU is asked which categories a six-way message can actually
             | reach in this locale, across integers and fractions. Arabic
             | reaches all six; English reaches two. A file claiming otherwise
             | would let a translator write four forms that never render, or
             | two that leave four cases grammatically wrong.
             */
            $reachable = $this->pluralCategoriesIcuReaches($tag);
            $declared = LanguageRegistry::pluralCategories($tag);

            sort($reachable);
            sort($declared);

            if ($reachable !== $declared) {
                $problems[] = sprintf(
                    '%s declares the plural categories %s; ICU reaches %s. Arabic has six where English '.
                    'has two, and a message written for the wrong number of them is grammatically wrong '.
                    'in the cases it does not cover - silently, on a page.',
                    $tag,
                    implode(',', $declared),
                    implode(',', $reachable),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function pluralCategoriesIcuReaches(string $tag): array
    {
        $message = '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}';
        $seen = [];

        foreach (array_merge(range(0, 220), [0.5, 1.5, 2.5, 100.0, 1000000]) as $n) {
            $category = MessageFormatter::formatMessage($tag, $message, ['n' => $n]);

            if (\is_string($category)) {
                $seen[$category] = true;
            }
        }

        return array_keys($seen);
    }

    /** @return list<string> */
    private function theDefaultLocaleIsServed(): array
    {
        $problems = [];

        if (! LanguageRegistry::exists(LanguageRegistry::DEFAULT_TAG)) {
            $problems[] = sprintf(
                'The default locale "%s" is not in the served list.',
                LanguageRegistry::DEFAULT_TAG,
            );
        }

        /*
         | The literal in config/app.php, read as text.
         |
         | The thing under test IS the literal: the Laravel skeleton ships
         | `env('APP_LOCALE', 'en')`, and with that in place Arabic-first
         | survives only while an environment variable is remembered. The
         | resolved value cannot show this, because .env sets it here.
         */
        $config = (string) file_get_contents(\dirname(__DIR__, 2).'/config/app.php');

        if (! str_contains($config, "env('APP_LOCALE') ?: \\App\\Support\\Iso\\LanguageRegistry::DEFAULT_TAG")) {
            $problems[] = 'config/app.php no longer falls back to LanguageRegistry::DEFAULT_TAG for the '.
                'application locale. A literal there - the skeleton ships `en` - means a deployment that '.
                'forgets APP_LOCALE serves English to every Arabic user, correctly as far as the framework '.
                'is concerned.';
        }

        if (LanguageRegistry::direction(LanguageRegistry::DEFAULT_TAG) !== 'rtl') {
            $problems[] = 'The default locale is not right-to-left, which contradicts every statement in '.
                'the codebase that PeakFlow is Arabic-first.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function unitsKnowWhatTheyMeasure(): array
    {
        $problems = [];

        $expected = [
            'HUR' => UnitDimension::Time,
            'C62' => UnitDimension::Count,
            'KGM' => UnitDimension::Mass,
            'MTK' => UnitDimension::Area,
            'LTR' => UnitDimension::Volume,
            'KWH' => UnitDimension::Energy,
            'E34' => UnitDimension::Data,
            'TKM' => UnitDimension::Freight,
            'P1' => UnitDimension::Proportion,
            'KMT' => UnitDimension::Length,
        ];

        foreach ($expected as $code => $dimension) {
            if (! UnitRegistry::exists($code)) {
                $problems[] = sprintf('%s is not in the unit registry.', $code);

                continue;
            }

            if (UnitRegistry::dimension($code) !== $dimension) {
                $problems[] = sprintf(
                    '%s measures %s; it should measure %s.',
                    $code,
                    UnitRegistry::dimension($code)->value,
                    $dimension->value,
                );
            }
        }

        if (UnitRegistry::comparable('HUR', 'KGM')) {
            $problems[] = 'Hours and kilograms are reported as comparable. Three hours plus two kilograms '.
                'is not a rounding problem, and where it shows up is a credit note whose unit does not '.
                'match the line it credits - the quantities subtract to a number that means nothing and '.
                'still prints.';
        }

        if (! UnitRegistry::comparable('HUR', 'DAY')) {
            $problems[] = 'Hours and days are reported as incomparable, so the rule is refusing everything '.
                'rather than refusing the wrong things.';
        }

        /*
         | Every dimension in the enum has at least one unit.
         |
         | An empty dimension is a case in a match statement that nothing can
         | reach, which is the shape a dropped unit leaves behind.
         */
        foreach (UnitDimension::cases() as $dimension) {
            if (UnitRegistry::ofDimension($dimension) === []) {
                $problems[] = sprintf(
                    'No unit measures %s, so the enum case is unreachable. Either a unit was dropped or '.
                    'the case should not exist.',
                    $dimension->value,
                );
            }
        }

        if (UnitRegistry::exists('KTM')) {
            $problems[] = 'KTM is back in the unit registry. It was labelled "kilometre per hour", and a '.
                'speed is not a chargeable quantity - a line multiplies a quantity by a unit price, and '.
                '3 km/h x 50 SAR is not money. Its meaning also could not be confirmed against the '.
                'published Rec 20 list, and an unsourced code is validated by the RECEIVING system, which '.
                'rejects the invoice rather than the code.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function sourceFiles(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
