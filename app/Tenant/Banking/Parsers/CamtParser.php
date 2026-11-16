<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Support\Xml\MalformedXml;
use App\Support\Xml\SafeXmlReader;
use App\Tenant\Banking\Exceptions\StatementUnreadable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * ISO 20022 CAMT.053, .052 and .054 - PF-150, PF-151.
 *
 * ## One reader for three messages, because they are one message
 *
 * `BkToCstmrStmt/Stmt` (053, the booked statement), `BkToCstmrAcctRpt/Rpt`
 * (052, the intraday report) and `BkToCstmrDbtCdtNtfctn/Ntfctn` (054, the
 * debit/credit notification) differ in their root element, in which balances
 * they are obliged to carry, and in whether what they say is final. Below
 * that they are the same `Ntry` structure, read the same way.
 *
 * Three readers would be three copies of the entry parsing, and the copy
 * that gets a fix is the one somebody was looking at.
 *
 * What differs is handled where it differs: {@see self::balancesOf()} will
 * fall back for a report that carries no closing booked balance, and
 * `banking.formats` decides whether the result posts and reconciles.
 *
 * ## The variation this had to survive
 *
 * "ISO 20022" sounds like it settles the question. It does not: the standard
 * permits more variation than its name suggests, and the fixtures here come
 * from four genuinely different bank shapes.
 *
 *   - **Namespace and version.** `.02` through `.08`, and the prefix is
 *     whatever the sender chose. Every expression here uses `local-name()`.
 *   - **Balance codes.** Some banks give `OPBD`/`CLBD`; some give only
 *     `PRCD` (previously closed booked) and `CLBD`; an intraday report
 *     gives `ITBD`. All three openings are the same figure under different
 *     names, and a reader that knew one of them would refuse two banks in
 *     four.
 *   - **Dates.** `BookgDt/Dt` or `BookgDt/DtTm`, and some banks give only
 *     one of booking or value date.
 *   - **Transaction codes.** `BkTxCd/Domn` with its family and sub-family,
 *     or `BkTxCd/Prtry/Cd` alone - which is most domestic banks.
 *   - **Entry details.** One `TxDtls` for a single payment, several under
 *     `Btch` for a run, or none at all for a fee.
 *
 * ## The two sign rules, which are the whole correctness of this
 *
 * **`CdtDbtInd` gives the real direction of the booked amount, always.**
 * `RvslInd` says only that this booking reverses an earlier one; it does
 * **not** mean the sign should be flipped. Flipping it is the obvious
 * reading, it is wrong, and it turns a reversed credit into a double debit -
 * so the flag is carried and the sign is not touched.
 *
 * **A balance has a direction too.** `Bal/CdtDbtInd` of `DBIT` on a current
 * account is an overdraft, and a reader that ignored it would report an
 * overdrawn account as having money in it.
 *
 * ## The counterparty depends on the direction
 *
 * On a credit the counterparty is the debtor; on a debit it is the creditor.
 * A reader that always took `Cdtr` gets it right on half the lines and
 * silently wrong on the other half, where the "counterparty" of every
 * incoming payment is us.
 */
final class CamtParser implements StatementParser
{
    /** @param 'camt053'|'camt052'|'camt054' $format */
    public function __construct(private readonly string $format = 'camt053') {}

    public function format(): string
    {
        return $this->format;
    }

    public function recognises(string $content): bool
    {
        $head = substr($content, 0, 4096);

        return str_contains($head, '<?xml')
            && (str_contains($head, 'BkToCstmrStmt')
                || str_contains($head, 'BkToCstmrAcctRpt')
                || str_contains($head, 'BkToCstmrDbtCdtNtfctn')
                || str_contains($head, 'camt.05'));
    }

