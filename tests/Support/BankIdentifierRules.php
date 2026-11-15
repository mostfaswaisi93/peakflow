<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Iso\CountryRegistry;
use App\Support\Iso\CurrencyRegistry;
use App\Support\Iso\Domestic\AuBsb;
use App\Support\Iso\Domestic\DomesticScheme;
use App\Support\Iso\Domestic\IndiaIfsc;
use App\Support\Iso\Domestic\UkSortCode;
use App\Support\Iso\Domestic\UsRoutingNumber;
use App\Support\Iso\Exceptions\InvalidDomesticIdentifier;
use App\Support\Iso\Exceptions\InvalidIban;
use App\Support\Iso\Iban;
use App\Support\Iso\IbanRegistry;
use Throwable;

/**
 * Bank account identifiers, and what they refuse - PF-008.
 *
 * ## What is actually being claimed
 *
 * That a beneficiary account somebody typed is either right or refused. Not
 * "validated": an identifier that passes a length check and goes into a
 * payment file is a payment that comes back days later, by which time the
 * invoice is overdue and somebody has been chased for it.
 *
 * ## The registry examples are the length table's test
 *
 * 80 published ISO 13616 examples go through the real constructor. If a
 * length in `iban.tsv` were wrong, that country's own published IBAN would be
 * refused - which is the only check available for a table transcribed rather
 * than downloaded.
 *
 * Then each one is broken in the two ways a person breaks one: a single
 * character changed, and two adjacent characters swapped. MOD 97-10 detects
 * both classes exhaustively, so every example must be refused after each -
 * 160 assertions that the checksum is load-bearing per country rather than in
 * general.
 */
final class BankIdentifierRules
{
    /**
     * The countries whose currency has three decimal places.
     *
     * Named in PF-008 for a reason: they are the markets, their IBANs are the
     * long ones (JO and KW are 30 characters), and a length error in exactly
     * these seven is a length error where it costs most.
     *
     * @var array<string, string>
     */
    private const THREE_DECIMAL_MARKETS = [
        'BH' => 'BHD',
        'IQ' => 'IQD',
        'JO' => 'JOD',
        'KW' => 'KWD',
        'LY' => 'LYD',
        'OM' => 'OMR',
        'TN' => 'TND',
    ];

    /**
     * Routing numbers whose ABA checksum was verified arithmetically.
     *
     * Eight remembered numbers, all eight passing a checksum that a wrong one
     * passes with probability 1 in 10 - so 8 for 8 is evidence they are real
     * rather than evidence of a working checksum. Both are worth having.
     *
     * @var list<string>
     */
    private const REAL_ROUTING_NUMBERS = [
        '021000021', '011000015', '121000248', '026009593',
        '322271627', '111000025', '063100277', '091000019',
    ];

