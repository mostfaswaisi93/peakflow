<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenant\Reporting\Export\CsvExport;
use App\Tenant\Reporting\Export\ReportBasis;
use Illuminate\Support\Carbon;

/**
 * Exporting a report, and being able to run it again - PF-197, PF-198.
 *
 * ## What a check here is for
 *
 * **A spreadsheet opened on somebody's laptop, running a payload.** A cell
 * beginning with `=`, `+`, `-`, `@`, a tab or a carriage return is a
 * FORMULA to Excel, LibreOffice and Sheets. A customer named
 * `=cmd|'/c calc'!A1` has not broken this product - they have planted
 * something in it, and it fires on the accountant who exports the party
 * list. Nothing in the ledger was ever wrong, which is why no ledger check
 * can see it.
 *
 * **Quoting is not the fix and a check must prove it knows that.** A
 * quoted `"=1+1"` is still a formula: the quotes belong to the CSV reader
 * and are gone before the spreadsheet sees the cell. So the checks assert
 * what the cell STARTS with after quoting is stripped, which is what a
 * spreadsheet actually reads.
 *
 * **Two people running "the same report" and disagreeing.** One included
 * the adjusting period. Both answers are right to different questions, and
 * a page that shows only figures gives neither of them a way to find out.
 */
final class ExportRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'a formula cell is made text, whatever it says' => $this->formulaeAreNeutralized(),
            'and the apostrophe goes inside the quoting' => $this->orderOfQuotingAndEscaping(),
            'a name that merely looks dangerous is not altered' => $this->ordinaryTextSurvives(),
            'the file carries a BOM, or Arabic arrives as mojibake' => $this->theBomIsPresent(),
            'rows end CRLF, per RFC 4180' => $this->rowsEndCrLf(),
            'a quote inside a cell is doubled, never dropped' => $this->quotesAreDoubled(),
            'the basis prints every parameter that changes a figure' => $this->theBasisIsComplete(),
            'the same question digests the same, in any order' => $this->theDigestIsStable(),
            'and a different question does not' => $this->theDigestSeparatesQuestions(),
        ];
    }

    /** @return list<string> */
    private function formulaeAreNeutralized(): array
    {
        $violations = [];
        $csv = new CsvExport();

        /*
         | Every character a spreadsheet treats as the start of a formula.
         |
         | Tab and carriage return are in the list because a spreadsheet
         | trims whitespace BEFORE the formula test, so a cell beginning
         | with a tab and then `=` is a formula that a check looking only
         | at `=` would miss - and so would the code.
         */
        $payloads = [
            '=cmd|\'/c calc\'!A1',
            '+1+1',
            '-1+1',
            '@SUM(1:2)',
            "\t=1+1",
            "\r=1+1",
        ];

        foreach ($payloads as $payload) {
            $rendered = $csv->render(['name'], [[$payload]]);
            $cell = $this->firstDataCell($rendered);

            if (! str_starts_with($cell, "'")) {
                $violations[] = sprintf(
                    'the cell %s exported as %s. A spreadsheet reads it as a formula and runs it on the '.
                    'machine of whoever opened the file - and nothing in the ledger was ever wrong, so '.
                    'no other check here can see it.',
                    json_encode($payload),
                    json_encode($cell),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function orderOfQuotingAndEscaping(): array
    {
        $csv = new CsvExport();

        // A payload that is BOTH dangerous and in need of quoting.
        $rendered = $csv->render(['name'], [['=1+1,2']]);
        $cell = $this->rawDataCell($rendered);

        if (! str_starts_with($cell, '"\'')) {
            return [sprintf(
                'a cell that is both a formula and needs quoting exported as %s. The apostrophe has to '.
                'go INSIDE the quotes: outside it, the CSV reader consumes it and the spreadsheet sees '.
                'a formula again.',
                json_encode($cell),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function ordinaryTextSurvives(): array
    {
        $csv = new CsvExport();
        $violations = [];

        /*
         | Stripping the character would be the easy fix and is wrong.
         |
         | A supplier genuinely called "Alpha-Beta" or a note reading
         | "re: 1=1" is data. Changing it silently costs somebody an
         | afternoon reconciling two systems that disagree about a name.
         */
        foreach (['Alpha-Beta', 're: 1=1', 'a@b.test', 'شركة ألفا'] as $text) {
            $cell = $this->firstDataCell($csv->render(['name'], [[$text]]));

            if ($cell !== $text) {
                $violations[] = sprintf(
                    'the ordinary value %s exported as %s. It does not begin with a formula character, '.
                    'so nothing should have touched it.',
                    json_encode($text),
                    json_encode($cell),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function theBomIsPresent(): array
    {
        $rendered = (new CsvExport())->render(['الاسم'], [['شركة ألفا']]);

        if (! str_starts_with($rendered, "\xEF\xBB\xBF")) {
            return ['the export has no byte-order mark. Excel on Windows then reads it in the system '.
                'code page and every Arabic name arrives as mojibake - which the person who exported '.
                'it reports as the product being broken.'];
        }

        return [];
    }

    /** @return list<string> */
    private function rowsEndCrLf(): array
    {
        $rendered = (new CsvExport())->render(['a'], [['1'], ['2']]);

        if (substr_count($rendered, "\r\n") !== 3) {
            return [sprintf(
                'a header and two rows produced %d CRLF line endings rather than 3. RFC 4180 says CRLF '.
                'and Excel is the strictest reader in practice: an LF-only file opens as a single row '.
                'in some versions, which looks like data loss.',
                substr_count($rendered, "\r\n"),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function quotesAreDoubled(): array
    {
        $rendered = (new CsvExport())->render(['a'], [['He said "no"']]);

        if (! str_contains($rendered, '"He said ""no"""')) {
            return ['a double quote inside a cell was not doubled, so the row ends early and every '.
                'column after it shifts by one - silently, into the wrong heading'];
        }

        return [];
    }

    /** @return list<string> */
    private function theBasisIsComplete(): array
    {
        $basis = $this->basis();
        $printed = implode('|', array_column($basis->lines(), 'value'));

        $violations = [];

        /*
         | Each of these changes a figure.
         |
         | The adjusting-entry treatment is the one that catches people:
         | two colleagues run "the trial balance", one includes period 13,
         | and both are right about different questions. A block that left
         | it out would leave them with nothing to compare.
         */
        foreach ([
            'DEMO' => 'the entity',
            '2026-01-01' => 'the start of the period',
            '2026-12-31' => 'the end of the period',
            'SAR' => 'the functional currency',
            'USD' => 'the presentation currency',
            'excluded' => 'the adjusting-entry treatment',
        ] as $expected => $what) {
            if (! str_contains($printed, (string) $expected)) {
                $violations[] = sprintf(
                    'the printed basis does not carry %s, so two runs that differ in it look identical '.
                    'on paper',
                    $what,
                );
            }
        }

        if (! str_contains($printed, substr($basis->digest(), 0, 16))) {
            $violations[] = 'the printed basis carries no parameter digest, so nobody can tell at a '.
                'glance whether two printouts answer the same question';
        }

        return $violations;
    }

    /** @return list<string> */
    private function theDigestIsStable(): array
    {
        if ($this->basis()->digest() !== $this->basis()->digest()) {
            return ['the same parameters produced two different digests, so the digest says nothing'];
        }

        return [];
    }

    /** @return list<string> */
    private function theDigestSeparatesQuestions(): array
    {
        $violations = [];
        $base = $this->basis();

        $variants = [
            'the adjusting-entry treatment' => $this->basis(includesAdjusting: true),
            'the presentation currency' => $this->basis(presentation: 'EUR'),
            'the period end' => $this->basis(until: '2026-11-30'),
        ];

        foreach ($variants as $what => $other) {
            if ($base->digest() === $other->digest()) {
                $violations[] = sprintf(
                    'changing %s left the digest unchanged, so two reports that answer different '.
                    'questions claim to answer the same one',
                    $what,
                );
            }
        }

        return $violations;
    }

    private function basis(
        bool $includesAdjusting = false,
        ?string $presentation = 'USD',
        string $until = '2026-12-31',
    ): ReportBasis {
        return ReportBasis::for(
            entityId: '01ABCDEF',
            entityCode: 'DEMO',
            from: Carbon::parse('2026-01-01'),
            until: Carbon::parse($until),
            functionalCurrency: 'SAR',
            presentationCurrency: $presentation,
            includesAdjusting: $includesAdjusting,
            // Fixed, so the digest is about the parameters and not the clock.
            asOf: Carbon::parse('2026-11-18T09:00:00+00:00'),
        );
    }

    /** The first data cell, as a SPREADSHEET reads it: quoting removed. */
    private function firstDataCell(string $csv): string
    {
        $cell = $this->rawDataCell($csv);

        if (str_starts_with($cell, '"') && str_ends_with($cell, '"')) {
            return str_replace('""', '"', substr($cell, 1, -1));
        }

        return $cell;
    }

    /** The first data cell exactly as written, quoting and all. */
    private function rawDataCell(string $csv): string
    {
        $lines = explode("\r\n", ltrim($csv, "\xEF\xBB\xBF"));

        return $lines[1] ?? '';
    }
}