    /**
     * @return list<ParsedStatement>
     *
     * @throws StatementUnreadable
     */
    public function parse(string $content): array
    {
        try {
            $document = SafeXmlReader::parse($content);
        } catch (MalformedXml $e) {
            /*
             | The parser's line and column reach the caller.
             |
             | Somebody is holding a file their bank just gave them.
             | "Malformed XML" leaves them with a few thousand lines and no
             | idea which one.
             */
            throw StatementUnreadable::malformed(
                $this->format,
                $e->getMessage(),
                $e->atLine,
                $e->atColumn,
            );
        }

        $xpath = SafeXmlReader::selector($document);

        /*
         | All three roots, because one reader serves all three messages.
         |
         | A 052 imported as 053 is caught here rather than producing a
         | statement with no closing balance further down.
         */
        $containers = $xpath->query(
            '//*[local-name()="Stmt" or local-name()="Rpt" or local-name()="Ntfctn"]'
        );

        if ($containers === false || $containers->length === 0) {
            throw StatementUnreadable::notTheFormatItClaims(
                $this->format,
                'an XML document with no CAMT statement, report or notification in it',
            );
        }

        $statements = [];

        foreach ($containers as $container) {
            $statements[] = $this->statement($xpath, $container);
        }

        return $statements;
    }

    /**
     * @throws StatementUnreadable
     */
    private function statement(\DOMXPath $xpath, \DOMNode $container): ParsedStatement
    {
        $currency = $this->accountCurrency($xpath, $container);
        [$opening, $closing] = $this->balancesOf($xpath, $container, $currency);
        [$from, $to] = $this->periodOf($xpath, $container);

        $lines = [];

        foreach ($xpath->query('.//*[local-name()="Ntry"]', $container) ?: [] as $entry) {
            $lines[] = $this->entry($xpath, $entry, $currency);
        }

        return new ParsedStatement(
            sourceFormat: $this->format,
            accountIban: $this->first($xpath, './/*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]', $container),
            accountOtherId: $this->first($xpath, './/*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="Othr"]/*[local-name()="Id"]', $container),
            currency: $currency,
            periodFrom: $from,
            periodTo: $to,
            openingBalance: $opening,
            closingBalance: $closing,
            lines: $lines,
            statementNumber: $this->first($xpath, './*[local-name()="LglSeqNb"]', $container)
                ?? $this->first($xpath, './*[local-name()="ElctrncSeqNb"]', $container)
                ?? $this->first($xpath, './*[local-name()="Id"]', $container),
            institutionBic: $this->first(
                $xpath,
                './/*[local-name()="Acct"]/*[local-name()="Svcr"]//*[local-name()="BICFI" or local-name()="BIC"]',
                $container,
            ),
        );
    }

    /**
     * @throws StatementUnreadable
     */
    private function accountCurrency(\DOMXPath $xpath, \DOMNode $container): string
    {
        $currency = $this->first($xpath, './/*[local-name()="Acct"]/*[local-name()="Ccy"]', $container);

        if ($currency !== null) {
            return strtoupper($currency);
        }

        /*
         | Some banks omit `Acct/Ccy` and put it on the balance amounts.
         |
         | Legal, and a reader that insisted on the account-level element
         | would refuse them. The balance's own currency attribute is the
         | same figure's currency by definition.
         */
        $node = $xpath->query('.//*[local-name()="Bal"]/*[local-name()="Amt"]/@Ccy', $container);

        if ($node !== false && $node->length > 0) {
            return strtoupper(trim((string) $node->item(0)?->nodeValue));
        }

        throw StatementUnreadable::missing(
            $this->format,
            'an account currency (Acct/Ccy, or a Ccy attribute on a balance)',
            'what every figure in the file is denominated in',
        );
    }