    /** The countries with no sourced registry example, so no confirmed length. */
    /** @var list<string> */
    private const UNCONFIRMED_LENGTHS = ['BI', 'DJ', 'MN', 'NI', 'RU', 'SD', 'SO'];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'every published registry example is accepted' => $this->examplesAreAccepted(),
            'a single changed character is refused, per country' => $this->singleCharacterErrorsAreCaught(),
            'two swapped characters are refused, per country' => $this->transpositionsAreCaught(),
            'the three-decimal markets each have a confirmed length' => $this->theMarketsAreConfirmed(),
            'the unconfirmed lengths are the known seven and no more' => $this->unconfirmedLengthsHaveNotGrown(),
            'an IBAN cannot be constructed invalid' => $this->anIbanCannotBeConstructedInvalid(),
            'normalisation and display are inverse' => $this->normalisationAndDisplayAgree(),
            'the ABA checksum catches what people actually mistype' => $this->abaCatchesTypingErrors(),
            'a checksum that passes is not enough on its own' => $this->aPassingChecksumIsNotEnough(),
            'the domestic shapes refuse what is not one' => $this->domesticShapesRefuse(),
            'a scheme says whether it can detect a typo at all' => $this->schemesAdmitWhatTheyCannotCheck(),
            'every country is either IBAN, a scheme, or admitted unknown' => $this->coverageIsHonest(),
        ];
    }

    /** @return list<string> */
    private function examplesAreAccepted(): array
    {
        $problems = [];
        $examples = IbanRegistry::examples();

        if (\count($examples) < 25) {
            $problems[] = sprintf(
                'Only %d countries have a registry example. PF-008 asks for at least 25, because the '.
                'example is what checks the length and an unchecked length refuses real accounts.',
                \count($examples),
            );
        }

        foreach ($examples as $country => $example) {
            try {
                $iban = new Iban($example);
            } catch (Throwable $e) {
                $problems[] = sprintf(
                    'The published example for %s (%s) was refused: %s. The example is the only check on '.
                    'this country\'s length, so either the length is wrong or the example is.',
                    $country,
                    $example,
                    $e->getMessage(),
                );

                continue;
            }

            if ($iban->countryCode !== $country) {
                $problems[] = sprintf('%s\'s example parsed as country %s.', $country, $iban->countryCode);
            }

            if (\strlen($iban->value) !== IbanRegistry::lengthFor($country)) {
                $problems[] = sprintf(
                    '%s\'s example is %d characters and the table says %d.',
                    $country,
                    \strlen($iban->value),
                    (int) IbanRegistry::lengthFor($country),
                );
            }
        }

        return $problems;
    }

    /**
     * One character changed, in every example.
     *
     * MOD 97-10 detects every single-character substitution, so this must be
     * refused 80 times out of 80. A position inside the BBAN is chosen rather
     * than the check digits themselves, because changing the check digits is
     * the easy case.
     *
     * @return list<string>
     */
    private function singleCharacterErrorsAreCaught(): array
    {
        $problems = [];

        foreach (IbanRegistry::examples() as $country => $example) {
            $position = 6;
            $original = $example[$position];
            $replacement = ctype_digit($original)
                ? (string) (((int) $original + 1) % 10)
                : \chr(((\ord($original) - 65 + 1) % 26) + 65);

            $mutated = substr_replace($example, $replacement, $position, 1);

            if (Iban::tryFrom($mutated) !== null) {
                $problems[] = sprintf(
                    '%s: changing one character of %s to %s produced an IBAN that was accepted. MOD 97-10 '.
                    'detects every single-character substitution, so this is the checksum not running.',
                    $country,
                    $example,
                    $mutated,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function transpositionsAreCaught(): array
    {
        $problems = [];

        foreach (IbanRegistry::examples() as $country => $example) {
            $mutated = null;

            /*
             | Find two ADJACENT DIFFERENT characters to swap.
             |
             | Swapping two identical characters produces the same string, and
             | several registry examples are long runs of zeros - so a fixed
             | position would silently assert nothing for those countries.
             */
            for ($i = 4; $i < \strlen($example) - 1; $i++) {
                if ($example[$i] !== $example[$i + 1]) {
                    $mutated = substr($example, 0, $i).$example[$i + 1].$example[$i].substr($example, $i + 2);

                    break;
                }
            }

            if ($mutated === null) {
                $problems[] = sprintf(
                    '%s\'s example has no two adjacent different characters, so no transposition could be '.
                    'built for it and this rule asserts nothing about that country.',
                    $country,
                );

                continue;
            }

            if (Iban::tryFrom($mutated) !== null) {
                $problems[] = sprintf(
                    '%s: swapping two adjacent characters of %s gave %s, which was accepted. Transposing '.
                    'two digits is the single most common error in a hand-copied account number.',
                    $country,
                    $example,
                    $mutated,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theMarketsAreConfirmed(): array
    {
        $problems = [];

        foreach (self::THREE_DECIMAL_MARKETS as $country => $currency) {
            if (CurrencyRegistry::exponent($currency) !== 3) {
                $problems[] = sprintf('%s is not a three-decimal currency in the registry.', $currency);
            }

            $example = IbanRegistry::example($country);

            if ($example === null) {
                $problems[] = sprintf(
                    '%s (%s) has no registry example, so its IBAN length is unconfirmed. These seven are '.
                    'named in PF-008 because they are the markets and their IBANs are the long ones - a '.
                    'length error here is a length error where it costs most.',
                    $country,
                    $currency,
                );

                continue;
            }

            if (Iban::tryFrom($example) === null) {
                $problems[] = sprintf('%s\'s example (%s) is not a valid IBAN.', $country, $example);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function unconfirmedLengthsHaveNotGrown(): array
    {
        $actual = IbanRegistry::unconfirmedLengths();
        $expected = self::UNCONFIRMED_LENGTHS;

        sort($actual);
        sort($expected);

        if ($actual === $expected) {
            return [];
        }

        $added = array_diff($actual, $expected);
        $removed = array_diff($expected, $actual);

        $problems = [];

        if ($added !== []) {
            $problems[] = sprintf(
                'These countries now have no confirmed IBAN length: %s. A length with no example behind '.
                'it refuses real accounts and nothing here would notice, so the list may shrink and not '.
                'grow - either source an example or explain the addition.',
                implode(', ', $added),
            );
        }

        if ($removed !== []) {
            $problems[] = sprintf(
                'These countries now have an example and are still listed as unconfirmed: %s. Remove '.
                'them, so the list stays a list of real gaps.',
                implode(', ', $removed),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function anIbanCannotBeConstructedInvalid(): array
    {
        $problems = [];

        $refusals = [
            'an empty string' => '',
            'only whitespace' => '   ',
            'a country that has no IBAN' => 'ZZ0380000000608010167519',
            'a country that exists but has no IBAN scheme' => 'CA0380000000608010167519',
            'the right country and the wrong length' => 'SA038000000060801016751',
            'valid structure with wrong check digits' => 'SA0480000000608010167519',
            'a lower-case country code with bad digits' => 'sa0480000000608010167519',
            'digits where the country code belongs' => '1203 8000 0000 6080 1016',
            'letters in the check-digit positions' => 'SAAB80000000608010167519',
        ];

        foreach ($refusals as $description => $candidate) {
            try {
                new Iban($candidate);

                $problems[] = sprintf(
                    '%s ("%s") was accepted. Validation is in the constructor precisely so that an '.
                    'invalid IBAN cannot exist as an object - a separate validator is something a caller '.
                    'forgets exactly once, and the thing it guards is where money leaves the company.',
                    $description,
                    $candidate,
                );
            } catch (InvalidIban) {
                // Refused, as it must be.
            } catch (Throwable $e) {
                $problems[] = sprintf(
                    '%s raised %s rather than InvalidIban: %s',
                    $description,
                    $e::class,
                    $e->getMessage(),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function normalisationAndDisplayAgree(): array
    {
        $problems = [];

        foreach (IbanRegistry::examples() as $country => $example) {
            $spaced = trim(chunk_split($example, 4, ' '));
            $hyphenated = str_replace(' ', '-', $spaced);

            foreach (['spaced' => $spaced, 'hyphenated' => $hyphenated, 'lower case' => strtolower($spaced)] as $form => $input) {
                $parsed = Iban::tryFrom($input);

                if ($parsed === null) {
                    $problems[] = sprintf(
                        '%s\'s example in %s form ("%s") was refused. A user pastes what their bank printed, '.
                        'and their bank printed it in groups.',
                        $country,
                        $form,
                        $input,
                    );

                    continue;
                }

                if ($parsed->value !== $example) {
                    $problems[] = sprintf(
                        '%s\'s example in %s form normalised to %s rather than %s.',
                        $country,
                        $form,
                        $parsed->value,
                        $example,
                    );
                }
            }

            /*
             | tryFrom, not the constructor.
             |
             | This was `new Iban($example)` and an unguarded constructor in a
             | check suite is a crash instead of a finding: biting the JO
             | length made the whole run die with a stack trace rather than
             | naming the country. A check that cannot report is not a check.
             */
            $parsed = Iban::tryFrom($example);

            if ($parsed !== null && $parsed->forDisplay() !== $spaced) {
                $problems[] = sprintf(
                    '%s\'s example displays as "%s" rather than in groups of four. ISO 13616 says groups '.
                    'of four, and groups of four is how a human actually checks one.',
                    $country,
                    $parsed->forDisplay(),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function abaCatchesTypingErrors(): array
    {
        $problems = [];
        $blindSpotsSeen = 0;

        foreach (self::REAL_ROUTING_NUMBERS as $number) {
            if (UsRoutingNumber::tryFrom($number) === null) {
                $problems[] = sprintf(
                    '%s was refused, and it is a real routing number - its ABA checksum was verified '.
                    'arithmetically before it was written into this suite.',
                    $number,
                );

                continue;
            }

            // Every single-digit change, in every position.
            for ($position = 0; $position < 9; $position++) {
                for ($digit = 0; $digit <= 9; $digit++) {
                    if ((int) $number[$position] === $digit) {
                        continue;
                    }

                    $mutated = substr_replace($number, (string) $digit, $position, 1);

                    if (UsRoutingNumber::tryFrom($mutated) !== null) {
                        $problems[] = sprintf(
                            '%s became %s by one digit and was still accepted. The ABA check digit '.
                            'detects every single-digit error, so this is it not running.',
                            $number,
                            $mutated,
                        );
                    }
                }
            }

            /*
             | Every adjacent transposition - except the ones the checksum
             | provably cannot see.
             |
             | This rule first asserted that all of them are caught, and it
             | failed: `322271627` with its `2` and `7` swapped passes. The
             | algebra says why - the adjacent weight differences are 4, -6
             | and 2, all even, so a swap of digits differing by five moves
             | the sum by a multiple of ten. Asserting the blind spot does not
             | exist would have meant deleting the finding; asserting its
             | exact shape keeps it visible.
             */
            for ($position = 0; $position < 8; $position++) {
                $first = (int) $number[$position];
                $second = (int) $number[$position + 1];

                if ($first === $second) {
                    continue;
                }

                $mutated = substr_replace($number, (string) $second.(string) $first, $position, 2);
                $accepted = UsRoutingNumber::tryFrom($mutated) !== null;
                $blind = UsRoutingNumber::transpositionIsUndetectable($first, $second);

                if ($accepted && ! $blind) {
                    $problems[] = sprintf(
                        '%s with positions %d and %d swapped (%s) was accepted, and those digits do not '.
                        'differ by five, so the checksum should have caught it.',
                        $number,
                        $position + 1,
                        $position + 2,
                        $mutated,
                    );
                }

                if ($accepted && $blind) {
                    $blindSpotsSeen++;
                }

                if (! $accepted && $blind) {
                    $problems[] = sprintf(
                        '%s with positions %d and %d swapped (%s) was refused, and the algebra says the '.
                        'ABA checksum cannot see that swap. Something else is catching it - which is '.
                        'good news, and means transpositionIsUndetectable() now describes a limitation '.
                        'that is no longer there.',
                        $number,
                        $position + 1,
                        $position + 2,
                        $mutated,
                    );
                }
            }
        }

        /*
         | The blind spot has to be EXERCISED, not merely allowed for.
         |
         | Without this, a corpus that happened to contain no two adjacent
         | digits differing by five would let the exception branch sit there
         | asserting nothing, and the documented limitation would quietly
         | become a claim nobody tests.
         */
        if ($blindSpotsSeen === 0) {
            $problems[] = 'No transposition in this corpus landed on the ABA checksum\'s blind spot, so '.
                'the documented limitation was not exercised at all. Add a routing number with two '.
                'adjacent digits differing by five.';
        }

        return $problems;
    }

    /**
     * The measurement that made the prefix check necessary.
     *
     * The first version of this used numbers that did NOT pass the checksum,
     * so it proved nothing: they would have been refused with or without the
     * prefix rule. Each one here was computed to satisfy the weighted sum and
     * still sit in an unallocated Federal Reserve range, which is the only
     * form of this case that tests anything.
     *
     * `000000000` satisfies the ABA checksum - the weighted sum is zero, which
     * is a multiple of ten. Without the Federal Reserve prefix check it is a
     * valid routing number, and a string of zeros is exactly what somebody
     * types to get past a required field.
     *
     * @return list<string>
     */
    private function aPassingChecksumIsNotEnough(): array
    {
        $problems = [];

        if (UsRoutingNumber::weightedSum('000000000') % 10 !== 0) {
            $problems[] = 'The premise of this check has changed: 000000000 no longer satisfies the ABA '.
                'checksum, so the prefix rule may no longer be load-bearing for the reason stated.';
        }

        /*
         | A list of pairs, not a keyed map.
         |
         | PHP casts a numeric-string array key to an integer, so a map keyed
         | by routing number hands `int` to a `string` parameter - which is a
         | TypeError rather than a finding.
         */
        $plausibleButUnallocated = [
            ['000000000', 'nine zeros, whose weighted sum is zero'],
            ['130000006', 'prefix 13, in the unallocated 13-20 gap'],
            ['200000004', 'prefix 20, the last of that gap'],
            ['500000005', 'prefix 50, in the unallocated 33-60 gap'],
            ['790000006', 'prefix 79, in the unallocated 73-79 gap'],
            ['990000000', 'prefix 99, above every allocated range'],
        ];

        foreach ($plausibleButUnallocated as [$number, $why]) {
            $accepted = UsRoutingNumber::tryFrom($number) !== null;
            $checksumPasses = UsRoutingNumber::weightedSum($number) % 10 === 0;

            if ($accepted) {
                $problems[] = sprintf(
                    '%s was accepted - %s. The checksum alone is not a sufficient check, which is the '.
                    'whole reason the prefix ranges are enforced.',
                    $number,
                    $why,
                );
            }

            if (! $checksumPasses) {
                $problems[] = sprintf(
                    '%s does not pass the checksum, so it proves nothing about the prefix rule - this '.
                    'case needs a number that passes the checksum and is still not a bank.',
                    $number,
                );
            }
        }

        foreach (self::REAL_ROUTING_NUMBERS as $number) {
            $district = (new UsRoutingNumber($number))->federalReserveDistrict();

            if ($district < 1 || $district > 12) {
                $problems[] = sprintf(
                    '%s maps to Federal Reserve district %d, and there are twelve.',
                    $number,
                    $district,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function domesticShapesRefuse(): array
    {
        $problems = [];

        /*
         | One of these must ALSO exercise the blind spot deliberately, so
         | that the enumerated limitation stays a measured fact.
         */
        $cases = [
            // scheme, accepted, refused
            [UkSortCode::class, ['123456', '12-34-56', '12 34 56'], ['12345', '1234567', '12-34-5A', '', 'abcdef']],
            [AuBsb::class, ['082039', '082-039', '062 000'], ['08203', '0820399', '000000', '00-20-39', 'ABC-123']],
            [IndiaIfsc::class, ['SBIN0001234', 'sbin0001234', 'HDFC0CAGSBK'], ['SBIN001234', 'SBIN1000123', 'SBI00001234', 'SBIN00012345', '']],
        ];

        foreach ($cases as [$class, $accepted, $refused]) {
            foreach ($accepted as $value) {
                try {
                    $class::tryFrom($value) === null
                        && $problems[] = sprintf('%s refused "%s", which is a valid one.', $class, $value);
                } catch (Throwable $e) {
                    $problems[] = sprintf('%s threw %s on "%s".', $class, $e::class, $value);
                }
            }

            foreach ($refused as $value) {
                if ($class::tryFrom($value) !== null) {
                    $problems[] = sprintf(
                        '%s accepted "%s". A beneficiary identifier of the wrong shape is a payment that '.
                        'is returned days later, with the invoice overdue and somebody already chased.',
                        $class,
                        $value,
                    );
                }
            }
        }

        /*
         | The one structural rule IFSC actually gives.
         |
         | `SBIN1000123` is eleven characters with a letter-digit shape that a
         | naive length-and-alphanumeric check accepts, and it is not a code:
         | the fifth character is reserved and is always zero.
         */
        if (IndiaIfsc::tryFrom('SBIN1000123') !== null) {
            $problems[] = 'An IFSC with a non-zero fifth character was accepted. That character is '.
                'reserved, and it is the one somebody mistypes when transcribing by hand.';
        }

        $ifsc = new IndiaIfsc('hdfc0cagsbk');

        if ($ifsc->bankCode() !== 'HDFC' || $ifsc->branchCode() !== 'CAGSBK') {
            $problems[] = sprintf(
                'An IFSC split into bank "%s" and branch "%s". The RBI publishes these upper case, and a '.
                'lower-case copy would not match a comparison a payment file makes.',
                $ifsc->bankCode(),
                $ifsc->branchCode(),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function schemesAdmitWhatTheyCannotCheck(): array
    {
        $problems = [];

        $expected = [
            'US' => true,   // ABA check digit.
            'GB' => false,  // VocaLink modulus checking needs the published weight table.
            'AU' => false,  // No check digit exists.
            'IN' => false,  // No check digit exists.
        ];

        foreach ($expected as $country => $hasCheck) {
            $scheme = DomesticScheme::forCountry($country);

            if ($scheme === null) {
                $problems[] = sprintf('%s has no domestic scheme.', $country);

                continue;
            }

            if ($scheme->hasCheckDigit() !== $hasCheck) {
                $problems[] = sprintf(
                    '%s reports hasCheckDigit() = %s. The answer changes what a form must do: without a '.
                    'check digit, reading the number back or asking for it twice is the only defence '.
                    'against a transposed digit, and a form that believes it was validated will not '.
                    'offer one.',
                    $country,
                    $scheme->hasCheckDigit() ? 'true' : 'false',
                    );
            }

            if ($scheme->label('ar') === $scheme->label('en')) {
                $problems[] = sprintf('%s\'s scheme has no Arabic label.', $country);
            }
        }

        // A scheme must actually build its value object, not just name it.
        foreach ([['US', '021000021'], ['GB', '123456'], ['AU', '082039'], ['IN', 'SBIN0001234']] as [$country, $value]) {
            $scheme = DomesticScheme::forCountry($country);

            if ($scheme === null || $scheme->tryParse($value) === null) {
                $problems[] = sprintf('%s\'s scheme could not parse "%s".', $country, $value);
            }
        }

        foreach ([['US', '021000022'], ['GB', '12345'], ['AU', '000000'], ['IN', 'SBIN1000123']] as [$country, $value]) {
            $scheme = DomesticScheme::forCountry($country);

            if ($scheme !== null && $scheme->tryParse($value) !== null) {
                $problems[] = sprintf('%s\'s scheme accepted the invalid "%s".', $country, $value);
            }
        }

        return $problems;
    }

    /**
     * No country may silently have neither an IBAN nor a scheme nor an admission.
     *
     * The failure this prevents is the form that asks for an IBAN and falls
     * back to a free-text account number: a US routing number typed into free
     * text is nine digits nobody checked, and the ABA checksum that would have
     * caught the transposition never ran.
     *
     * @return list<string>
     */
    private function coverageIsHonest(): array
    {
        $problems = [];

        foreach (DomesticScheme::countries() as $country) {
            if (! CountryRegistry::exists($country)) {
                $problems[] = sprintf('The scheme map names %s, which is not a country.', $country);
            }

            if (DomesticScheme::forCountry($country) === null) {
                $problems[] = sprintf(
                    '%s is listed in DomesticScheme::countries() and forCountry() returns null for it.',
                    $country,
                );
            }
        }

        foreach (DomesticScheme::cases() as $case) {
            $served = array_filter(
                DomesticScheme::countries(),
                static fn (string $c): bool => DomesticScheme::forCountry($c) === $case,
            );

            if ($served === []) {
                $problems[] = sprintf(
                    'No country uses the %s scheme, so the case is unreachable - either a country was '.
                    'dropped from the map or the case should not exist.',
                    $case->value,
                );
            }
        }

        /*
         | And an IBAN country must not also claim a scheme by accident.
         |
         | GB is the one deliberate overlap: it has an IBAN and its domestic
         | payments are addressed by sort code, so an invoice to a UK customer
         | carries both. Any OTHER overlap is a mistake, because it means two
         | validators would run on one field with no rule saying which wins.
         */
        foreach (DomesticScheme::countries() as $country) {
            if ($country !== 'GB' && Iban::appliesTo($country)) {
                $problems[] = sprintf(
                    '%s has both an IBAN and a domestic scheme, and only GB is a deliberate overlap. Two '.
                    'validators on one field with no rule about which wins is a field that accepts '.
                    'whichever the caller tried first.',
                    $country,
                );
            }
        }

        if (! Iban::appliesTo('GB')) {
            $problems[] = 'GB no longer has an IBAN in the registry, which would make the overlap note '.
                'above describe something that is not there.';
        }

        if (Iban::appliesTo('US') || Iban::appliesTo('AU') || Iban::appliesTo('IN')) {
            $problems[] = 'One of US, AU or IN has acquired an IBAN in the registry. None of them has one, '.
                'so this is a row that should not be there.';
        }

        return $problems;
    }
}
