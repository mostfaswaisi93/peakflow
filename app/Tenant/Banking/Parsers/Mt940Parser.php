<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Tenant\Banking\Exceptions\StatementUnreadable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * SWIFT MT940 and MT942 - PF-152.
 *
 * ## A degraded path, and it says so
 *
 * MT940 is legacy, ubiquitous and lossy. It has no structured remittance, no
 * end-to-end id, no ISO transaction code and no counterparty account in any
 * dependable place. Everything a matcher would want is in `:86:`, which is
 * semi-structured free text whose layout is **bank-specific** - and the
 * standard does not say what goes in it.
 *
 * So matching confidence from MT940 is structurally lower than from
 * CAMT.053, not incidentally lower, and `banking.formats` records that as
 * `fidelity: low` for the UI to state. Where a bank offers both, the import
 * takes the CAMT.
 *
 * ## An unparseable `:86:` is kept verbatim
 *
 * Three layouts are common and none is universal: the German `?20?`
 * numbered sub-fields, SWIFT's `/EREF/` tagged segments, and plain prose.
 * The reader tries each, and where none fits it keeps the text exactly as
 * it arrived rather than dropping it - a human reading the line can still
 * see what the bank wrote, which is the whole value of the field.
 *
 * Dropping it would be the easy choice and the worst one: the field is the
 * only place a reference appears in this format.
 *
 * ## MT942 states no balances, and that is not a defect
 *
 * The interim message carries movements since the last statement and no
 * `:60F:`/`:62F:` at all. The parser reports null balances rather than
 * inventing them; the importer fills them from the account's own booked
 * position, so the intraday statement carries zero booked movement - which
 * is exactly what it is.
 */
final class Mt940Parser implements StatementParser
{
    /** @param 'mt940'|'mt942' $format */
    public function __construct(
        private readonly string $format = 'mt940',
        private readonly string $profile = 'generic',
    ) {}

    public function format(): string
    {
        return $this->format;
    }

    public function recognises(string $content): bool
    {
        $head = substr($content, 0, 4096);

        if (str_contains($head, '<?xml') || str_contains($head, '<OFX')) {
            return false;
        }

        if (preg_match('/^:20:/m', $head) !== 1) {
            return false;
        }

        /*
         | The two messages are told apart by their balances.
         |
         | An MT940 states `:60F:` and `:62F:`; an MT942 has no balance tags
         | at all and carries `:34F:`, the floor limit, instead. Both
         | otherwise look identical, and the difference matters: one posts
         | and reconciles, the other is provisional. A sniffer that could
         | not tell them apart would import an interim report as a booked
         | statement, which double-counts every line when the real statement
         | arrives.
         */
        $hasBalances = preg_match('/^:6[02][FM]:/m', $content) === 1;

        if ($this->format === 'mt942') {
            return ! $hasBalances && (
                preg_match('/^:34F:/m', $content) === 1 || preg_match('/^:13D:/m', $content) === 1
            );
        }

        return $hasBalances && preg_match('/^:61:/m', $content) === 1;
    }

