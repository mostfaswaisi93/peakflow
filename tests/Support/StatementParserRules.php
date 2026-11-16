<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Banking\Exceptions\StatementUnreadable;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Parsers\CamtParser;
use App\Tenant\Banking\Parsers\CsvDialect;
use App\Tenant\Banking\Parsers\CsvSniffer;
use App\Tenant\Banking\Parsers\CsvStatementReader;
use App\Tenant\Banking\Parsers\Mt940Parser;
use App\Tenant\Banking\Parsers\OfxParser;
use App\Tenant\Banking\Parsers\ParsedStatement;
use App\Tenant\Banking\Parsers\StatementParsers;
use App\Tenant\Banking\Services\BankBalances;
use App\Tenant\Banking\Services\StatementImport;
use App\Tenant\Banking\Services\TransactionRouting;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Reading what a bank actually sends - PF-150 to PF-155.
 *
 * ## What the fixtures are for
 *
 * "ISO 20022" sounds like it settles the question. It does not, and the four
 * CAMT shapes here are four genuinely different banks: balance codes under
 * three different names, dates in two forms, transaction codes that are
 * sometimes proprietary only, a prefixed namespace, a batch entry, a
 * reversal, an overdraft and an FX payment. A reader tested against one
 * vendor's sample passes and then refuses half the banks it meets.
 *
 * ## The two things that silently corrupt an import
 *
 * **A sign.** `CdtDbtInd` and MT940's `C`/`D` give direction separately from
 * magnitude, and a reversal flag looks like it ought to invert it. It does
 * not. Getting that wrong turns a reversed credit into a double debit, and
 * the figures stay plausible.
 *
 * **A decimal separator.** `1.234` is a thousand in Frankfurt and one in
 * London. A detector that guesses silently imports a year off by a factor of
 * a thousand with every row looking right, which is why detection proposes
 * and a preview confirms.
 */
