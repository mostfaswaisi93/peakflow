<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Tenant\Banking\Exceptions\StatementUnreadable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * OFX and QFX - PF-153.
 *
 * ## Two eras of the same format, and both are still sent
 *
 * OFX 1.x is SGML: tags are not closed, and `<NAME>Acme Supplies` ends at
 * the next tag. OFX 2.x is XML. A retail bank picked one some time around
 * 2003 and has not revisited the decision, so a reader that handles only the
 * XML era refuses roughly half the files people actually have.
 *
 * Rather than two readers, the SGML form is **closed into XML** first: every
 * unclosed value tag gets its closing tag, and the result goes through the
 * same safe parser as the XML form. One reading path, and the XXE and
 * DOCTYPE guards apply to both.
 *
 * A regular expression closing SGML tags is not elegant. The alternative is
 * an SGML parser for a dialect with eleven element types, and the mapping it
 * would produce is the one written here anyway.
 *
 * ## `TRNTYPE` is mapped into the ISO code space
 *
 * So that downstream routing has one vocabulary. PF-155 picks a matching
 * strategy from the ISO domain and family, and a second vocabulary for
 * retail banks would mean every rule written twice - with the second copy
 * drifting.
 *
 * The mapping is deliberately coarse. OFX says `DEBIT` where CAMT would say
 * which kind of debit, and inventing a sub-family to fill the gap would
 * state more than the file does.
 */
final class OfxParser implements StatementParser
{
    public function format(): string
    {
        return 'ofx';
    }

    public function recognises(string $content): bool
    {
        $head = strtoupper(substr($content, 0, 4096));

        return str_contains($head, '<OFX>')
            || str_contains($head, 'OFXHEADER')
            || str_contains($head, '<STMTRS>');
    }

    /**
     * @return list<ParsedStatement>
     *
     * @throws StatementUnreadable
     */
    public function parse(string $content): array
    {
        $xml = $this->asXml($content);

        try {
            $document = \App\Support\Xml\SafeXmlReader::parse($xml);
        } catch (\App\Support\Xml\MalformedXml $e) {
            throw StatementUnreadable::malformed('ofx', $e->getMessage(), $e->atLine, $e->atColumn);
        }

        $xpath = \App\Support\Xml\SafeXmlReader::selector($document);

        $responses = $xpath->query('//*[local-name()="STMTRS" or local-name()="CCSTMTRS"]');

        if ($responses === false || $responses->length === 0) {
            throw StatementUnreadable::notTheFormatItClaims(
                'ofx',
                'a document with no OFX statement response (STMTRS) in it',
            );
        }

        $statements = [];

        foreach ($responses as $response) {
            $statements[] = $this->statement($xpath, $response);
        }

        return $statements;
    }