    /**
     * @return list<ParsedStatement>
     *
     * @throws StatementUnreadable
     */
    public function parse(string $content): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $content);

        /*
         | A file can hold several statements, one per `:20:`.
         |
         | Split on the tag rather than on a page break, because the page
         | breaks differ by bank and one of them is a form feed.
         */
        $blocks = preg_split('/\n(?=:20:)/', trim($normalised)) ?: [];
        $statements = [];

        foreach ($blocks as $block) {
            if (trim($block) === '') {
                continue;
            }

            $statements[] = $this->statement($block);
        }

        if ($statements === []) {
            throw StatementUnreadable::notTheFormatItClaims(
                $this->format,
                'a text file with no SWIFT statement block in it',
            );
        }

        return $statements;
    }

    /**
     * @throws StatementUnreadable
     */
    private function statement(string $block): ParsedStatement
    {
        $tags = $this->tags($block);

        $account = $tags['25'][0] ?? null;

        if ($account === null) {
            throw StatementUnreadable::missing(
                $this->format,
                'an account identification (:25:)',
                'which account the statement is for - and importing a file into the wrong account '
                .'produces a reconciliation that can never be completed',
            );
        }

        /*
         | `:25:` is "bank/account" as often as it is an account.
         |
         | The part after the last slash is the account; some banks append
         | the currency after it, which is stripped so the comparison with
         | the bank account's own identifier can succeed.
         */
        $account = trim((string) preg_replace('/\s+[A-Z]{3}$/', '', trim($account)));
        $identifier = str_contains($account, '/') ? substr($account, (int) strrpos($account, '/') + 1) : $account;

        $opening = $this->balance($tags['60F'][0] ?? $tags['60M'][0] ?? null);
        $closing = $this->balance($tags['62F'][0] ?? $tags['62M'][0] ?? null);

        /*
         | An MT942 has no balance tags, so its currency is on `:34F:`.
         |
         | The floor limit is the only tag in the interim message that
         | carries one. Without it every interim line would be stored as
         | "XXX", which looks like a value and is not - and the account's
         | own currency would never be checked against the file's.
         */
        $currency = $opening['currency']
            ?? $closing['currency']
            ?? $this->floorLimitCurrency($tags['34F'][0] ?? null);

        $lines = $this->lines($block, $currency);

        [$from, $to] = $this->period($tags, $lines);

        return new ParsedStatement(
            sourceFormat: $this->format,
            accountIban: preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $identifier) === 1 ? $identifier : null,
            accountOtherId: $identifier,
            currency: $currency === null ? ParsedStatement::UNSTATED_CURRENCY : strtoupper($currency),
            periodFrom: $from,
            periodTo: $to,
            openingBalance: $opening['amount'] ?? null,
            closingBalance: $closing['amount'] ?? null,
            lines: $lines,
            statementNumber: $tags['28C'][0] ?? $tags['28'][0] ?? $tags['20'][0] ?? null,
        );
    }

    /**
     * `:61:` statement lines, each with the `:86:` that follows it.
     *
     * @return list<ParsedLine>
     */
    private function lines(string $block, ?string $currency): array
    {
        $lines = [];

        /*
         | Each `:61:` runs to the next tag at the start of a line.
         |
         | A `:61:` can carry a second physical line of supplementary
         | details, and the `:86:` that follows belongs to it. Matching to
         | the next tag keeps both without a state machine.
         */
        $pattern = '/^:61:(?P<line>.*?)(?=^:[0-9]{2}[A-Z]?:|\z)/ms';

        if (preg_match_all($pattern, $block."\n", $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return $lines;
        }

        foreach ($matches as $match) {
            $body = (string) $match['line'][0];
            $offset = (int) $match['line'][1];

            $information = null;

            if (preg_match('/^:86:(?P<info>.*?)(?=^:[0-9]{2}[A-Z]?:|\z)/ms', substr($block."\n", $offset + \strlen($body)), $found) === 1) {
                $information = trim((string) $found['info']);
            }

            $line = $this->line($body, $information, $currency);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function line(string $body, ?string $information, ?string $currency): ?ParsedLine
    {
        $body = trim($body);

        /*
         | The `:61:` grammar, in the order SWIFT defines it.
         |
         | Value date, an optional entry date with no year, the debit/credit
         | mark (where `R` prefixes a reversal), an optional funds code, the
         | amount with a comma for a decimal point, the transaction type,
         | and the references.
         */
        $pattern = '/^(?P<value>\d{6})'
            .'(?P<entry>\d{4})?'
            .'(?P<mark>R?[CD])'
            .'(?P<funds>[A-Z])?'
            .'(?P<amount>[\d,\.]+)'
            .'(?P<type>N[A-Z0-9]{3}|[A-Z]{4})?'
            .'(?P<references>[^\n]*)'
            .'(?:\n(?P<supplementary>.*))?$/s';

        if (preg_match($pattern, $body, $parts) !== 1) {
            return null;
        }

        $valueDate = $this->date((string) $parts['value']);

        if ($valueDate === null) {
            return null;
        }

        $mark = (string) $parts['mark'];
        $isReversal = str_starts_with($mark, 'R');
        $isDebit = str_ends_with($mark, 'D');

        $amount = BigDecimal::of(str_replace(',', '.', (string) $parts['amount']));

        /*
         | `RC` and `RD` are reversals, and the letter still gives the
         | direction of the booking that happened.
         |
         | The same rule as CAMT's `RvslInd`: the mark describes what the
         | bank did, not what it undid. Flipping it turns a reversed credit
         | into a double debit.
         */
        if ($isDebit) {
            $amount = $amount->negated();
        }

        $references = trim((string) ($parts['references'] ?? ''));
        $customerReference = $references;
        $bankReference = null;

        if (str_contains($references, '//')) {
            [$customerReference, $bankReference] = explode('//', $references, 2);
            $customerReference = trim($customerReference);
            $bankReference = trim($bankReference);
        }

        $entry = $this->entryDate((string) ($parts['entry'] ?? ''), $valueDate);
        $parsed = $this->information($information);

        return new ParsedLine(
            bookingDate: $entry ?? $valueDate,
            valueDate: $valueDate,
            amount: (string) $amount->toScale(4),
            currency: strtoupper((string) ($currency ?? ParsedStatement::UNSTATED_CURRENCY)),
            counterpartyName: $parsed['counterparty_name'],
            counterpartyAccount: $parsed['counterparty_account'],
            remittanceUnstructured: $parsed['remittance'] ?? ($information === '' ? null : $information),
            bankReference: $bankReference !== '' ? $bankReference : null,
            endToEndId: $parsed['end_to_end_id'],
            bankCodeProprietary: ($parts['type'] ?? '') !== '' ? (string) $parts['type'] : null,
            isReversal: $isReversal,
            /*
             | The customer reference, where the bank gave one.
             |
             | `NONREF` is what a bank sends when there is none, and storing
             | it as a reference would make every such line look as though
             | it carried one.
             */
            reversesReference: $isReversal && $customerReference !== '' && $customerReference !== 'NONREF'
                ? $customerReference
                : null,
            isPending: $this->format === 'mt942',
            sourcePayload: [
                'tag_61' => $body,
                'tag_86' => $information,
                'customer_reference' => $customerReference === 'NONREF' ? null : ($customerReference ?: null),
                'supplementary' => trim((string) ($parts['supplementary'] ?? '')) ?: null,
            ],
        );
    }

    /**
     * `:86:`, in whichever of the three layouts this bank uses.
     *
     * @return array{counterparty_name: ?string, counterparty_account: ?string, remittance: ?string, end_to_end_id: ?string}
     */
    private function information(?string $information): array
    {
        $empty = [
            'counterparty_name' => null,
            'counterparty_account' => null,
            'remittance' => null,
            'end_to_end_id' => null,
        ];

        if ($information === null || trim($information) === '') {
            return $empty;
        }

        $text = trim(preg_replace('/\s*\n\s*/', ' ', $information) ?? $information);
        $profiles = (array) config('banking.mt940.profiles', []);
        $map = (array) ($profiles[$this->profile] ?? $profiles['generic'] ?? []);

        /*
         | The German numbered form: `?20?remittance?32?name`.
         |
         | The sub-field numbers mean different things at different banks,
         | which is why the map is configuration - a bank using 20-29 for
         | remittance and 32-33 for the name is the common case and not the
         | only one.
         */
        if (preg_match('/\?\d{2}\?/', $text) === 1) {
            $fields = [];

            if (preg_match_all('/\?(?P<key>\d{2})\?(?P<value>[^?]*)/', $text, $found, PREG_SET_ORDER) > 0) {
                foreach ($found as $field) {
                    $fields[(string) $field['key']][] = trim((string) $field['value']);
                }
            }

            return [
                'counterparty_name' => $this->fromFields($fields, (array) ($map['name'] ?? ['32', '33'])),
                'counterparty_account' => $this->fromFields($fields, (array) ($map['account'] ?? ['31'])),
                'remittance' => $this->fromFields($fields, (array) ($map['remittance'] ?? ['20', '21', '22', '23', '24', '25', '26', '27', '28', '29'])),
                'end_to_end_id' => null,
            ];
        }

        /*
         | SWIFT's tagged form: `/EREF/...`/`/ORDP/...`/`/REMI/...`.
         |
         | Segments run to the next slash-tag, so a remittance containing a
         | slash survives.
         */
        if (preg_match('#/[A-Z]{3,4}/#', $text) === 1) {
            $segments = [];

            if (preg_match_all('#/(?P<key>[A-Z]{3,4})/(?P<value>.*?)(?=/[A-Z]{3,4}/|$)#', $text, $found, PREG_SET_ORDER) > 0) {
                foreach ($found as $segment) {
                    $segments[(string) $segment['key']] = trim((string) $segment['value']);
                }
            }

            return [
                'counterparty_name' => $segments['ORDP'] ?? $segments['BENM'] ?? null,
                'counterparty_account' => $segments['IBAN'] ?? $segments['ACCW'] ?? null,
                'remittance' => $segments['REMI'] ?? $segments['RTRN'] ?? null,
                'end_to_end_id' => $segments['EREF'] ?? null,
            ];
        }

        /*
         | Neither layout fits, so nothing is extracted here.
         |
         | The text is still kept - `line()` falls back to the raw `:86:`
         | when no remittance was extracted - and keeping it in BOTH places
         | was two implementations of one rule. Removing either changed
         | nothing, which is how it was found: a bite that dropped this
         | branch left every check green, because the fallback caught it.
         |
         | The fallback is the one that stays, because it also covers the
         | layouts that parse but yield no remittance field.
         */
        return $empty;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  list<string>  $keys
     */
    private function fromFields(array $fields, array $keys): ?string
    {
        $parts = [];

        foreach ($keys as $key) {
            foreach ($fields[$key] ?? [] as $value) {
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * A `:60F:`-style balance: mark, date, currency, amount.
     *
     * @return array{amount: ?string, currency: ?string, date: ?Carbon}
     */
    private function balance(?string $value): array
    {
        if ($value === null) {
            return ['amount' => null, 'currency' => null, 'date' => null];
        }

        if (preg_match('/^(?P<mark>[CD])(?P<date>\d{6})(?P<currency>[A-Z]{3})(?P<amount>[\d,\.]+)/', trim($value), $parts) !== 1) {
            return ['amount' => null, 'currency' => null, 'date' => null];
        }

        $amount = BigDecimal::of(str_replace(',', '.', (string) $parts['amount']));

        /*
         | `D` on a balance is an overdraft.
         |
         | Same as CAMT's `Bal/CdtDbtInd`, and the same consequence if it is
         | ignored: an overdrawn account reported as having money in it.
         */
        if ((string) $parts['mark'] === 'D') {
            $amount = $amount->negated();
        }

        return [
            'amount' => (string) $amount->toScale(4),
            'currency' => strtoupper((string) $parts['currency']),
            'date' => $this->date((string) $parts['date']),
        ];
    }

    /**
     * @param  array<string, list<string>>  $tags
     * @param  list<ParsedLine>  $lines
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(array $tags, array $lines): array
    {
        $opening = $this->balance($tags['60F'][0] ?? $tags['60M'][0] ?? null)['date'] ?? null;
        $closing = $this->balance($tags['62F'][0] ?? $tags['62M'][0] ?? null)['date'] ?? null;

        if ($opening !== null && $closing !== null) {
            return [$opening, $closing];
        }

        /*
         | MT942 has no balance tags, so the period is its own entries.
         |
         | `:13D:` is the report's creation time and is a fallback for an
         | interim report with no movements at all - which happens, and is
         | not an error.
         */
        $dates = array_map(static fn (ParsedLine $line): Carbon => $line->bookingDate, $lines);

        if ($dates !== []) {
            usort($dates, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

            return [reset($dates), end($dates)];
        }

        $created = $tags['13D'][0] ?? null;

        if ($created !== null && preg_match('/^(\d{6})/', trim($created), $found) === 1) {
            $day = $this->date((string) $found[1]) ?? Carbon::now();

            return [$day, $day->copy()];
        }

        $today = Carbon::now()->startOfDay();

        return [$today, $today->copy()];
    }

    /** `:34F:` is `SARD0,00` or `SAR0,00`: the currency, an optional mark, the limit. */
    private function floorLimitCurrency(?string $value): ?string
    {
        if ($value === null || preg_match('/^(?P<currency>[A-Z]{3})/', trim($value), $parts) !== 1) {
            return null;
        }

        return strtoupper((string) $parts['currency']);
    }

    /** @return array<string, list<string>> */
    private function tags(string $block): array
    {
        $tags = [];

        if (preg_match_all('/^:(?P<tag>[0-9]{2}[A-Z]?):(?P<value>.*?)(?=^:[0-9]{2}[A-Z]?:|\z)/ms', $block."\n", $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $tags[(string) $match['tag']][] = trim((string) $match['value']);
            }
        }

        return $tags;
    }

    /** `YYMMDD`, with the century the only thing SWIFT leaves to the reader. */
    private function date(string $value): ?Carbon
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        $year = (int) $parts[1];

        /*
         | Two digits, and no century anywhere in the message.
         |
         | The convention everyone uses: 00-79 is 2000-2079. A statement
         | from 1980 is not a thing anybody imports, and the alternative -
         | a sliding window around today - makes the same file parse
         | differently next year.
         */
        $century = $year <= 79 ? 2000 : 1900;

        return Carbon::createFromDate($century + $year, (int) $parts[2], (int) $parts[3])?->startOfDay();
    }

    /** `MMDD`, whose year is the value date's unless that would run backwards. */
    private function entryDate(string $value, Carbon $valueDate): ?Carbon
    {
        if (preg_match('/^(\d{2})(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        $month = (int) $parts[1];
        $day = (int) $parts[2];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $entry = Carbon::createFromDate($valueDate->year, $month, $day)->startOfDay();

        /*
         | A booking in December against a value date in January.
         |
         | The entry date carries no year, so a statement that straddles the
         | turn of the year produces an entry date eleven months in the
         | future. It is the previous year's.
         */
        if ($entry->diffInDays($valueDate, false) < -180) {
            $entry = $entry->subYear();
        }

        if ($entry->diffInDays($valueDate, false) > 180) {
            $entry = $entry->addYear();
        }

        return $entry;
    }
}
