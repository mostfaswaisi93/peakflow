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
            'the run clock is printed and is not part of the question' => $this->theClockIsNotTheQuestion(),
            'but an as-of somebody asked for is' => $this->anAskedForAsOfIsTheQuestion(),
            'and so is a filter, because it changes every figure' => $this->aFilterIsTheQuestion(),
            'the parameter block is neutralized like any other cell' => $this->thePreambleIsNeutralized(),
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

    /**
     * Two runs of one question, an hour apart, are one question.
     *
     * ## The defect
     *
     * `ReportBasis::for()` defaulted `asOf` to `Carbon::now()` and put the
     * result in the digest, so in production two runs of the same report
     * over a closed period NEVER carried the same digest. Every pair looked
     * like two different questions, which is the exact opposite of the one
     * guarantee PF-198 asks for.
     *
     * And this suite could not see it, because its own helper passed a
     * fixed `asOf` - with the comment "so the digest is about the
     * parameters and not the clock", which was true of the fixture and
     * false of the product. A check that only ever exercises the path where
     * the bug cannot appear is a check that cannot fail.
     *
     * So this one uses the DEFAULT, and separates the two instants by an
     * hour rather than a millisecond: a sibling check elsewhere in this
     * repository could not fail because two calls in the same second shared
     * a timestamp, and that is the same mistake one layer down.
     *
     * @return list<string>
     */
    private function theClockIsNotTheQuestion(): array
    {
        $morning = $this->basisRunAt('2026-11-18T09:00:00+00:00');
        $evening = $this->basisRunAt('2026-11-18T18:30:00+00:00');

        $violations = [];

        if ($morning->digest() !== $evening->digest()) {
            $violations[] = 'the same report run twice, nine hours apart, carries two digests. Nobody '.
                'can ever compare two runs, so the fingerprint answers no question at all - and it '.
                'looks like it does, which is why somebody would rely on it.';
        }

        foreach ([$morning, $evening] as $basis) {
            if (! str_contains((string) json_encode($basis->lines()), $basis->ranAt)) {
                $violations[] = 'the run clock is not printed. "When was this taken" is the first thing '.
                    'asked of a printout found in a file six months later, and keeping it out of the '.
                    'digest is not a reason to keep it off the page.';
            }
        }

        if ($morning->asOf !== null) {
            $violations[] = 'a basis nobody gave an as-of to has one anyway, so the asked-for instant '.
                'and the clock are the same field again';
        }

        return $violations;
    }

    /**
     * An as-of IS the question, when somebody asked for one.
     *
     * The other half of the distinction, and it has to be checked or the
     * fix above degenerates into "ignore the as-of entirely" - which would
     * make a balance sheet as at the 30th and one as at the 31st digest
     * identically, and those are different reports.
     *
     * @return list<string>
     */
    private function anAskedForAsOfIsTheQuestion(): array
    {
        $thirtieth = ReportBasis::for(
            entityId: '01ABCDEF',
            entityCode: 'DEMO',
            from: Carbon::parse('2026-01-01'),
            until: Carbon::parse('2026-12-31'),
            functionalCurrency: 'SAR',
            asOf: Carbon::parse('2026-11-30T23:59:59+00:00'),
        );

        $thirtyFirst = ReportBasis::for(
            entityId: '01ABCDEF',
            entityCode: 'DEMO',
            from: Carbon::parse('2026-01-01'),
            until: Carbon::parse('2026-12-31'),
            functionalCurrency: 'SAR',
            asOf: Carbon::parse('2026-12-31T23:59:59+00:00'),
        );

        if ($thirtieth->digest() === $thirtyFirst->digest()) {
            return ['a report asked for as at 30 November and one asked for as at 31 December carry '.
                'the same digest, so the asked-for instant is being discarded along with the clock'];
        }

        return [];
    }

    /**
     * A filter changes every figure, so it changes the question.
     *
     * The activity for one cost centre and the activity for the whole
     * entity are not the same report. A digest that could not express the
     * filter would call them the same question - worse than no digest,
     * because somebody would act on the match.
     *
     * The order a caller passes them in is NOT part of the question, and
     * that is checked too: two screens passing the same two filters in
     * different orders would otherwise produce two digests for one
     * question, with identical printed blocks and no way to see why.
     *
     * @return list<string>
     */
    private function aFilterIsTheQuestion(): array
    {
        $unfiltered = $this->basisFiltered([]);
        $riyadh = $this->basisFiltered(['cost centre' => 'Riyadh branch']);
        $jeddah = $this->basisFiltered(['cost centre' => 'Jeddah branch']);

        $violations = [];

        if ($unfiltered->digest() === $riyadh->digest()) {
            $violations[] = 'the whole entity and one cost centre carry the same digest, so a filtered '.
                'report and an unfiltered one claim to be the same question';
        }

        if ($riyadh->digest() === $jeddah->digest()) {
            $violations[] = 'two different cost centres carry the same digest, so the filter\'s VALUE '.
                'is not in the question - only the fact that there was one';
        }

        $forwards = $this->basisFiltered(['cost centre' => 'Riyadh branch', 'party' => 'Alpha']);
        $backwards = $this->basisFiltered(['party' => 'Alpha', 'cost centre' => 'Riyadh branch']);

        if ($forwards->digest() !== $backwards->digest()) {
            $violations[] = 'the same two filters passed in two orders carry two digests. The printed '.
                'blocks are identical, so whoever compares them has no way to see why they differ.';
        }

        if (! str_contains((string) json_encode($unfiltered->lines()), 'none')) {
            $violations[] = 'an unfiltered report does not say so. An unfiltered report and one whose '.
                'filter block failed to print look identical on paper, and the difference is every '.
                'figure on it.';
        }

        return $violations;
    }

    /**
     * The parameter block is data too, and it is the forgettable cell.
     *
     * It is tempting to treat the preamble as ours and therefore safe, and
     * it is not: it prints the FILTER VALUES, and a filter value is a party
     * name or a cost centre somebody typed. A customer called
     * `=cmd|'/c calc'!A1` reaches the file through "Filtered by party"
     * exactly as readily as through a data row - and through the one cell
     * in the file nobody thought about, which is how this class of bug
     * survives a fix.
     *
     * @return list<string>
     */
    private function thePreambleIsNeutralized(): array
    {
        $rendered = (new CsvExport())->render(
            ['code'],
            [['4000']],
            [['Filtered by party', '=cmd|\'/c calc\'!A1']],
        );

        $firstRow = explode("\r\n", ltrim($rendered, "\xEF\xBB\xBF"))[0] ?? '';

        // The filter VALUE is the second cell; read it as a spreadsheet does.
        $cells = str_getcsv($firstRow);
        $value = $cells[1] ?? '';

        if (! str_starts_with($value, "'")) {
            return [sprintf(
                'a filter value in the parameter block renders as %s, which a spreadsheet runs. The '.
                'data rows are neutralized and the block above them is not, so the fix covers every '.
                'cell except the one carrying a customer\'s own name.',
                $value,
            )];
        }

        return [];
    }

    private function basisRunAt(string $ranAt): ReportBasis
    {
        return ReportBasis::for(
            entityId: '01ABCDEF',
            entityCode: 'DEMO',
            from: Carbon::parse('2026-01-01'),
            until: Carbon::parse('2026-03-31'),
            functionalCurrency: 'SAR',
            ranAt: Carbon::parse($ranAt),
        );
    }

    /** @param array<string, ?string> $filters */
    private function basisFiltered(array $filters): ReportBasis
    {
        return ReportBasis::for(
            entityId: '01ABCDEF',
            entityCode: 'DEMO',
            from: Carbon::parse('2026-01-01'),
            until: Carbon::parse('2026-03-31'),
            functionalCurrency: 'SAR',
            report: 'general_ledger_detail',
            filters: $filters,
            ranAt: Carbon::parse('2026-11-18T09:00:00+00:00'),
        );
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