    /**
     * @throws StatementUnreadable
     */
    private function statement(\DOMXPath $xpath, \DOMNode $response): ParsedStatement
    {
        $currency = strtoupper((string) (
            $this->first($xpath, './/*[local-name()="CURDEF"]', $response) ?? 'XXX'
        ));

        $lines = [];

        foreach ($xpath->query('.//*[local-name()="STMTTRN"]', $response) ?: [] as $transaction) {
            $line = $this->transaction($xpath, $transaction, $currency);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $closing = $this->first($xpath, './/*[local-name()="LEDGERBAL"]/*[local-name()="BALAMT"]', $response);

        if ($closing === null) {
            throw StatementUnreadable::missing(
                'ofx',
                'a ledger balance (LEDGERBAL/BALAMT)',
                'what the bank says the account held at the end of the period',
            );
        }

        $closing = (string) BigDecimal::of($this->clean($closing))->toScale(4);

        /*
         | OFX gives the closing balance and never the opening one.
         |
         | So the opening is the closing less what the lines did - which is
         | arithmetic on figures the file states, not a guess. It also means
         | the lines tie by construction, so the "lines add up" check cannot
         | catch a dropped line in an OFX file; what catches that is
         | continuity against the previous statement, which an OFX import
         | still has to satisfy.
         */
        $movement = BigDecimal::zero();

        foreach ($lines as $line) {
            $movement = $movement->plus($line->amount);
        }

        $opening = (string) BigDecimal::of($closing)->minus($movement)->toScale(4);

        [$from, $to] = $this->period($xpath, $response, $lines);

        $account = $this->first($xpath, './/*[local-name()="ACCTID"]', $response);

        return new ParsedStatement(
            sourceFormat: 'ofx',
            accountIban: null,
            accountOtherId: $account,
            currency: $currency,
            periodFrom: $from,
            periodTo: $to,
            openingBalance: $opening,
            closingBalance: $closing,
            lines: $lines,
            /*
             | OFX has no statement number, and the first FITID is not one.
             |
             | It is a transaction id, and using it would label the
             | statement "FIT-0001" on every screen - which reads as the
             | bank's own numbering and is not. Null lets the statement fall
             | back to our own sequence, which is honest about whose number
             | it is.
             */
            statementNumber: null,
            institutionBic: $this->first($xpath, './/*[local-name()="BANKID"]', $response),
        );
    }

    private function transaction(\DOMXPath $xpath, \DOMNode $node, string $currency): ?ParsedLine
    {
        $amount = $this->first($xpath, './*[local-name()="TRNAMT"]', $node);

        if ($amount === null) {
            return null;
        }

        /*
         | OFX amounts are already signed.
         |
         | Unlike CAMT and MT940, which state magnitude and direction
         | separately. `TRNTYPE` agrees with the sign in every well-formed
         | file, and where it does not the sign is what the bank actually
         | booked - so the sign wins and the type is kept for routing.
         */
        $signed = BigDecimal::of($this->clean($amount));

        $posted = $this->date($this->first($xpath, './*[local-name()="DTPOSTED"]', $node));
        $available = $this->date($this->first($xpath, './*[local-name()="DTAVAIL"]', $node));
        $type = strtoupper((string) $this->first($xpath, './*[local-name()="TRNTYPE"]', $node));

        [$domain, $family, $subfamily] = $this->isoCode($type, $signed->isNegative());

        $memo = $this->first($xpath, './*[local-name()="MEMO"]', $node);
        $name = $this->first($xpath, './*[local-name()="NAME"]', $node);

        return new ParsedLine(
            bookingDate: $posted ?? $available ?? Carbon::now(),
            valueDate: $available ?? $posted ?? Carbon::now(),
            amount: (string) $signed->toScale(4),
            currency: $currency,
            counterpartyName: $name,
            counterpartyAccount: $this->first($xpath, './/*[local-name()="ACCTID"]', $node),
            remittanceUnstructured: $memo ?? $name,
            bankReference: $this->first($xpath, './*[local-name()="FITID"]', $node),
            endToEndId: $this->first($xpath, './*[local-name()="REFNUM"]', $node),
            mandateId: $this->first($xpath, './*[local-name()="CHECKNUM"]', $node),
            codeDomain: $domain,
            codeFamily: $family,
            codeSubfamily: $subfamily,
            bankCodeProprietary: $type !== '' ? $type : null,
            isReversal: $type === 'REVERSAL',
            sourcePayload: ['trntype' => $type !== '' ? $type : null],
        );
    }

    /**
     * OFX's transaction type in the ISO 20022 code space.
     *
     * Coarse on purpose. OFX says `DEBIT` where CAMT would say which kind,
     * and inventing a sub-family to fill the gap would state more than the
     * file does - which is how a routing rule comes to fire on a code no
     * bank ever sent.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function isoCode(string $type, bool $isDebit): array
    {
        return match ($type) {
            'CREDIT', 'DEP', 'DIRECTDEP' => ['PMNT', 'RCDT', null],
            'DEBIT', 'PAYMENT', 'DIRECTDEBIT' => ['PMNT', 'ICDT', null],
            'XFER' => ['PMNT', $isDebit ? 'ICDT' : 'RCDT', 'DMCT'],
            'CHECK' => ['PMNT', 'ICHQ', null],
            'ATM', 'CASH', 'POS' => ['PMNT', 'CCRD', null],
            'FEE', 'SRVCHG' => ['PMNT', 'MDOP', 'CHRG'],
            'INT' => ['PMNT', 'MDOP', 'INTR'],
            'DIV' => ['SECU', 'CORP', null],
            'REPEATPMT' => ['PMNT', 'IDDT', null],
            /*
             | `OTHER`, and anything a bank invented, falls through
             | unmapped - which PF-155 requires: an unmapped code takes the
             | generic passes rather than failing. The proprietary type is
             | still stored, so a rule can be written against it.
             */
            default => [null, null, null],
        };
    }

    /**
     * @param  list<ParsedLine>  $lines
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(\DOMXPath $xpath, \DOMNode $response, array $lines): array
    {
        $from = $this->date($this->first($xpath, './/*[local-name()="DTSTART"]', $response));
        $to = $this->date($this->first($xpath, './/*[local-name()="DTEND"]', $response));

        if ($from !== null && $to !== null) {
            return [$from, $to];
        }

        $dates = array_map(static fn (ParsedLine $line): Carbon => $line->bookingDate, $lines);

        if ($dates !== []) {
            usort($dates, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

            return [$from ?? reset($dates), $to ?? end($dates)];
        }

        $day = $this->date($this->first($xpath, './/*[local-name()="DTASOF"]', $response)) ?? Carbon::now();

        return [$from ?? $day, $to ?? $day->copy()];
    }

    /**
     * Close an SGML-era OFX into XML, and strip the pre-amble.
     *
     * The header is `KEY:VALUE` lines before the first tag, which no XML
     * parser will accept. After it, every tag carrying a value is left
     * unclosed, so each is closed at the next tag or line end.
     */
    private function asXml(string $content): string
    {
        $body = $content;

        $start = strpos($body, '<OFX>');

        if ($start === false) {
            $start = strpos($body, '<?xml');
        }

        if ($start !== false) {
            $body = substr($body, $start);
        }

        if (str_contains(substr($body, 0, 256), '<?xml')) {
            return $body;
        }

        /*
         | A value tag is one with text after it on the same line.
         |
         | `<BANKTRANLIST>` on its own line is a container and is left
         | alone; `<TRNAMT>-25.00` is a value and is closed. The two cannot
         | be told apart by name, only by whether a value follows.
         */
        $closed = preg_replace_callback(
            '/<([A-Z0-9.]+)>([^<\r\n]+)(?![^<]*<\/\1>)/i',
            static fn (array $m): string => sprintf('<%s>%s</%s>', $m[1], htmlspecialchars(trim($m[2]), \ENT_XML1), $m[1]),
            $body,
        );

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".($closed ?? $body);
    }

    /** `YYYYMMDD` with an optional time and an optional bracketed zone. */
    private function date(?string $value): ?Carbon
    {
        if ($value === null || preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($value), $parts) !== 1) {
            return null;
        }

        return Carbon::createFromDate((int) $parts[1], (int) $parts[2], (int) $parts[3])->startOfDay();
    }

    private function first(\DOMXPath $xpath, string $expression, \DOMNode $context): ?string
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->nodeValue);

        return $value === '' ? null : $value;
    }

    private function clean(string $value): string
    {
        return (string) preg_replace('/[\s,]/', '', trim($value));
    }
}