final class StatementParserRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    private const FIXTURES = __DIR__.'/../Fixtures/banking/';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['statement parsers (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            return $this->runEachInASavepoint([
                // ---- CAMT, across four bank shapes - PF-150 -------------
                'every shipped CAMT shape parses and ties' => fn (): array => $this->everyShapeTies(),
                'the textbook shape reads every field' => fn (): array => $this->shapeA(),
                'an older bank, PRCD and an overdraft' => fn (): array => $this->shapeB(),
                'a batch entry keeps its underlying transactions' => fn (): array => $this->shapeC(),
                'a prefixed namespace and absent optionals' => fn (): array => $this->shapeD(),
                'a reversal does not flip the sign' => fn (): array => $this->reversalKeepsItsSign(),
                'the counterparty follows the direction' => fn (): array => $this->counterpartyDirection(),
                'structured remittance is kept, not dropped' => fn (): array => $this->structuredRemittance(),
                'a pending entry is not in the movement' => fn (): array => $this->pendingIsNotBooked(),
                'charges and FX amounts survive' => fn (): array => $this->chargesAndFx(),

                // ---- Failing safely - PF-150 ---------------------------
                'a malformed file names its line' => fn (): array => $this->malformedNamesItsLine(),
                'a DOCTYPE is refused outright' => fn (): array => $this->doctypeIsRefused(),
                'an external entity cannot be expanded' => fn (): array => $this->xxeIsRefused(),
                'a file with no balances says which is missing' => fn (): array => $this->missingBalance(),
                'a file named as the wrong format is refused' => fn (): array => $this->wrongFormatIsRefused(),
                'and an intraday file is not read as booked' => fn (): array => $this->intradayNamedAsBooked(),

                // ---- MT940 and MT942 - PF-152 --------------------------
                'MT940 parses and ties' => fn (): array => $this->mt940(),
                'both :86: layouts are read' => fn (): array => $this->mt940Information(),
                'an unparseable :86: is kept verbatim' => fn (): array => $this->mt940KeepsVerbatim(),
                'an MT940 overdraft is read as negative' => fn (): array => $this->mt940Overdrawn(),
                'MT942 states no balances and is provisional' => fn (): array => $this->mt942(),
                'and the two are told apart by their balances' => fn (): array => $this->mtAreDistinguished(),

                // ---- OFX - PF-153 --------------------------------------
                'OFX reads in both its eras' => fn (): array => $this->ofxBothEras(),
                'and maps its types into the ISO code space' => fn (): array => $this->ofxIsoCodes(),

                // ---- CSV - PF-154 --------------------------------------
                'a European CSV is detected correctly' => fn (): array => $this->csvEuropean(),
                'a signed CSV is detected correctly' => fn (): array => $this->csvSigned(),
                'an ambiguous decimal separator is reported' => fn (): array => $this->csvAmbiguous(),
                'the preview shows rows as they will be read' => fn (): array => $this->csvPreview(),
                'a row that will not read stops the import' => fn (): array => $this->csvBadRow(),
                'a file that is not text is refused clearly' => fn (): array => $this->csvNotText(),
                'both a debit and a credit on one row is refused' => fn (): array => $this->csvBothColumns(),

                // ---- Importing a file - PF-150, PF-154 -----------------
                'a file is imported into the account it names' => fn (): array => $this->importsByFile(),
                'and refused for an account it does not' => fn (): array => $this->wrongAccountIsRefused(),
                'a statement in another currency is refused' => fn (): array => $this->wrongCurrencyIsRefused(),
                'a zero advice line is skipped and reported' => fn (): array => $this->zeroLineIsReported(),
                'batch details reach the database' => fn (): array => $this->detailsAreStored(),
                'and are as immutable as the entry' => fn (): array => $this->detailsAreImmutable(),
                'three statements in a row still reconcile' => fn (): array => $this->threeInARow(),
                'an intraday file does not break the chain' => fn (): array => $this->intradayDoesNotBreakTheChain(),

                // ---- Routing - PF-155 ----------------------------------
                'a code picks the matching strategy' => fn (): array => $this->routingPicksAStrategy(),
                'the most specific code wins' => fn (): array => $this->routingPrefersTheSpecific(),
                'an unmapped code falls through' => fn (): array => $this->routingFallsThrough(),
                'and the routing is data, not code' => fn (): array => $this->routingIsData(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- CAMT -------------------------------------------------------------

    /** @return list<string> */
    private function everyShapeTies(): array
    {
        $failures = [];

        foreach (['a', 'b', 'c', 'd'] as $shape) {
            $statements = $this->camt('camt053-shape-'.$shape.'.xml');

            if ($statements === []) {
                $failures[] = sprintf('shape %s produced no statement', $shape);

                continue;
            }

            foreach ($statements as $statement) {
                $movement = $statement->movement();

                if ($movement === null) {
                    $failures[] = sprintf('shape %s states no balances', $shape);

                    continue;
                }

                if (! $statement->bookedTotal()->amount->isEqualTo($movement->amount)) {
                    $failures[] = sprintf(
                        'shape %s: its booked lines total %s against a stated movement of %s, so '.
                        'something was lost or double-counted in the parse',
                        $shape,
                        (string) $statement->bookedTotal()->amount,
                        (string) $movement->amount,
                    );
                }
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function shapeA(): array
    {
        $statement = $this->camt('camt053-shape-a.xml')[0];
        $line = $statement->lines[0];

        return $this->expect([
            'the IBAN' => [$statement->accountIban, 'SA0380000000608010167519'],
            'the currency' => [$statement->currency, 'SAR'],
            'the opening balance' => [$statement->openingBalance, '50000.0000'],
            'the closing balance' => [$statement->closingBalance, '51500.0000'],
            'the bank\'s statement number' => [$statement->statementNumber, '41'],
            'the period start' => [$statement->periodFrom->toDateString(), '2026-05-01'],
            'the bank reference' => [$line->bankReference, 'BR-ALPHA-001'],
            'the end-to-end id' => [$line->endToEndId, 'E2E-INV-1001'],
            'the ISO domain' => [$line->codeDomain, 'PMNT'],
            'the ISO family' => [$line->codeFamily, 'RCDT'],
            'the ISO sub-family' => [$line->codeSubfamily, 'ESCT'],
            'the proprietary code' => [$line->bankCodeProprietary, 'RJ-INC-01'],
            'the counterparty BIC' => [$line->counterpartyBic, 'NCBKSAJE'],
        ]);
    }

    /** @return list<string> */
    private function shapeB(): array
    {
        $statement = $this->camt('camt053-shape-b.xml')[0];

        $failures = $this->expect([
            'PRCD as the opening balance' => [$statement->openingBalance, '1200.0000'],
            /*
             | A DBIT closing balance is an overdraft.
             |
             | Ignoring `Bal/CdtDbtInd` reports an overdrawn account as
             | having money in it, and the figure is plausible so nothing
             | downstream notices.
             */
            'a DBIT balance read as negative' => [$statement->closingBalance, '-300.0000'],
            'the domestic account id' => [$statement->accountOtherId, '0108-123456-001'],
            'no IBAN where the bank gave none' => [$statement->accountIban, null],
            'a proprietary-only code' => [$statement->lines[0]->bankCodeProprietary, 'TRF-OUT'],
            'no ISO domain where none was sent' => [$statement->lines[0]->codeDomain, null],
        ]);

        /*
         | The period came from the entries, because there is no FrToDt.
         |
         | Permitted and common. Deriving it is what keeps a bank that omits
         | it usable; the alternative is a refusal nobody can act on.
         */
        if ($statement->periodFrom->toDateString() !== '2026-05-02') {
            $failures[] = sprintf(
                'the period was not derived from the entries: it starts %s',
                $statement->periodFrom->toDateString(),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function shapeC(): array
    {
        $statement = $this->camt('camt053-shape-c.xml')[0];
        $batch = $statement->lines[0];

        $failures = $this->expect([
            'the batch entry amount' => [$batch->amount, '-8000.0000'],
            'the batch flag' => [$batch->isBatch, true],
            'four underlying transactions' => [\count($batch->details), 4],
            'no counterparty on a batch' => [$batch->counterpartyName, null],
        ]);

        /*
         | The details sum to the entry.
         |
         | The bank took one amount; the details explain what it was made
         | of. A reader that dropped one, or that read the refund as another
         | payment, breaks this - and nothing else would notice, because the
         | entry is what reconciles.
         */
        $sum = BigDecimal::zero();

        foreach ($batch->details as $detail) {
            $sum = $sum->plus($detail->amount);
        }

        if (! $sum->isEqualTo($batch->amount)) {
            $failures[] = sprintf(
                'the four underlying transactions total %s against an entry of %s',
                (string) $sum,
                $batch->amount,
            );
        }

        /*
         | A refund inside an outgoing run is a credit among debits.
         |
         | Its own CdtDbtInd says so. A reader that applied the entry's
         | direction to every detail would report the refund as a fourth
         | payment and the sum would be out by twice it.
         */
        $credits = array_filter(
            $batch->details,
            static fn (object $detail): bool => BigDecimal::of($detail->amount)->isPositive(),
        );

        if (\count($credits) !== 1) {
            $failures[] = sprintf(
                '%d of the four underlying transactions are credits; the refund inside the run '.
                'should be the only one',
                \count($credits),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function shapeD(): array
    {
        $statement = $this->camt('camt053-shape-d.xml')[0];

        $failures = $this->expect([
            'a prefixed namespace' => [$statement->accountIban, 'SA0380000000608010167519'],
            'the currency from a balance attribute' => [$statement->currency, 'SAR'],
            'charges summed from separate records' => [$statement->lines[0]->chargesAmount, '65.0000'],
        ]);

        /*
         | Several Ustrd elements are one sentence.
         |
         | Taking only the first keeps a third of the remittance, and the
         | invoice numbers are usually in the part that is dropped.
         */
        $joined = $statement->lines[2]->remittanceUnstructured;

        foreach (['INV-1021', 'INV-1022', 'INV-1023', 'agreed rebate'] as $fragment) {
            if (! str_contains((string) $joined, $fragment)) {
                $failures[] = sprintf('the joined remittance lost "%s": %s', $fragment, (string) $joined);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function reversalKeepsItsSign(): array
    {
        $statement = $this->camt('camt053-shape-c.xml')[0];
        $reversal = $statement->lines[1];

        $failures = $this->expect([
            'the reversal flag' => [$reversal->isReversal, true],
            /*
             | The sign is NOT flipped.
             |
             | The original was a 1,200 credit that bounced, so the booking
             | that happened is a 1,200 debit and CdtDbtInd says so.
             | "RvslInd is true, so invert" is the obvious reading and it is
             | wrong: it turns a reversed credit into a double debit, and
             | the account is out by twice the entry with nothing in the
             | file to explain it.
             */
            'the sign the bank actually booked' => [$reversal->amount, '-1200.0000'],
            'what it reverses' => [$reversal->reversesReference, 'ANB-TX-8844'],
        ]);

        /*
         | And the statement still ties.
         |
         | Which is the real proof: a flipped reversal would make the booked
         | lines miss the stated movement by 2,400, and that is the number
         | somebody would spend a day on.
         */
        $movement = $statement->movement();

        if ($movement !== null && ! $statement->bookedTotal()->amount->isEqualTo($movement->amount)) {
            $failures[] = 'the statement containing a reversal no longer ties to its own movement';
        }

        return $failures;
    }

    /** @return list<string> */
    private function counterpartyDirection(): array
    {
        $statement = $this->camt('camt053-shape-a.xml')[0];

        return $this->expect([
            /*
             | On a credit the counterparty is the debtor; on a debit, the
             | creditor. A reader that always took Cdtr is right on half the
             | lines and silently wrong on the rest, where the
             | "counterparty" of every incoming payment comes out as us.
             */
            'the debtor on an incoming payment' => [$statement->lines[0]->counterpartyName, 'Gulf Retail LLC'],
            'the creditor on an outgoing one' => [$statement->lines[3]->counterpartyName, 'Riyadh Utilities'],
            'the debtor\'s account on a credit' => [
                $statement->lines[0]->counterpartyAccount,
                'SA4420000001234567891234',
            ],
            'the creditor\'s account on a debit' => [$statement->lines[3]->counterpartyAccount, 'UTIL-99001'],
        ]);
    }

    /** @return list<string> */
    private function structuredRemittance(): array
    {
        $structured = $this->camt('camt053-shape-a.xml')[0]->lines[0]->remittanceStructured;

        if ($structured === null) {
            return [
                'RmtInf/Strd was dropped. It is the highest-quality signal a payer can supply and '.
                'the one most readers lose, because reading it takes more than one element - '.
                'without it matching pass 4 has nothing to work with',
            ];
        }

        $referenced = (array) ($structured['referenced'][0] ?? []);

        return $this->expect([
            'the creditor reference' => [$referenced['creditor_reference'] ?? null, 'RF18INV1001'],
            'its scheme' => [$referenced['creditor_reference_type'] ?? null, 'SCOR'],
            'the referenced document number' => [$referenced['documents'][0]['number'] ?? null, 'INV-1001'],
            'its type' => [$referenced['documents'][0]['type'] ?? null, 'CINV'],
            'the amount due on it' => [$referenced['due_amount'] ?? null, '1000.0000'],
        ]);
    }

    /** @return list<string> */
    private function pendingIsNotBooked(): array
    {
        $statement = $this->camt('camt053-shape-a.xml')[0];

        $failures = $this->expect([
            'one pending entry' => [\count($statement->pendingLines()), 1],
            'four booked entries' => [\count($statement->bookedLines()), 4],
        ]);

        /*
         | A pending entry is not in the closing balance.
         |
         | Counting it would make a perfectly correct file disagree with its
         | own stated movement by 9,999 - and the import would then be
         | refused for a reason that is not the file's fault, which reads as
         | a parser bug.
         */
        $movement = $statement->movement();

        if ($movement !== null && ! $statement->bookedTotal()->amount->isEqualTo($movement->amount)) {
            $failures[] = 'the pending entry is being counted toward the statement\'s movement';
        }

        return $failures;
    }

    /** @return list<string> */
    private function chargesAndFx(): array
    {
        $fx = $this->camt('camt053-shape-c.xml')[0]->lines[2];
        $detail = $fx->details[0] ?? null;

        return $this->expect([
            'the charge on an entry' => [$this->camt('camt053-shape-a.xml')[0]->lines[3]->chargesAmount, '2.5000'],
            'the instructed amount' => [$detail?->instructedAmount, '500.0000'],
            'its currency' => [$detail?->instructedCurrency, 'EUR'],
            'the exchange rate' => [$detail?->exchangeRate, '4.000000'],
            'the amount actually booked' => [$fx->amount, '-2000.0000'],
        ]);
    }

    // ---- Failing safely ---------------------------------------------------

    /** @return list<string> */
    private function malformedNamesItsLine(): array
    {
        $broken = "<?xml version=\"1.0\"?>\n<Document>\n  <BkToCstmrStmt>\n    <Stmt>\n  </BkToCstmrStmt>\n</Document>\n";

        $refusal = $this->readingRefusal(fn () => (new CamtParser())->parse($broken));

        if ($refusal === null) {
            return ['a document with an unclosed element was parsed'];
        }

        $failures = [];

        if (! str_contains($refusal, 'line')) {
            $failures[] = sprintf('the refusal gives no position: %s', $refusal);
        }

        /*
         | And it warns that the position is approximate.
         |
         | The parser gives up at the next tag that does not match, which is
         | usually a line or two after the real problem - somebody who takes
         | the number literally edits the wrong line.
         */
        if (! str_contains($refusal, 'after the real problem')) {
            $failures[] = 'the refusal does not say the position is where the parser gave up';
        }

        return $failures;
    }

    /** @return list<string> */
    private function doctypeIsRefused(): array
    {
        $withDoctype = "<?xml version=\"1.0\"?>\n<!DOCTYPE Document>\n<Document><BkToCstmrStmt><Stmt/></BkToCstmrStmt></Document>";

        $refusal = $this->readingRefusal(fn () => (new CamtParser())->parse($withDoctype));

        return $refusal === null
            ? [
                'a statement declaring a DOCTYPE was parsed. A DOCTYPE in a received file has no '.
                'legitimate purpose, and "billion laughs" is a DOCTYPE with no external reference '.
                'at all - so entity limits do not stop it and refusing the declaration does',
            ]
            : [];
    }

    /** @return list<string> */
    private function xxeIsRefused(): array
    {
        $xxe = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE Document [<!ENTITY secret SYSTEM "file:///etc/passwd">]>
            <Document><BkToCstmrStmt><Stmt><Id>&secret;</Id></Stmt></BkToCstmrStmt></Document>
            XML;

        $refusal = $this->readingRefusal(fn () => (new CamtParser())->parse($xxe));

        if ($refusal === null) {
            return ['a statement declaring an external entity was parsed'];
        }

        return str_contains($refusal, 'passwd') || str_contains($refusal, 'root:')
            ? ['the refusal itself carries the contents of the file the entity pointed at']
            : [];
    }

    /** @return list<string> */
    private function missingBalance(): array
    {
        $noClosing = str_replace('CLBD', 'XXXX', (string) file_get_contents(self::FIXTURES.'camt053-shape-a.xml'));

        $refusal = $this->readingRefusal(fn () => (new CamtParser())->parse($noClosing));

        if ($refusal === null) {
            return ['a statement with no closing balance was parsed'];
        }

        $failures = [];

        if (! str_contains($refusal, 'CLBD')) {
            $failures[] = sprintf('the refusal does not name the element it wanted: %s', $refusal);
        }

        /*
         | And it says why guessing is not an option.
         |
         | A closing balance cannot be inferred from the rest of the file -
         | the lines sum to a movement, not to a position - and somebody who
         | does not know that will ask for it to be derived.
         */
        if (! str_contains($refusal, 'guessing it would produce')) {
            $failures[] = 'the refusal does not say why the figure cannot be inferred';
        }

        return $failures;
    }

    /** @return list<string> */
    private function wrongFormatIsRefused(): array
    {
        $mt940 = (string) file_get_contents(self::FIXTURES.'mt940-shape-a.txt');

        $refusal = $this->readingRefusal(
            fn () => (new StatementParsers())->parse($mt940, 'camt053'),
        );

        if ($refusal === null) {
            return ['an MT940 was accepted as a CAMT.053'];
        }

        return str_contains($refusal, 'MT940')
            ? []
            : [sprintf('the refusal does not say what the file actually is: %s', $refusal)];
    }

    /** @return list<string> */
    private function intradayNamedAsBooked(): array
    {
        $intraday = str_replace(
            'BkToCstmrStmt',
            'BkToCstmrAcctRpt',
            (string) file_get_contents(self::FIXTURES.'camt053-shape-a.xml'),
        );
        $intraday = str_replace('<Stmt>', '<Rpt>', str_replace('</Stmt>', '</Rpt>', $intraday));

        $refusal = $this->readingRefusal(
            fn () => (new StatementParsers())->parse($intraday, 'camt053'),
        );

        if ($refusal === null) {
            return [
                'a CAMT.052 was imported as a CAMT.053. It is provisional - the same movements the '.
                'booked statement will carry - so treating it as final double-counts every line '.
                'when the real statement arrives',
            ];
        }

        return str_contains($refusal, 'provisional')
            ? []
            : [sprintf('the refusal does not say what makes the difference matter: %s', $refusal)];
    }

    // ---- MT940 ------------------------------------------------------------

    /** @return list<string> */
    private function mt940(): array
    {
        $statement = (new Mt940Parser())->parse($this->fixture('mt940-shape-a.txt'))[0];
        $movement = $statement->movement();

        $failures = $this->expect([
            'the account' => [$statement->accountIban, 'SA0380000000608010167519'],
            'the currency from the balance tag' => [$statement->currency, 'SAR'],
            'the opening balance' => [$statement->openingBalance, '50000.0000'],
            'the closing balance' => [$statement->closingBalance, '51500.0000'],
            'the statement number' => [$statement->statementNumber, '00041/001'],
            'four lines' => [\count($statement->lines), 4],
            'a comma decimal separator' => [$statement->lines[0]->amount, '1000.0000'],
            'a D mark read as negative' => [$statement->lines[3]->amount, '-500.0000'],
            'the bank reference after the //' => [$statement->lines[0]->bankReference, 'RJHI0001'],
        ]);

        if ($movement !== null && ! $statement->bookedTotal()->amount->isEqualTo($movement->amount)) {
            $failures[] = 'the MT940 lines do not add up to its own stated movement';
        }

        return $failures;
    }

    /** @return list<string> */
    private function mt940Information(): array
    {
        $lines = (new Mt940Parser())->parse($this->fixture('mt940-shape-a.txt'))[0]->lines;

        return $this->expect([
            // The German numbered form: ?20?remittance ?31?account ?32?name
            'remittance from a numbered sub-field' => [$lines[0]->remittanceUnstructured, 'Payment for INV-1001'],
            'the name from a numbered sub-field' => [$lines[0]->counterpartyName, 'Gulf Retail LLC'],
            'the account from a numbered sub-field' => [
                $lines[0]->counterpartyAccount,
                'SA4420000001234567891234',
            ],
            // SWIFT's tagged form: /EREF/ /ORDP/ /REMI/
            'the end-to-end id from a tagged segment' => [$lines[1]->endToEndId, 'E2E-INV-1002'],
            'the name from a tagged segment' => [$lines[1]->counterpartyName, 'Gulf Retail LLC'],
            'remittance from a tagged segment' => [$lines[1]->remittanceUnstructured, 'INV-1002'],
        ]);
    }

    /** @return list<string> */
    private function mt940KeepsVerbatim(): array
    {
        $line = (new Mt940Parser())->parse($this->fixture('mt940-shape-a.txt'))[0]->lines[3];

        /*
         | Neither layout fits this one, so the text is kept as it arrived.
         |
         | Dropping it would be the easy choice and the worst one: `:86:` is
         | the only place a reference appears in this format, and a human
         | reading the line can still see what the bank wrote.
         */
        return str_contains((string) $line->remittanceUnstructured, 'Riyadh Utilities')
            && str_contains((string) $line->remittanceUnstructured, 'UTIL-99001')
            ? []
            : [sprintf(
                'an unparseable :86: was not kept verbatim - it is the only place a reference '.
                'appears in MT940, so dropping it leaves the line unmatchable: "%s"',
                (string) $line->remittanceUnstructured,
            )];
    }

    /**
     * An MT940 whose closing balance carries a `D` mark.
     *
     * The same rule as CAMT's `Bal/CdtDbtInd`, and the same consequence if
     * it is ignored: an overdrawn account reported as having money in it.
     *
     * The shipped fixture had `C` on both balances, so the negation was
     * never exercised - removing it changed nothing and the check stayed
     * green. Found by biting it.
     *
     * @return list<string>
     */
    private function mt940Overdrawn(): array
    {
        $statement = (new Mt940Parser())->parse($this->fixture('mt940-overdrawn.txt'))[0];
        $movement = $statement->movement();

        $failures = $this->expect([
            'a C opening balance' => [$statement->openingBalance, '1200.0000'],
            'a D closing balance read as an overdraft' => [$statement->closingBalance, '-300.0000'],
        ]);

        if ($movement !== null && ! $statement->bookedTotal()->amount->isEqualTo($movement->amount)) {
            $failures[] = sprintf(
                'the overdrawn statement does not tie: its lines total %s against a movement of %s',
                (string) $statement->bookedTotal()->amount,
                (string) $movement->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function mt942(): array
    {
        $statement = (new Mt940Parser('mt942'))->parse($this->fixture('mt942-interim.txt'))[0];

        return $this->expect([
            'no balances stated' => [$statement->statesItsBalances(), false],
            'the currency from :34F:' => [$statement->currency, 'SAR'],
            'every line provisional' => [\count($statement->pendingLines()), 2],
            'no booked lines' => [\count($statement->bookedLines()), 0],
            'the period derived from the entries' => [$statement->periodFrom->toDateString(), '2026-05-11'],
        ]);
    }

    /** @return list<string> */
    private function mtAreDistinguished(): array
    {
        $registry = new StatementParsers();

        $failures = [];

        $statement = $registry->sniff($this->fixture('mt940-shape-a.txt'));

        if ($statement->format() !== 'mt940') {
            $failures[] = sprintf('an MT940 was sniffed as %s', $statement->format());
        }

        /*
         | An MT942 has no balance tags and carries :34F: instead.
         |
         | The two otherwise look identical, and the difference decides
         | whether the file posts. A sniffer that could not tell them apart
         | would import an interim report as a booked statement, which
         | double-counts every line when the real one arrives.
         */
        $interim = $registry->sniff($this->fixture('mt942-interim.txt'));

        if ($interim->format() !== 'mt942') {
            $failures[] = sprintf(
                'an MT942 was sniffed as %s, so an interim report would be imported as booked',
                $interim->format(),
            );
        }

        return $failures;
    }

    // ---- OFX --------------------------------------------------------------

    /** @return list<string> */
    private function ofxBothEras(): array
    {
        $failures = [];

        foreach (['ofx-sgml-v1.ofx' => 3, 'ofx-xml-v2.ofx' => 2] as $file => $expected) {
            $statement = (new OfxParser())->parse($this->fixture($file))[0];

            if (\count($statement->lines) !== $expected) {
                $failures[] = sprintf(
                    '%s produced %d lines rather than %d - the SGML era leaves every value tag '.
                    'unclosed, and a reader that handles only the XML era refuses half the files '.
                    'people actually have',
                    $file,
                    \count($statement->lines),
                    $expected,
                );
            }

            $movement = $statement->movement();

            if ($movement !== null && ! $statement->bookedTotal()->amount->isEqualTo($movement->amount)) {
                $failures[] = sprintf('%s does not tie to its own derived movement', $file);
            }
        }

        $sgml = (new OfxParser())->parse($this->fixture('ofx-sgml-v1.ofx'))[0];

        return [...$failures, ...$this->expect([
            'the currency' => [$sgml->currency, 'USD'],
            'the account id' => [$sgml->accountOtherId, '123456789'],
            'a signed amount' => [$sgml->lines[1]->amount, '-500.0000'],
            'the opening balance, derived' => [$sgml->openingBalance, '50000.0000'],
            /*
             | OFX has no statement number and the first FITID is not one.
             |
             | Using it would label the statement "FIT-0001" on every
             | screen, which reads as the bank's own numbering and is not.
             */
            'no invented statement number' => [$sgml->statementNumber, null],
        ])];
    }

    /** @return list<string> */
    private function ofxIsoCodes(): array
    {
        $sgml = (new OfxParser())->parse($this->fixture('ofx-sgml-v1.ofx'))[0];
        $xml = (new OfxParser())->parse($this->fixture('ofx-xml-v2.ofx'))[0];

        return $this->expect([
            'CREDIT as an incoming transfer' => [$sgml->lines[0]->codeFamily, 'RCDT'],
            'DEBIT as an outgoing one' => [$sgml->lines[1]->codeFamily, 'ICDT'],
            'SRVCHG as a charge' => [$sgml->lines[2]->codeSubfamily, 'CHRG'],
            'INT as interest' => [$xml->lines[1]->codeSubfamily, 'INTR'],
            'the original type kept too' => [$sgml->lines[2]->bankCodeProprietary, 'SRVCHG'],
        ]);
    }

    // ---- CSV --------------------------------------------------------------

    /** @return list<string> */
    private function csvEuropean(): array
    {
        $sniffed = (new CsvSniffer())->sniff($this->fixture('csv-european.csv'));
        $dialect = $sniffed['dialect'];

        return $this->expect([
            'a semicolon delimiter' => [$dialect->delimiter, ';'],
            /*
             | The one that matters.
             |
             | `1.234` is a thousand in Frankfurt and one in London, and a
             | wrong answer here is off by a factor of a thousand with every
             | row still looking plausible.
             */
            'a comma decimal separator' => [$dialect->decimalSeparator, ','],
            'a point for grouping' => [$dialect->thousandsSeparator, '.'],
            'day-first dates' => [$dialect->dateFormat, 'd.m.Y'],
            'separate debit and credit columns' => [
                $dialect->signConvention,
                CsvDialect::SIGN_DEBIT_CREDIT_COLUMNS,
            ],
            'a header row' => [$dialect->hasHeader, true],
        ]);
    }

    /** @return list<string> */
    private function csvSigned(): array
    {
        $sniffed = (new CsvSniffer())->sniff($this->fixture('csv-uk-signed.csv'));

        $failures = $this->expect([
            'a comma delimiter' => [$sniffed['dialect']->delimiter, ','],
            'a point decimal separator' => [$sniffed['dialect']->decimalSeparator, '.'],
            'ISO dates' => [$sniffed['dialect']->dateFormat, 'Y-m-d'],
            'signed amounts' => [$sniffed['dialect']->signConvention, CsvDialect::SIGN_SIGNED],
        ]);

        /*
         | And a quoted comma does not split the row.
         |
         | "Payment for INV-1001, thanks" is one field. Splitting on every
         | comma gives this file four fields on two rows and five on the
         | third, which shifts the amount column by one on exactly the rows
         | whose description happens to contain a comma - so most of the
         | file imports correctly and a few rows import the balance as the
         | amount.
         */
        $statement = (new CsvStatementReader())->read(
            $this->fixture('csv-uk-signed.csv'),
            $sniffed['dialect'],
            ['booking_date' => 0, 'remittance' => 1, 'amount' => 2],
            'SAR',
        );

        $failures = [...$failures, ...$this->expect([
            'three lines' => [\count($statement->lines), 3],
            'a quoted comma kept inside its field' => [
                $statement->lines[0]->remittanceUnstructured,
                'Payment for INV-1001, thanks',
            ],
            'the amount column still in place' => [$statement->lines[0]->amount, '1000.0000'],
        ])];

        return $failures;
    }

    /** @return list<string> */
    private function csvAmbiguous(): array
    {
        $ambiguous = "Date,Description,Amount\n2026-05-01,One,1.234\n2026-05-02,Two,2.500\n";

        $sniffed = (new CsvSniffer())->sniff($ambiguous);

        $warned = implode(' ', $sniffed['warnings']);

        return str_contains($warned, 'factor of a thousand')
            ? []
            : [
                'a file whose amounts read correctly either way was decided silently. "1.234" is '.
                'one thousand two hundred and thirty four or one and a bit, nothing in the file '.
                'settles it, and a guess is off by a factor of a thousand with every row still '.
                'looking right',
            ];
    }

    /** @return list<string> */
    private function csvPreview(): array
    {
        $content = $this->fixture('csv-european.csv');
        $sniffed = (new CsvSniffer())->sniff($content);

        $preview = (new CsvStatementReader())->preview(
            $content,
            $sniffed['dialect'],
            $this->europeanMap(),
            'SAR',
        );

        $failures = [];

        if ($preview['problems'] !== []) {
            $failures[] = sprintf('the preview reported %d problems on a good file', \count($preview['problems']));
        }

        $first = $preview['rows'][0] ?? null;

        if ($first === null) {
            return [...$failures, 'the preview produced no rows'];
        }

        /*
         | The raw cells AND the interpretation, side by side.
         |
         | "01.05.2026 -> 2026-05-01" is checkable at a glance, and
         | "1.000,00 -> 1000.0000" is the line where somebody sees that
         | their thousands are being read as decimals. A preview of the raw
         | file proves only that the file was uploaded.
         */
        if (! \array_key_exists('raw', $first) || ! \array_key_exists('as_read', $first)) {
            $failures[] = 'the preview does not pair the raw cells with how they were read';
        }

        return [...$failures, ...$this->expect([
            'the date as it will be read' => [$first['as_read']['booking_date'] ?? null, '2026-05-01'],
            'the amount as it will be read' => [$first['as_read']['amount'] ?? null, '1000.0000'],
            'a debit column read as negative' => [
                $preview['rows'][3]['as_read']['amount'] ?? null,
                '-500.0000',
            ],
            'the header row' => [$preview['header'][0] ?? null, 'Buchungstag'],
        ])];
    }

    /** @return list<string> */
    private function csvBadRow(): array
    {
        $broken = "Date;Description;Debit;Credit\n01.05.2026;Good;;1.000,00\nNOT-A-DATE;Bad;;500,00\n";

        $refusal = $this->readingRefusal(fn () => (new CsvStatementReader())->read(
            $broken,
            new CsvDialect(delimiter: ';', decimalSeparator: ',', thousandsSeparator: '.', dateFormat: 'd.m.Y', signConvention: CsvDialect::SIGN_DEBIT_CREDIT_COLUMNS),
            ['booking_date' => 0, 'remittance' => 1, 'debit' => 2, 'credit' => 3],
            'SAR',
        ));

        if ($refusal === null) {
            return [
                'a row that could not be read was skipped and the rest imported. The month is then '.
                'short by that row and nothing says which, and the difference surfaces weeks later '.
                'as a reconciliation that does not tie',
            ];
        }

        $failures = [];

        if (! str_contains($refusal, 'row 3')) {
            $failures[] = sprintf('the refusal does not name the row: %s', $refusal);
        }

        if (! str_contains($refusal, 'NOT-A-DATE')) {
            $failures[] = 'the refusal does not show what was in the row';
        }

        if (! str_contains($refusal, 'nothing was imported')) {
            $failures[] = 'the refusal does not say that nothing was imported';
        }

        return $failures;
    }

    /** @return list<string> */
    private function csvNotText(): array
    {
        $refusal = $this->readingRefusal(
            fn () => (new CsvSniffer())->sniff("PK\x03\x04\x14\x00\x08\x00\x08\x00\xFF\xFE\xFD\xFC\x00\x01\x02"),
        );

        if ($refusal === null) {
            return ['a binary file was accepted as a CSV'];
        }

        $failures = [];

        if (! str_contains($refusal, '.xlsx')) {
            $failures[] = sprintf('the refusal does not name what the file actually is: %s', $refusal);
        }

        /*
         | And it says what to do, which is not "try again".
         |
         | The first version of this reported "fewer than two non-empty
         | lines, which usually means an empty date range" - which sends
         | somebody back to the bank to re-export a file that was never the
         | problem. Windows-1252 is single-byte, so it accepts any byte
         | sequence and the encoding check never fired.
         */
        if (! str_contains($refusal, 'export it again')) {
            $failures[] = 'the refusal does not say what to do instead';
        }

        return $failures;
    }

    /** @return list<string> */
    private function csvBothColumns(): array
    {
        $both = "Date;Description;Debit;Credit\n01.05.2026;Both;500,00;1.000,00\n";

        $refusal = $this->readingRefusal(fn () => (new CsvStatementReader())->read(
            $both,
            new CsvDialect(delimiter: ';', decimalSeparator: ',', thousandsSeparator: '.', dateFormat: 'd.m.Y', signConvention: CsvDialect::SIGN_DEBIT_CREDIT_COLUMNS),
            ['booking_date' => 0, 'remittance' => 1, 'debit' => 2, 'credit' => 3],
            'SAR',
        ));

        if ($refusal === null) {
            return [
                'a row with both a debit and a credit was accepted. Both cannot be true of one '.
                'transaction, and the usual cause is a running-balance column mapped as one of them',
            ];
        }

        return str_contains($refusal, 'running balance')
            ? []
            : [sprintf('the refusal does not name the usual cause: %s', $refusal)];
    }

    // ---- Importing --------------------------------------------------------

    /** @return list<string> */
    private function importsByFile(): array
    {
        $report = $this->importFixture('camt053-shape-a.xml');

        $failures = $this->expect([
            'four booked lines and one pending' => [$report['imported'], 5],
            'the pending count reported' => [$report['pending'], 1],
            'the account checked' => [$report['account_was_checked'], true],
            'the balances taken from the file' => [$report['balances_were_stated'], true],
        ]);

        $provisional = BankTransaction::query()
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('is_provisional', true)
            ->count();

        if ($provisional !== 1) {
            $failures[] = sprintf(
                '%d transactions are marked provisional; the pending entry should be the only one',
                $provisional,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function wrongAccountIsRefused(): array
    {
        $other = $this->openAccount(
            openingBalance: '0',
            iban: self::SA_IBAN_OTHER,
            glAccountId: $this->secondBankGlAccountId(),
            label: $this->uniqueLabel('Other'),
        );

        $refusal = $this->refusal(fn () => app(StatementImport::class)->importFile(
            account: $other,
            content: $this->fixture('camt053-shape-a.xml'),
            userId: $this->actorId(),
        ));

        if ($refusal === null) {
            return [
                'a statement was imported into an account it is not for. The balances are another '.
                'account\'s, the lines are another account\'s, and nothing about the result says so',
            ];
        }

        return str_contains($refusal, 'SA0380000000608010167519')
            ? []
            : [sprintf('the refusal does not name the account the file is for: %s', $refusal)];
    }

    /** @return list<string> */
    private function wrongCurrencyIsRefused(): array
    {
        $inEuros = str_replace('SAR', 'EUR', $this->fixture('camt053-shape-a.xml'));

        $refusal = $this->refusal(fn () => app(StatementImport::class)->importFile(
            account: $this->bank(),
            content: $inEuros,
            userId: $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a EUR statement was imported into a SAR account at face value'];
        }

        return str_contains($refusal, 'not a conversion')
            ? []
            : [sprintf('the refusal does not say what importing it would do: %s', $refusal)];
    }

    /** @return list<string> */
    private function zeroLineIsReported(): array
    {
        $this->importFixture('camt053-shape-a.xml');
        $this->importFixture('camt053-shape-c.xml');
        $report = $this->importFixture('camt053-shape-d.xml');

        $failures = [];

        if ((int) $report['skipped'] !== 1) {
            $failures[] = sprintf('%d lines were skipped; the zero advice line should be the one', (int) $report['skipped']);
        }

        $reasons = implode(' ', array_map(
            static fn (array $row): string => (string) $row['why'],
            (array) $report['skipped_detail'],
        ));

        return str_contains($reasons, 'moves no money')
            ? $failures
            : [...$failures, sprintf('the skipped zero line does not say why: %s', $reasons)];
    }

    /** @return list<string> */
    private function detailsAreStored(): array
    {
        $this->importFixture('camt053-shape-a.xml');
        $this->importFixture('camt053-shape-c.xml');

        $batch = BankTransaction::query()
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('bank_reference', 'ANB-BATCH-5512')
            ->first();

        if ($batch === null) {
            return ['the batch entry was not imported'];
        }

        $details = DB::connection('tenant')->table('bank_transaction_details')
            ->where('bank_transaction_id', (string) $batch->getKey())
            ->orderBy('sequence')
            ->get();

        $failures = [];

        if ($details->count() !== 4) {
            $failures[] = sprintf(
                '%d detail rows were written for a batch of four. Without them a payment run is '.
                'permanently unmatchable: the fifty references are inside the entry, not on it',
                $details->count(),
            );
        }

        $sum = BigDecimal::zero();

        foreach ($details as $detail) {
            $sum = $sum->plus((string) $detail->amount);
        }

        if (! $sum->isEqualTo((string) $batch->amount)) {
            $failures[] = sprintf(
                'the stored details total %s against an entry of %s',
                (string) $sum,
                (string) $batch->amount,
            );
        }

        /*
         | The details carry no balance meaning.
         |
         | The entry is what the bank took and what reconciles; a detail
         | that counted toward the account's balance would double it.
         */
        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        if ($balances['reconciliation']['agrees'] !== true) {
            $failures[] = 'storing batch details made the reconciliation disagree, so they are being counted twice';
        }

        if ((bool) $details->first()?->is_batch !== true) {
            $failures[] = 'the detail rows do not record that their entry was a batch';
        }

        return $failures;
    }

    /** @return list<string> */
    private function detailsAreImmutable(): array
    {
        $this->importFixture('camt053-shape-a.xml');

        $detail = DB::connection('tenant')->table('bank_transaction_details')->first();

        if ($detail === null) {
            return ['no detail rows exist to check'];
        }

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('bank_transaction_details')
            ->where('id', (string) $detail->id)
            ->update(['counterparty_name' => 'Somebody else']));

        if ($refusal === null) {
            return [
                'a detail line was edited. It is part of the same third-party assertion as its '.
                'entry, and a batch whose detail lines have been edited reconciles to our ledger '.
                'and to nothing the bank has ever sent',
            ];
        }

        return str_contains($refusal, 'belongs with the match')
            ? []
            : [sprintf('the refusal does not say where our opinion belongs: %s', $refusal)];
    }

    /** @return list<string> */
    private function threeInARow(): array
    {
        $this->postTheOpeningEntry();

        foreach (['camt053-shape-a.xml', 'camt053-shape-c.xml', 'camt053-shape-d.xml'] as $file) {
            try {
                $this->importFixture($file);
            } catch (\Throwable $e) {
                return [sprintf('%s was refused in sequence: %s', $file, $e->getMessage())];
            }
        }

        /*
         | Three real statements, and the reconciliation still agrees.
         |
         | This is the end-to-end proof: continuity held across the three,
         | the pending entry stayed out of the balances, the batch details
         | did not double-count, and the identity still closes.
         */
        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        return $balances['reconciliation']['agrees'] === true
            ? []
            : [sprintf(
                'after three statements the reconciliation reports %s unexplained: %s',
                (string) $balances['reconciliation']['components']['unexplained'],
                (string) $balances['reconciliation']['why'],
            )];
    }

    /** @return list<string> */
    private function intradayDoesNotBreakTheChain(): array
    {
        $this->importFixture('camt053-shape-a.xml');

        /*
         | A CAMT.052 that STATES its own balances, not the MT942.
         |
         | An MT942 carries no balances at all, so the importer opens and
         | closes it where the account already stands - it is transparent to
         | the chain whatever the rules say, and a check built on it passed
         | with the format filter removed. A CAMT.052 may carry ITBD and
         | CLBD figures of its own, which are NOT the last booked closing,
         | and that is the case that breaks a chain counting interim
         | reports. Found by biting it.
         */
        foreach ([
            'camt052-intraday.xml' => 'camt052',
            'mt942-interim.txt' => 'mt942',
        ] as $file => $format) {
            try {
                app(StatementImport::class)->importFile(
                    account: $this->bank(),
                    content: (string) file_get_contents(self::FIXTURES.$file),
                    format: $format,
                    userId: $this->actorId(),
                );
            } catch (\Throwable $e) {
                return [sprintf('%s could not be imported: %s', $file, $e->getMessage())];
            }
        }

        /*
         | And the next booked statement still imports.
         |
         | An interim report is not part of the booked chain: it carries the
         | same movements the booked statement will, and it is in no closing
         | balance. A continuity chain that included it would refuse the
         | next real statement, whose opening is the last BOOKED closing.
         */
        try {
            $this->importFixture('camt053-shape-c.xml');
        } catch (\Throwable $e) {
            return [sprintf(
                'an interim report between two statements broke the chain: %s',
                $e->getMessage(),
            )];
        }

        $failures = [];

        $balance = app(BankBalances::class)->statement($this->bank(), $this->date->copy());

        if ((string) $balance->amount !== '51500.0000') {
            $failures[] = sprintf(
                'an interim report moved the reconciliation baseline to %s',
                (string) $balance->amount,
            );
        }

        /*
         | And the cash position DOES follow it.
         |
         | Which is the whole reason an interim report is imported at all.
         | A treasurer deciding whether a payment run can go out this
         | afternoon needs the intraday figure; a reconciliation must not
         | use it, and the two are separate methods so the wrong one cannot
         | be read by accident.
         */
        $position = app(BankBalances::class)->intradayPosition($this->bank(), $this->date->copy());

        if ($position['provisional'] !== true) {
            $failures[] = 'the cash position does not say it is provisional after an interim import';
        }

        if ((string) $position['balance'] === (string) $balance->amount) {
            $failures[] = sprintf(
                'the cash position is the booked balance (%s), so the interim report fed nothing',
                (string) $position['balance'],
            );
        }

        return $failures;
    }

    // ---- Routing ----------------------------------------------------------

    /** @return list<string> */
    private function routingPicksAStrategy(): array
    {
        $routing = new TransactionRouting();

        return $this->expect([
            'an outgoing transfer to the payment run' => [
                $routing->strategyFor('PMNT', 'ICDT', 'ESCT'),
                'payment_run',
            ],
            'an incoming one to receivables' => [
                $routing->strategyFor('PMNT', 'RCDT', 'ESCT'),
                'receivables',
            ],
            /*
             | A charge has no document and never will.
             |
             | Sending it to the document passes wastes every one of them and
             | occasionally produces a confident wrong match on amount alone -
             | a 50 charge against a 50 invoice, which survives review
             | because the figures agree.
             */
            'a charge to the rule engine' => [$routing->strategyFor('PMNT', 'MDOP', 'CHRG'), 'rule_engine'],
            'an incoming direct debit to payables' => [$routing->strategyFor('PMNT', 'IDDT'), 'payables'],
        ]);
    }

    /** @return list<string> */
    private function routingPrefersTheSpecific(): array
    {
        $routing = new TransactionRouting();

        /*
         | A returned incoming payment is not a new receipt.
         |
         | `PMNT/RCDT` alone routes to receivables; `PMNT/RCDT/RRTN` is a
         | bounced payment coming back, and matching it against open
         | invoices applies a failed payment to a different invoice.
         */
        $general = $routing->strategyFor('PMNT', 'RCDT');
        $specific = $routing->strategyFor('PMNT', 'RCDT', 'RRTN');

        $failures = [];

        if ($specific !== 'reversal') {
            $failures[] = sprintf(
                'a returned incoming payment routes to %s, so a bounced payment would be matched '.
                'against open invoices as though it were a new receipt',
                $specific,
            );
        }

        if ($general === $specific) {
            $failures[] = 'the sub-family makes no difference, so storing it buys nothing';
        }

        return $failures;
    }

    /** @return list<string> */
    private function routingFallsThrough(): array
    {
        $routing = new TransactionRouting();

        $failures = $this->expect([
            'an unmapped code' => [$routing->strategyFor('PMNT', 'ZZZZ', 'QQQQ'), 'generic'],
            'no code at all' => [$routing->strategyFor(null), 'generic'],
        ]);

        $passes = $routing->passesFor('generic');

        if (\count($passes) < 5) {
            $failures[] = sprintf(
                'the generic route runs only %d passes, so an unfamiliar bank gets a worse match '.
                'rather than a slower one',
                \count($passes),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function routingIsData(): array
    {
        $strategies = (array) config('banking.routing.strategies');
        $passes = (array) config('banking.routing.passes');

        $failures = [];

        if ($strategies === [] || $passes === []) {
            return ['the routing table is empty, so nothing is configurable'];
        }

        /*
         | Every strategy a code names has a pass list.
         |
         | A code routing to a strategy with no passes matches nothing and
         | reports no error - the transaction simply never gets a proposal,
         | which looks like the engine being cautious.
         */
        foreach ($strategies as $code => $strategy) {
            if (! \array_key_exists((string) $strategy, $passes)) {
                $failures[] = sprintf('%s routes to "%s", which has no pass list', (string) $code, (string) $strategy);
            }
        }

        $fallback = (string) config('banking.routing.fallback');

        if (! \array_key_exists($fallback, $passes)) {
            $failures[] = sprintf('the fallback strategy "%s" has no pass list', $fallback);
        }

        /*
         | And the passes are ones the engine declares.
         |
         | A typo in a pass name is a pass that never runs, and the only
         | sign of it is a slightly worse match rate.
         */
        $known = array_values((array) config('banking.matching.passes', []));
        $known[] = 'reversal';

        foreach ($passes as $strategy => $list) {
            foreach ((array) $list as $pass) {
                if (! \in_array((string) $pass, $known, true)) {
                    $failures[] = sprintf(
                        '"%s" in the %s route is not a pass the engine declares, so it would never run',
                        (string) $pass,
                        (string) $strategy,
                    );
                }
            }
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return list<ParsedStatement> */
    private function camt(string $file, string $format = 'camt053'): array
    {
        return (new CamtParser($format))->parse($this->fixture($file));
    }

    private function fixture(string $file): string
    {
        return (string) file_get_contents(self::FIXTURES.$file);
    }

    /** @return array<string, mixed> */
    private function importFixture(string $file): array
    {
        return app(StatementImport::class)->importFile(
            account: $this->bank(),
            content: $this->fixture($file),
            fileName: $file,
            userId: $this->actorId(),
        );
    }

    /** @return array<string, int> */
    private function europeanMap(): array
    {
        return [
            'booking_date' => 0,
            'value_date' => 1,
            'remittance' => 2,
            'counterparty_name' => 3,
            'debit' => 4,
            'credit' => 5,
            'currency' => 6,
        ];
    }

    /**
     * Compare what was read against what the file says, naming both.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $expectations
     * @return list<string>
     */
    private function expect(array $expectations): array
    {
        $failures = [];

        foreach ($expectations as $what => [$actual, $expected]) {
            if ($actual !== $expected) {
                $failures[] = sprintf(
                    '%s read as %s rather than %s',
                    $what,
                    $this->show($actual),
                    $this->show($expected),
                );
            }
        }

        return $failures;
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => '"'.$value.'"',
            default => get_debug_type($value),
        };
    }

    /**
     * A reading refusal, which needs no savepoint - nothing is written.
     *
     * @return string|null
     */
    private function readingRefusal(callable $attempt): ?string
    {
        try {
            $attempt();

            return null;
        } catch (StatementUnreadable $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
    }
}
