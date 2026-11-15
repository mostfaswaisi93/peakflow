<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Hashing\Canonicalizer;
use App\Support\Hashing\ChainLink;
use App\Support\Hashing\ChainWalker;
use App\Support\Hashing\Exceptions\NotCanonicalizable;
use App\Support\Hashing\XmlCanonicalizer;
use DOMDocument;
use Throwable;

/**
 * Canonicalization, and the hashes that depend on it - PF-009.
 *
 * ## What is actually being claimed
 *
 * That the same information produces the same bytes, here and at a
 * counterparty. Not "we hash consistently with ourselves": a hash chain whose
 * canonical form differs from a conformant implementation's verifies here and
 * fails there, which is the exact class of problem the chain exists to detect,
 * arriving from the thing meant to detect it.
 *
 * So the checks are about agreement with the specifications, and the cases are
 * the ones where a plausible implementation diverges: surrogate ordering,
 * U+2028, an integer past 2^53, an entity reference, a DOCTYPE.
 */
final class CanonicalizationRules
{
    /**
     * A fixed payload and the SHA-256 of its canonical form.
     *
     * This is the "stable across PHP versions" assertion in the only form a
     * single environment can make it: a literal, written down, that a future
     * change to PHP's string or JSON handling would move.
     *
     * The payload deliberately contains the three things that would break it -
     * Arabic text, a U+2028 LINE SEPARATOR, and keys whose sorted order is not
     * their insertion order.
     */
    private const GOLDEN_HASH = 'e9e08e024f9005df39a01ad27cda7be4a4508ed1a97343d1389f5288f7bc6267';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'object members sort by UTF-16 code unit, not by byte' => $this->orderingIsByUtf16CodeUnits(),
            'insertion order cannot change a hash' => $this->insertionOrderDoesNotMatter(),
            'nothing in the canonical path asks the locale' => $this->nothingAsksTheLocale(),
            'the escaping is the RFC\'s and not PHP\'s' => $this->escapingIsTheRfcs(),
            'a number that cannot round-trip is refused' => $this->unsafeNumbersAreRefused(),
            'the refusal names the integer a conformant reader would use' => $this->theRefusalNamesTheRealAlternative(),
            'invalid UTF-8 is refused rather than hashed' => $this->invalidUtf8IsRefused(),
            'the canonical form of a fixed payload has not moved' => $this->theGoldenVectorHolds(),
            'XML that differs only in what is not significant hashes the same' => $this->xmlIgnoresTheInsignificant(),
            'XML that differs in anything significant hashes differently' => $this->xmlNoticesTheSignificant(),
            'an entity declaration cannot read a file or expand' => $this->entitiesCannotReachOut(),
            'XML this cannot canonicalize correctly is refused' => $this->xmlRefusesWhatItCannotDo(),
            'exclusive canonicalization drops only unused namespaces' => $this->exclusiveDropsOnlyUnused(),
            'a chain link is the canonical hash, and the walker names the first break' => $this->theChainUsesTheCanonicalForm(),
        ];
    }

    /** @return array<string, mixed> */
    private function goldenPayload(): array
    {
        return [
            'invoice' => 'INV-2026-000147',
            'issued_at' => '2026-11-15T09:30:00+03:00',
            'currency' => 'SAR',
            'total' => '1234.5600',
            'lines' => [
                ['item' => 'HUR', 'qty' => '3.0000', 'unit' => '450.00000000'],
                ['item' => 'C62', 'qty' => '1.0000', 'unit' => '99.00000000'],
            ],
            'buyer' => ['name' => 'شركة الأفق', 'vat' => '300000000000003'],
            'note' => "خط\u{2028}جديد",
            'sequence' => 147,
            'void' => false,
            'superseded' => null,
        ];
    }

    /** @return list<string> */
    private function orderingIsByUtf16CodeUnits(): array
    {
        $problems = [];

        /*
         | The RFC's own example, and the reason it is the example.
         |
         | U+1F600 is encoded in UTF-16 as the surrogate pair D83D DE00, so it
         | sorts BEFORE U+FB33. In UTF-8 bytes it sorts after. Any key above
         | U+FFFF therefore puts a byte-sorting implementation in a different
         | order from a conformant one - and nothing on ASCII keys shows it.
         */
        $payload = [
            "\u{20ac}" => 'Euro Sign',
            "\r" => 'Carriage Return',
            "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh',
            '1' => 'One',
            "\u{1f600}" => 'Emoji: Grinning Face',
            "\u{80}" => 'Control',
            "\u{f6}" => 'Latin Small Letter O With Diaeresis',
        ];

        $expected = '{"\r":"Carriage Return",'
            .'"1":"One",'
            ."\"\u{80}\":\"Control\","
            ."\"\u{f6}\":\"Latin Small Letter O With Diaeresis\","
            ."\"\u{20ac}\":\"Euro Sign\","
            ."\"\u{1f600}\":\"Emoji: Grinning Face\","
            ."\"\u{fb33}\":\"Hebrew Letter Dalet With Dagesh\"}";

        $actual = Canonicalizer::encode($payload);

        if ($actual !== $expected) {
            $problems[] = sprintf(
                "Member ordering does not match RFC 8785.\n       expected %s\n       actual   %s",
                $expected,
                $actual,
            );
        }

        /*
         | And the divergence is demonstrated, not assumed.
         |
         | If UTF-8 byte sorting happened to give the same answer, the
         | conversion in Canonicalizer would be dead code and this rule would
         | be asserting nothing.
         */
        $byBytes = ["\u{1f600}", "\u{fb33}"];
        sort($byBytes, SORT_STRING);

        if ($byBytes[0] === "\u{1f600}") {
            $problems[] = 'UTF-8 byte order now agrees with UTF-16 code unit order for U+1F600 and '.
                'U+FB33, which would make the UTF-16 conversion in the canonicalizer unnecessary - and '.
                'this rule no longer a test of anything.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function insertionOrderDoesNotMatter(): array
    {
        $payload = $this->goldenPayload();
        $hashes = [];

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $keys = array_keys($payload);
            shuffle($keys);

            $shuffled = [];

            foreach ($keys as $key) {
                $shuffled[$key] = $payload[$key];
            }

            $hashes[Canonicalizer::hash($shuffled)] = true;
        }

        if (\count($hashes) !== 1) {
            return [sprintf(
                '200 shuffles of the same payload produced %d distinct hashes. Insertion order is the '.
                'difference between two servers building the same document and agreeing about it.',
                \count($hashes),
            )];
        }

        return [];
    }

    /**
     * The locale assertion, in the form this environment can actually make.
     *
     * Only the `C` and `C.UTF-8` locales are installed here, so a matrix over
     * `tr_TR` and `ar_SA` is not available - and a check that silently skips
     * is worse than one that asserts something else. So the structural claim
     * is made instead: no locale-sensitive function appears in the
     * canonicalization path at all.
     *
     * `strcmp` is byte-wise and `strcoll` is the locale-aware one; `sprintf`
     * with `%04x` on an integer is locale-independent, while `%f` is not.
     * Casting a float to a string was locale-dependent before PHP 8, and
     * floats are refused outright - but the rule names the function rather
     * than trusting that.
     *
     * @return list<string>
     */
    private function nothingAsksTheLocale(): array
    {
        $problems = [];
        $forbidden = ['strcoll', 'setlocale', 'localeconv', 'strftime', 'money_format', 'nl_langinfo'];

        foreach (glob(\dirname(__DIR__, 2).'/app/Support/Hashing/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            foreach ($forbidden as $function) {
                if (preg_match('/\b'.$function.'\s*\(/', $source) === 1) {
                    $problems[] = sprintf(
                        '%s calls %s(), which answers differently depending on the server\'s locale. A '.
                        'canonical form that depends on a server setting is not canonical, and the '.
                        'symptom is a chain that verifies on one machine and not on the next.',
                        basename($path),
                        $function,
                    );
                }
            }
        }

        // And the sort really is byte-wise under both available locales.
        $hashes = [];

        foreach (['C', 'C.UTF-8'] as $locale) {
            if (setlocale(LC_ALL, $locale) === false) {
                continue;
            }

            $hashes[Canonicalizer::hash($this->goldenPayload())] = true;
        }

        setlocale(LC_ALL, 'C');

        if (\count($hashes) > 1) {
            $problems[] = 'The canonical hash changed between the C and C.UTF-8 locales.';
        }

        return $problems;
    }

    /**
     * Where PHP's JSON encoder and RFC 8785 disagree.
     *
     * `json_encode` with `JSON_UNESCAPED_UNICODE` still escapes U+2028 and
     * U+2029, because they terminate JavaScript string literals. RFC 8785 does
     * not permit that: only `"`, `\` and U+0000..U+001F may be escaped.
     *
     * So the divergence is asserted to EXIST - if PHP stopped escaping them,
     * the hand-written escaper would be unnecessary and this rule should say
     * so rather than keep passing.
     *
     * @return list<string>
     */
    private function escapingIsTheRfcs(): array
    {
        $problems = [];

        $expected = [
            "\x00" => '"\u0000"',
            "\x01" => '"\u0001"',
            "\x07" => '"\u0007"',
            "\x08" => '"\b"',
            "\x09" => '"\t"',
            "\x0a" => '"\n"',
            "\x0b" => '"\u000b"',
            "\x0c" => '"\f"',
            "\x0d" => '"\r"',
            "\x1f" => '"\u001f"',
            "\x7f" => "\"\x7f\"",
            '"' => '"\\""',
            '\\' => '"\\\\"',
            '/' => '"/"',
            "\u{2028}" => "\"\u{2028}\"",
            "\u{2029}" => "\"\u{2029}\"",
            "\u{a0}" => "\"\u{a0}\"",
            'عربي' => '"عربي"',
        ];

        foreach ($expected as $input => $want) {
            $got = Canonicalizer::encode([(string) $input]);
            $got = substr($got, 1, -1);  // strip the array brackets

            if ($got !== $want) {
                $problems[] = sprintf(
                    'The byte sequence %s canonicalized to %s, and RFC 8785 section 3.2.2.2 wants %s.',
                    strtoupper(bin2hex((string) $input)),
                    $got,
                    $want,
                );
            }
        }

        /*
         | Lower-case hex, specifically.
         |
         | `\u001F` and `\u001f` are different bytes and therefore different
         | hashes, and the RFC specifies the lower-case form. An implementation
         | using `%04X` would pass every other case here.
         */
        if (! str_contains(Canonicalizer::encode(["\x1f"]), '\u001f')) {
            $problems[] = 'A control character was escaped with upper-case hex. The RFC specifies lower '.
                'case, and the two are different bytes, so the hashes differ.';
        }

        $phpForm = json_encode("\u{2028}", JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($phpForm === '"'."\u{2028}".'"') {
            $problems[] = 'PHP\'s json_encode no longer escapes U+2028 under JSON_UNESCAPED_UNICODE, '.
                'which was the measured reason the escaper here is written out by hand. Worth '.
                'revisiting - this rule is now guarding against something that has gone away.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function unsafeNumbersAreRefused(): array
    {
        $problems = [];

        $refusals = [
            'an integer one past 2^53-1' => Canonicalizer::MAX_SAFE_INTEGER + 2,
            'the most negative safe integer, minus two' => -Canonicalizer::MAX_SAFE_INTEGER - 2,
            'PHP_INT_MAX' => PHP_INT_MAX,
        ];

        foreach ($refusals as $description => $value) {
            try {
                Canonicalizer::encode(['n' => $value]);

                $problems[] = sprintf(
                    '%s (%d) was canonicalized. RFC 8785 processes numbers as IEEE-754 doubles, so a '.
                    'conformant implementation reading it serializes a DIFFERENT integer and computes a '.
                    'different hash - the chain then verifies here and fails at a counterparty.',
                    $description,
                    $value,
                );
            } catch (NotCanonicalizable) {
                // Refused, as it must be.
            }
        }

        foreach ([0, 1, -1, Canonicalizer::MAX_SAFE_INTEGER, -Canonicalizer::MAX_SAFE_INTEGER] as $safe) {
            try {
                $encoded = Canonicalizer::encode(['n' => $safe]);

                if ($encoded !== '{"n":'.$safe.'}') {
                    $problems[] = sprintf('The safe integer %d encoded as %s.', $safe, $encoded);
                }
            } catch (Throwable $e) {
                $problems[] = sprintf('The safe integer %d was refused: %s', $safe, $e->getMessage());
            }
        }

        // And a float at all, which is the existing narrowing.
        try {
            Canonicalizer::encode(['n' => 1.5]);

            $problems[] = 'A float was canonicalized. PeakFlow has no float in its money path, so the '.
                'shortest-round-trip formatter RFC 8785 requires would only ever be exercised by a '.
                'mistake - and a refusal cannot produce a wrong hash.';
        } catch (NotCanonicalizable) {
            // Refused.
        }

        return $problems;
    }

    /**
     * The message's arithmetic, against the float cast it is not allowed to use.
     *
     * `Canonicalizer::nearestRepresentable()` computes which integer a
     * conformant implementation would serialize instead, and it does it with
     * integer arithmetic because the architecture rules forbid a float
     * anywhere in the money path - and the hashing code is in it.
     *
     * A test may use the cast, and should: `(float) $value` IS the IEEE-754
     * rounding the method is reimplementing, so it is the reference rather
     * than a second opinion. 2,000 random values plus the edges that broke
     * the first version: PHP_INT_MAX, which overflowed into a float and
     * printed "9.2233720368548E+18", and PHP_INT_MIN, whose negation
     * overflows before the arithmetic even starts.
     *
     * @return list<string>
     */
    private function theRefusalNamesTheRealAlternative(): array
    {
        $problems = [];
        $method = new \ReflectionMethod(Canonicalizer::class, 'nearestRepresentable');

        $edges = [
            Canonicalizer::MAX_SAFE_INTEGER + 2,
            Canonicalizer::MAX_SAFE_INTEGER + 4,
            18014398509481985,
            1152921504606846977,
            PHP_INT_MAX,
            PHP_INT_MAX - 1,
            9223372036854775296,
            9223372036854775295,
            PHP_INT_MIN,
            PHP_INT_MIN + 1,
            -Canonicalizer::MAX_SAFE_INTEGER - 2,
        ];

        $values = $edges;

        for ($i = 0; $i < 2000; $i++) {
            $value = random_int(Canonicalizer::MAX_SAFE_INTEGER + 1, PHP_INT_MAX);
            $values[] = random_int(0, 1) === 1 ? -$value : $value;
        }

        foreach ($values as $value) {
            /*
             | Wrapped, because the failure mode here is not a wrong answer.
             |
             | Removing the 2^63 guards made the method hang - `-PHP_INT_MIN`
             | overflows into a float and shifting a float never reaches zero,
             | so the loop ran until the process was killed. With the loop
             | bounded it throws instead. Either way an unguarded call turns
             | this rule into a crash rather than a finding, and a check that
             | cannot report is not a check.
             */
            try {
                /** @var string $computed */
                $computed = $method->invoke(null, $value);
            } catch (Throwable $e) {
                $problems[] = sprintf(
                    'Computing the alternative for %d raised %s: %s. This runs inside a refusal, so it '.
                    'turns a clear error message into a stack trace - or, before the loop was bounded, '.
                    'into a request that never returns.',
                    $value,
                    $e::class,
                    $e->getMessage(),
                );

                break;
            }

            // The reference: the cast the method is forbidden to make.
            $reference = sprintf('%.0f', (float) $value);

            if ($computed !== $reference) {
                $problems[] = sprintf(
                    'For %d the refusal would name %s; IEEE-754 rounding gives %s. The message exists to '.
                    'be convincing - a wrong alternative in it sends somebody looking for a bug that is '.
                    'not there.',
                    $value,
                    $computed,
                    $reference,
                );

                if (\count($problems) > 4) {
                    break;
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function invalidUtf8IsRefused(): array
    {
        $problems = [];

        $broken = [
            'a truncated two-byte sequence' => "\xC3",
            'a continuation byte on its own' => "\x80",
            'an over-long encoding of NUL' => "\xC0\x80",
            'a lone high surrogate in CESU-8' => "\xED\xA0\x80",
        ];

        foreach ($broken as $description => $value) {
            try {
                Canonicalizer::encode(['v' => $value]);

                $problems[] = sprintf(
                    '%s was canonicalized. A hash over invalid UTF-8 is a hash of bytes nobody else will '.
                    'reproduce: every other implementation either refuses the input or substitutes a '.
                    'replacement character, and both give a different hash.',
                    $description,
                );
            } catch (NotCanonicalizable) {
                // Refused.
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theGoldenVectorHolds(): array
    {
        $actual = Canonicalizer::hash($this->goldenPayload());

        if (hash_equals(self::GOLDEN_HASH, $actual)) {
            return [];
        }

        return [sprintf(
            "The canonical hash of the fixed payload has moved.\n".
            "       recorded %s\n".
            "       computed %s\n".
            '       Canonical form: %s'."\n".
            '       Nothing about that payload changed, so something about how it is serialized did. '.
            'Every stored document hash in every tenant was computed with the old one.',
            self::GOLDEN_HASH,
            $actual,
            Canonicalizer::encode($this->goldenPayload()),
        )];
    }

    /** @return list<string> */
    private function xmlIgnoresTheInsignificant(): array
    {
        $problems = [];

        $reference = '<Invoice xmlns="urn:x" xmlns:q="urn:q"><ID>INV-1</ID><Total q:c="S">100.00</Total></Invoice>';
        $base = XmlCanonicalizer::hash($reference);

        $equivalent = [
            'attributes in another order' => '<Invoice xmlns:q="urn:q" xmlns="urn:x"><ID>INV-1</ID><Total q:c="S">100.00</Total></Invoice>',
            'extra whitespace between attributes' => '<Invoice   xmlns="urn:x"    xmlns:q="urn:q"><ID>INV-1</ID><Total   q:c="S">100.00</Total></Invoice>',
            'single quotes on attribute values' => "<Invoice xmlns='urn:x' xmlns:q='urn:q'><ID>INV-1</ID><Total q:c='S'>100.00</Total></Invoice>",
            'an XML declaration' => '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:x" xmlns:q="urn:q"><ID>INV-1</ID><Total q:c="S">100.00</Total></Invoice>',
            'a comment' => '<Invoice xmlns="urn:x" xmlns:q="urn:q"><!-- draft --><ID>INV-1</ID><Total q:c="S">100.00</Total></Invoice>',
            'CDATA around the same text' => '<Invoice xmlns="urn:x" xmlns:q="urn:q"><ID><![CDATA[INV-1]]></ID><Total q:c="S">100.00</Total></Invoice>',
            'a numeric character reference' => '<Invoice xmlns="urn:x" xmlns:q="urn:q"><ID>INV&#45;1</ID><Total q:c="S">100.00</Total></Invoice>',
        ];

        foreach ($equivalent as $description => $variant) {
            try {
                $hash = XmlCanonicalizer::hash($variant);
            } catch (Throwable $e) {
                $problems[] = sprintf('%s was refused: %s', $description, $e->getMessage());

                continue;
            }

            if (! hash_equals($base, $hash)) {
                $problems[] = sprintf(
                    '%s produced a different hash. None of these changes the information set, so a '.
                    'signature that breaks on one of them breaks the first time the document passes '.
                    'through any processor that reserializes it - which every one of them does.',
                    $description,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function xmlNoticesTheSignificant(): array
    {
        $problems = [];

        $reference = '<Invoice xmlns="urn:x"><ID>INV-1</ID><Total>100.00</Total></Invoice>';
        $base = XmlCanonicalizer::hash($reference);

        $different = [
            'a changed amount' => '<Invoice xmlns="urn:x"><ID>INV-1</ID><Total>100.01</Total></Invoice>',
            'a trailing zero added to the amount' => '<Invoice xmlns="urn:x"><ID>INV-1</ID><Total>100.000</Total></Invoice>',
            'two elements swapped' => '<Invoice xmlns="urn:x"><Total>100.00</Total><ID>INV-1</ID></Invoice>',
            'a different namespace' => '<Invoice xmlns="urn:y"><ID>INV-1</ID><Total>100.00</Total></Invoice>',
            'whitespace inside an element' => '<Invoice xmlns="urn:x"><ID>INV-1</ID> <Total>100.00</Total></Invoice>',
            'a leading space in the text' => '<Invoice xmlns="urn:x"><ID> INV-1</ID><Total>100.00</Total></Invoice>',
        ];

        foreach ($different as $description => $variant) {
            if (hash_equals($base, XmlCanonicalizer::hash($variant))) {
                $problems[] = sprintf(
                    '%s produced the SAME hash. Element order and whitespace inside content are both '.
                    'significant in XML, and a canonicalization that discarded either would let a '.
                    'document be altered without the signature noticing.',
                    $description,
                );
            }
        }

        return $problems;
    }

    /**
     * The flag whose name reads as the defence and is the vulnerability.
     *
     * `LIBXML_NOENT` maps to `XML_PARSE_NOENT`, which ENABLES entity
     * substitution. Measured: with it, a document declaring
     * `file:///etc/passwd` canonicalized to the contents of the password file.
     *
     * This rule asserts both halves - that the canonicalizer refuses the
     * document, and that the dangerous flag still is dangerous. The second
     * matters because if libxml changed and the leak stopped, the first would
     * keep passing while guarding nothing.
     *
     * @return list<string>
     */
    private function entitiesCannotReachOut(): array
    {
        $problems = [];

        $secret = sys_get_temp_dir().'/peakflow-canon-secret-'.bin2hex(random_bytes(6)).'.txt';
        $marker = 'PEAKFLOW-XXE-MARKER-'.bin2hex(random_bytes(8));
        file_put_contents($secret, $marker);

        try {
            $xxe = sprintf(
                '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://%s">]><r>&x;</r>',
                $secret,
            );

            try {
                $canonical = XmlCanonicalizer::canonicalize($xxe);

                $problems[] = str_contains($canonical, $marker)
                    ? 'An external entity was read and canonicalized into the output. A document hash '.
                      'that can exfiltrate a file is a file read with a signature on it.'
                    : 'A document with a DOCTYPE was canonicalized. Even where nothing leaked, an '.
                      'unexpanded entity reference canonicalizes to nothing - so the hash is of a '.
                      'document missing its content, and a conformant processor computes a different one.';
            } catch (NotCanonicalizable) {
                // Refused, as it must be.
            }

            /*
             | And the measurement that justifies the refusal, repeated here.
             |
             | If this stops leaking, the refusal is guarding against something
             | that has gone away and should be revisited rather than kept out
             | of habit.
             */
            $raw = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $raw->loadXML($xxe, LIBXML_NONET | LIBXML_NOENT);
            $leaked = (string) $raw->C14N(false, false);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (! str_contains($leaked, $marker)) {
                $problems[] = 'LIBXML_NOENT no longer causes an external entity to be read into the '.
                    'canonical output. That was the measured reason a DOCTYPE is refused outright, so '.
                    'the reasoning in XmlCanonicalizer should be rechecked rather than trusted.';
            }

            // Nested expansion, the same mechanism pointed at memory.
            $laughs = '<?xml version="1.0"?><!DOCTYPE r ['
                .'<!ENTITY a "aaaaaaaaaa">'
                .'<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
                .'<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
                .']><r>&c;</r>';

            try {
                XmlCanonicalizer::canonicalize($laughs);

                $problems[] = 'A nested entity expansion was canonicalized rather than refused.';
            } catch (NotCanonicalizable) {
                // Refused.
            }
        } finally {
            @unlink($secret);
        }

        return $problems;
    }

    /** @return list<string> */
    private function xmlRefusesWhatItCannotDo(): array
    {
        $problems = [];

        $refusals = [
            'nothing at all' => '',
            'only whitespace' => "  \n ",
            'an unclosed element' => '<r>',
            'two root elements' => '<r/><s/>',
            'a DOCTYPE with no entities' => '<?xml version="1.0"?><!DOCTYPE r><r/>',
            'xml:base, which 1.0 and 1.1 disagree about' => '<r xml:base="http://example.test/"><c/></r>',
            'xml:id, likewise' => '<r xml:id="i1"><c/></r>',
            'xml:base on a descendant' => '<r><c xml:base="http://example.test/"/></r>',
        ];

        foreach ($refusals as $description => $xml) {
            try {
                XmlCanonicalizer::canonicalize($xml);

                $problems[] = sprintf(
                    '%s was canonicalized. Producing 1.0 output for a document whose signature claims '.
                    '1.1 is a signature that verifies nowhere else - and PHP\'s DOM API exposes no 1.1 '.
                    'mode at all, so the honest answer is a refusal.',
                    $description,
                );
            } catch (NotCanonicalizable) {
                // Refused.
            } catch (Throwable $e) {
                $problems[] = sprintf('%s raised %s: %s', $description, $e::class, $e->getMessage());
            }
        }

        // And xml:lang / xml:space are NOT refused: 1.0 and 1.1 agree on them.
        try {
            XmlCanonicalizer::canonicalize('<r xml:lang="ar" xml:space="preserve"><c/></r>');
        } catch (Throwable $e) {
            $problems[] = sprintf(
                'xml:lang and xml:space were refused: %s. Both algorithms treat them the same way for a '.
                'whole document, and refusing them would rule out every Arabic document that declares '.
                'its language.',
                $e->getMessage(),
            );
        }

        // Fragment selection refuses what it cannot sign.
        $document = '<I xmlns="urn:u"><L>1</L><L>2</L><S>sig</S></I>';

        $fragmentRefusals = [
            'an XPath matching nothing' => '//u:Z',
            'an XPath matching two nodes' => '//u:L',
            'an XPath matching an attribute' => '//u:L/@*',
        ];

        foreach ($fragmentRefusals as $description => $xpath) {
            try {
                XmlCanonicalizer::canonicalizeFragment($document, $xpath, ['u' => 'urn:u']);

                $problems[] = sprintf(
                    '%s was accepted. A signature must name one element: which of several matches was '.
                    'signed is not recoverable from the signature afterwards, and a signature over '.
                    'nothing verifies.',
                    $description,
                );
            } catch (NotCanonicalizable) {
                // Refused.
            }
        }

        $signed = XmlCanonicalizer::canonicalizeFragment($document, '//u:S', ['u' => 'urn:u']);

        if ($signed !== '<S xmlns="urn:u">sig</S>') {
            $problems[] = sprintf(
                'A single-element fragment canonicalized to "%s". An ancestor\'s namespace declaration '.
                'has to travel with the fragment, or the signed bytes describe an element in no '.
                'namespace.',
                $signed,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function exclusiveDropsOnlyUnused(): array
    {
        $problems = [];

        $xml = '<r xmlns="urn:d" xmlns:used="urn:u" xmlns:spare="urn:s"><used:c/></r>';

        $inclusive = XmlCanonicalizer::canonicalize($xml, false);
        $exclusive = XmlCanonicalizer::canonicalize($xml, true);

        if (! str_contains($inclusive, 'xmlns:spare')) {
            $problems[] = 'Inclusive canonicalization dropped an unused namespace declaration. It must '.
                'not: a recipient\'s schema validator may need it, and inclusive C14N is defined to keep '.
                'it.';
        }

        if (str_contains($exclusive, 'xmlns:spare')) {
            $problems[] = 'Exclusive canonicalization kept the unused namespace declaration, which is '.
                'the one thing it exists to drop - a fragment lifted out of a larger document carries '.
                'its parent\'s declarations otherwise, and the signature then covers bytes that depend '.
                'on where the fragment used to live.';
        }

        if (! str_contains($exclusive, 'xmlns:used')) {
            $problems[] = 'Exclusive canonicalization dropped a namespace the node set USES, which '.
                'changes what the element is.';
        }

        if ($inclusive === $exclusive) {
            $problems[] = 'Inclusive and exclusive canonicalization produced the same bytes for a '.
                'document with an unused namespace declaration, so one of the two is not doing what it '.
                'claims.';
        }

        foreach ([XmlCanonicalizer::INCLUSIVE_1_0, XmlCanonicalizer::EXCLUSIVE_1_0] as $uri) {
            if (! str_starts_with($uri, 'http://www.w3.org/')) {
                $problems[] = sprintf('The algorithm identifier %s is not a W3C URI.', $uri);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theChainUsesTheCanonicalForm(): array
    {
        $problems = [];

        $fields = ['invoice' => 'INV-1', 'total' => '100.0000'];
        $first = ChainLink::link($fields);

        if (! hash_equals($first->hash, Canonicalizer::hash($fields))) {
            $problems[] = 'A chain link\'s hash is not the canonical hash of its fields, so the chain is '.
                'protecting a serialization nobody else can reproduce.';
        }

        if (! $first->isGenesis() || $first->index !== 1) {
            $problems[] = 'The first link is not genesis at index 1.';
        }

        // A three-document chain, then one altered in the middle.
        $documents = [];
        $previous = null;

        foreach (['INV-1', 'INV-2', 'INV-3', 'INV-4'] as $reference) {
            $theseFields = ['invoice' => $reference, 'total' => '100.0000'];
            $link = ChainLink::link($theseFields, $previous);
            $documents[] = ['reference' => $reference, 'link' => $link, 'fields' => $theseFields];
            $previous = $link;
        }

        $walker = new ChainWalker();
        $intact = $walker->walk($documents);

        if (! $intact->isIntact()) {
            $problems[] = sprintf('An untouched four-document chain was reported broken: %s', $intact->describe());
        }

        /*
         | Two breaks, and the FIRST one must be named.
         |
         | A verdict that says "the chain is broken" on a series of forty
         | thousand documents is not actionable. The first divergence is:
         | everything before it is intact, everything after is unverifiable.
         */
        $tampered = $documents;
        $tampered[1]['fields']['total'] = '999.0000';
        $tampered[3]['fields']['total'] = '888.0000';

        $verdict = $walker->walk($tampered);

        if ($verdict->isIntact()) {
            $problems[] = 'A chain with two altered documents was reported intact.';
        } elseif (! str_contains($verdict->describe(), 'INV-2')) {
            $problems[] = sprintf(
                'The verdict on a chain altered at documents 2 and 4 does not name INV-2: "%s". The '.
                'first divergence is the actionable fact, and naming the later one sends somebody to '.
                'the wrong document.',
                $verdict->describe(),
            );
        }

        if (! $verdict->isIntact() && str_contains($verdict->describe(), 'INV-4')) {
            $problems[] = sprintf(
                'The verdict names INV-4 as well as INV-2: "%s". Everything after the first divergence '.
                'is unverifiable rather than wrong, and reporting it as wrong is how four thousand '.
                'documents get investigated for one edit.',
                $verdict->describe(),
            );
        }

        return $problems;
    }
}