    /**
     * The opening and closing booked balances, signed.
     *
     * @return array{0: string, 1: string}
     *
     * @throws StatementUnreadable
     */
    private function balancesOf(\DOMXPath $xpath, \DOMNode $container, string $currency): array
    {
        $balances = [];

        foreach ($xpath->query('./*[local-name()="Bal"]', $container) ?: [] as $balance) {
            $code = $this->first(
                $xpath,
                './*[local-name()="Tp"]/*[local-name()="CdOrPrtry"]/*[local-name()="Cd"]',
                $balance,
            ) ?? $this->first(
                $xpath,
                './*[local-name()="Tp"]/*[local-name()="CdOrPrtry"]/*[local-name()="Prtry"]',
                $balance,
            );

            if ($code === null) {
                continue;
            }

            $amount = $this->first($xpath, './*[local-name()="Amt"]', $balance);

            if ($amount === null) {
                continue;
            }

            /*
             | `DBIT` on a balance is an overdraft.
             |
             | Ignoring it reports an overdrawn account as having money in
             | it - and the figure is plausible, so nothing downstream
             | notices.
             */
            $indicator = $this->first($xpath, './*[local-name()="CdtDbtInd"]', $balance);
            $signed = BigDecimal::of($this->clean($amount));

            if (strtoupper((string) $indicator) === 'DBIT') {
                $signed = $signed->negated();
            }

            $balances[strtoupper($code)] = (string) $signed->toScale(4);
        }

        /*
         | Three spellings of the same opening figure.
         |
         | `OPBD` is the obvious one; `PRCD` (previously closed booked) is
         | what several banks send instead and is the same number by
         | definition; `ITBD` is what an intraday report carries. A reader
         | that knew only the first would refuse two banks in four.
         */
        $opening = $balances['OPBD'] ?? $balances['PRCD'] ?? $balances['ITBD'] ?? null;
        $closing = $balances['CLBD'] ?? $balances['ITBD'] ?? null;

        if ($opening === null) {
            throw StatementUnreadable::missing(
                $this->format,
                'an opening balance (OPBD, PRCD or ITBD)',
                'the figure the statement\'s own movement is measured from',
            );
        }

        if ($closing === null) {
            throw StatementUnreadable::missing(
                $this->format,
                'a closing balance (CLBD or ITBD)',
                'what the bank says the account held at the end of the period',
            );
        }

        return [$opening, $closing];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws StatementUnreadable
     */
    private function periodOf(\DOMXPath $xpath, \DOMNode $container): array
    {
        $from = $this->first($xpath, './*[local-name()="FrToDt"]/*[local-name()="FrDtTm"]', $container);
        $to = $this->first($xpath, './*[local-name()="FrToDt"]/*[local-name()="ToDtTm"]', $container);

        if ($from !== null && $to !== null) {
            return [Carbon::parse($from), Carbon::parse($to)];
        }

        /*
         | No `FrToDt`, which is permitted and common on an intraday report.
         |
         | The entries' own booking dates are the period: the first and the
         | last. Derived rather than refused, because a report that covers
         | today and says so only in its entries is still a report - and the
         | creation date alone would make every intraday period a single
         | instant.
         */
        $dates = [];

        foreach ($xpath->query('.//*[local-name()="Ntry"]', $container) ?: [] as $entry) {
            $booked = $this->dateOf($xpath, $entry, 'BookgDt') ?? $this->dateOf($xpath, $entry, 'ValDt');

            if ($booked !== null) {
                $dates[] = $booked;
            }
        }

        if ($dates !== []) {
            usort($dates, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

            return [reset($dates), end($dates)];
        }

        $created = $this->first($xpath, './/*[local-name()="CreDtTm"]');

        if ($created !== null) {
            $day = Carbon::parse($created);

            return [$day->copy()->startOfDay(), $day->copy()->startOfDay()];
        }

        throw StatementUnreadable::missing(
            $this->format,
            'a period (FrToDt), any entry with a date, or a creation timestamp',
            'what span of time the statement covers',
        );
    }

    private function entry(\DOMXPath $xpath, \DOMNode $entry, string $currency): ParsedLine
    {
        $amount = (string) $this->first($xpath, './*[local-name()="Amt"]', $entry);
        $indicator = strtoupper((string) $this->first($xpath, './*[local-name()="CdtDbtInd"]', $entry));

        $signed = BigDecimal::of($this->clean($amount));

        /*
         | `CdtDbtInd` is the real direction, and `RvslInd` does NOT flip it.
         |
         | The obvious reading of a reversal flag is "so invert the sign",
         | and it is wrong: the indicator already describes the booking that
         | actually happened. Inverting turns a reversed credit into a double
         | debit, and the account is then out by twice the entry with
         | nothing in the file to explain it. The flag is carried so a human
         | can see what it was; the arithmetic ignores it.
         */
        if ($indicator === 'DBIT') {
            $signed = $signed->negated();
        }

        $status = strtoupper((string) (
            $this->first($xpath, './*[local-name()="Sts"]/*[local-name()="Cd"]', $entry)
            ?? $this->first($xpath, './*[local-name()="Sts"]', $entry)
        ));

        $booking = $this->dateOf($xpath, $entry, 'BookgDt');
        $value = $this->dateOf($xpath, $entry, 'ValDt');

        $details = $this->detailsOf($xpath, $entry, $currency, $indicator);
        $isBatch = ($xpath->query('./*[local-name()="NtryDtls"]/*[local-name()="Btch"]', $entry)?->length ?? 0) > 0
            || \count($details) > 1;

        /*
         | A single detail is flattened onto the entry as well.
         |
         | Its remittance and counterparty are the entry's, and the
         | transaction's own columns are what every query and the identity
         | key read. A batch leaves them null, because a batch-level
         | "counterparty" would be a fiction.
         */
        $only = \count($details) === 1 && ! $isBatch ? $details[0] : null;

        return new ParsedLine(
            bookingDate: $booking ?? $value ?? Carbon::now(),
            valueDate: $value ?? $booking ?? Carbon::now(),
            amount: (string) $signed->toScale(4),
            currency: strtoupper((string) (
                $this->attribute($xpath, './*[local-name()="Amt"]/@Ccy', $entry) ?? $currency
            )),
            counterpartyName: $only?->counterpartyName,
            counterpartyAccount: $only?->counterpartyAccount,
            counterpartyBic: $only?->counterpartyBic,
            remittanceUnstructured: $only?->remittanceUnstructured
                ?? $this->first($xpath, './*[local-name()="AddtlNtryInf"]', $entry),
            remittanceStructured: $only?->remittanceStructured,
            bankReference: $this->first($xpath, './*[local-name()="AcctSvcrRef"]', $entry)
                ?? $this->first($xpath, './*[local-name()="NtryRef"]', $entry),
            endToEndId: $only?->endToEndId,
            mandateId: $only?->mandateId,
            codeDomain: $this->first($xpath, './*[local-name()="BkTxCd"]/*[local-name()="Domn"]/*[local-name()="Cd"]', $entry),
            codeFamily: $this->first($xpath, './*[local-name()="BkTxCd"]/*[local-name()="Domn"]/*[local-name()="Fmly"]/*[local-name()="Cd"]', $entry),
            codeSubfamily: $this->first($xpath, './*[local-name()="BkTxCd"]/*[local-name()="Domn"]/*[local-name()="Fmly"]/*[local-name()="SubFmlyCd"]', $entry),
            bankCodeProprietary: $this->first($xpath, './*[local-name()="BkTxCd"]/*[local-name()="Prtry"]/*[local-name()="Cd"]', $entry),
            isReversal: $this->isTrue($this->first($xpath, './*[local-name()="RvslInd"]', $entry)),
            reversesReference: $this->first($xpath, './/*[local-name()="RtrInf"]/*[local-name()="OrgnlBkTxCd"]/*[local-name()="Prtry"]/*[local-name()="Cd"]', $entry),
            chargesAmount: $this->chargesOf($xpath, $entry),
            instructedAmount: $only?->instructedAmount,
            instructedCurrency: $only?->instructedCurrency,
            exchangeRate: $only?->exchangeRate,
            /*
             | A pending entry is not in the closing balance.
             |
             | `PDNG` on a 053 is ordinary, and importing it as a booked line
             | makes the file disagree with its own stated movement - at
             | which point the import is refused for a reason that is not the
             | file's fault. An intraday message's entries are provisional
             | whatever their status says.
             */
            isPending: $status === 'PDNG' || $status === 'INFO' || $this->format !== 'camt053',
            isBatch: $isBatch,
            details: $details,
        );
    }

    /**
     * @return list<ParsedDetail>
     */
    private function detailsOf(
        \DOMXPath $xpath,
        \DOMNode $entry,
        string $currency,
        string $entryIndicator,
    ): array {
        $details = [];

        foreach ($xpath->query('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $entry) ?: [] as $node) {
            $amount = $this->first($xpath, './*[local-name()="Amt"]', $node)
                ?? $this->first($xpath, './*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"]', $node);

            if ($amount === null) {
                continue;
            }

            /*
             | A detail may run against its entry.
             |
             | A refund inside an outgoing run is a credit among debits. Its
             | own `CdtDbtInd` says so where the bank supplies one;
             | otherwise it follows the entry, which is the common case and
             | the right default.
             */
            $indicator = strtoupper((string) (
                $this->first($xpath, './*[local-name()="CdtDbtInd"]', $node) ?? $entryIndicator
            ));

            $signed = BigDecimal::of($this->clean($amount));

            if ($indicator === 'DBIT') {
                $signed = $signed->negated();
            }

            /*
             | On a credit the counterparty is the debtor; on a debit, the
             | creditor.
             |
             | A reader that always took `Cdtr` is right on half the lines
             | and silently wrong on the rest, where the "counterparty" of
             | every incoming payment comes out as us.
             */
            $party = $indicator === 'DBIT' ? 'Cdtr' : 'Dbtr';

            $details[] = new ParsedDetail(
                amount: (string) $signed->toScale(4),
                currency: strtoupper((string) (
                    $this->attribute($xpath, './*[local-name()="Amt"]/@Ccy', $node) ?? $currency
                )),
                counterpartyName: $this->first(
                    $xpath,
                    sprintf('./*[local-name()="RltdPties"]/*[local-name()="%s"]//*[local-name()="Nm"]', $party),
                    $node,
                ),
                counterpartyAccount: $this->first(
                    $xpath,
                    sprintf(
                        './*[local-name()="RltdPties"]/*[local-name()="%sAcct"]/*[local-name()="Id"]'
                        .'/*[local-name()="IBAN" or local-name()="Othr"]//text()[normalize-space()]',
                        $party,
                    ),
                    $node,
                ),
                counterpartyBic: $this->first(
                    $xpath,
                    sprintf(
                        './*[local-name()="RltdAgts"]/*[local-name()="%sAgt"]//*[local-name()="BICFI" or local-name()="BIC"]',
                        $party,
                    ),
                    $node,
                ),
                remittanceUnstructured: $this->joined($xpath, './*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $node),
                remittanceStructured: $this->structuredRemittance($xpath, $node),
                endToEndId: $this->first($xpath, './*[local-name()="Refs"]/*[local-name()="EndToEndId"]', $node),
                mandateId: $this->first($xpath, './*[local-name()="Refs"]/*[local-name()="MndtId"]', $node),
                instructionId: $this->first($xpath, './*[local-name()="Refs"]/*[local-name()="InstrId"]', $node),
                instructedAmount: $this->scaled($this->first(
                    $xpath,
                    './*[local-name()="AmtDtls"]/*[local-name()="InstdAmt"]/*[local-name()="Amt"]',
                    $node,
                )),
                instructedCurrency: $this->attribute(
                    $xpath,
                    './*[local-name()="AmtDtls"]/*[local-name()="InstdAmt"]/*[local-name()="Amt"]/@Ccy',
                    $node,
                ),
                exchangeRate: $this->first(
                    $xpath,
                    './*[local-name()="AmtDtls"]//*[local-name()="CcyXchg"]/*[local-name()="XchgRate"]',
                    $node,
                ),
                chargesAmount: $this->scaled($this->first(
                    $xpath,
                    './*[local-name()="Chrgs"]//*[local-name()="Amt"]',
                    $node,
                )),
            );
        }

        return $details;
    }

    /**
     * `RmtInf/Strd`, which is what makes matching pass 4 possible.
     *
     * The referenced document numbers and their amounts, plus the creditor
     * reference. This is the highest-quality signal a payer can supply and
     * the one most readers drop, because it takes more than reading a single
     * element.
     *
     * @return array<string, mixed>|null
     */
    private function structuredRemittance(\DOMXPath $xpath, \DOMNode $node): ?array
    {
        $structured = [];

        foreach ($xpath->query('./*[local-name()="RmtInf"]/*[local-name()="Strd"]', $node) ?: [] as $strd) {
            $entry = [];

            $reference = $this->first(
                $xpath,
                './*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]',
                $strd,
            );

            if ($reference !== null) {
                $entry['creditor_reference'] = $reference;
                $entry['creditor_reference_type'] = $this->first(
                    $xpath,
                    './*[local-name()="CdtrRefInf"]/*[local-name()="Tp"]//*[local-name()="Cd" or local-name()="Prtry"]',
                    $strd,
                );
            }

            $documents = [];

            foreach ($xpath->query('./*[local-name()="RfrdDocInf"]', $strd) ?: [] as $document) {
                $number = $this->first($xpath, './*[local-name()="Nb"]', $document);

                if ($number === null) {
                    continue;
                }

                $documents[] = array_filter([
                    'number' => $number,
                    'type' => $this->first(
                        $xpath,
                        './*[local-name()="Tp"]//*[local-name()="Cd" or local-name()="Prtry"]',
                        $document,
                    ),
                    'date' => $this->first($xpath, './*[local-name()="RltdDt"]', $document),
                ], static fn (?string $value): bool => $value !== null);
            }

            if ($documents !== []) {
                $entry['documents'] = $documents;
            }

            $due = $this->scaled($this->first(
                $xpath,
                './*[local-name()="RfrdDocAmt"]/*[local-name()="DuePyblAmt"]',
                $strd,
            ));

            if ($due !== null) {
                $entry['due_amount'] = $due;
            }

            $remitted = $this->scaled($this->first(
                $xpath,
                './*[local-name()="RfrdDocAmt"]/*[local-name()="RmtdAmt"]',
                $strd,
            ));

            if ($remitted !== null) {
                $entry['remitted_amount'] = $remitted;
            }

            $info = $this->joined($xpath, './*[local-name()="AddtlRmtInf"]', $strd);

            if ($info !== null) {
                $entry['additional'] = $info;
            }

            if ($entry !== []) {
                $structured[] = $entry;
            }
        }

        return $structured === [] ? null : ['referenced' => $structured];
    }

    private function chargesOf(\DOMXPath $xpath, \DOMNode $entry): ?string
    {
        $total = $this->first(
            $xpath,
            './*[local-name()="Chrgs"]/*[local-name()="TtlChrgsAndTaxAmt"]',
            $entry,
        );

        if ($total !== null) {
            return $this->scaled($total);
        }

        $sum = BigDecimal::zero();
        $found = false;

        foreach ($xpath->query('./*[local-name()="Chrgs"]//*[local-name()="Amt"]', $entry) ?: [] as $node) {
            $sum = $sum->plus(BigDecimal::of($this->clean((string) $node->nodeValue)));
            $found = true;
        }

        return $found ? (string) $sum->toScale(4) : null;
    }

    private function dateOf(\DOMXPath $xpath, \DOMNode $entry, string $element): ?Carbon
    {
        $value = $this->first($xpath, sprintf('./*[local-name()="%s"]/*[local-name()="Dt"]', $element), $entry)
            ?? $this->first($xpath, sprintf('./*[local-name()="%s"]/*[local-name()="DtTm"]', $element), $entry);

        return $value === null ? null : Carbon::parse($value);
    }

    private function first(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): ?string
    {
        $nodes = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->nodeValue);

        return $value === '' ? null : $value;
    }

    private function attribute(\DOMXPath $xpath, string $expression, \DOMNode $context): ?string
    {
        $value = $this->first($xpath, $expression, $context);

        return $value === null ? null : strtoupper($value);
    }

    /** Several `Ustrd` elements are one remittance, in order. */
    private function joined(\DOMXPath $xpath, string $expression, \DOMNode $context): ?string
    {
        $parts = [];

        foreach ($xpath->query($expression, $context) ?: [] as $node) {
            $value = trim((string) $node->nodeValue);

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function scaled(?string $value): ?string
    {
        return $value === null ? null : (string) BigDecimal::of($this->clean($value))->toScale(4);
    }

    /**
     * An amount as written, with the spacing some banks add.
     *
     * Not a thousands separator: ISO 20022 amounts use a point and no
     * grouping, so anything else here is whitespace a serialiser left in.
     */
    private function clean(string $value): string
    {
        return (string) preg_replace('/\s+/', '', trim($value));
    }

    private function isTrue(?string $value): bool
    {
        return \in_array(strtolower((string) $value), ['true', '1'], true);
    }
}
