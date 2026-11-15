<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Lang\Message;

/**
 * What a spreadsheet import would do, before it does any of it - PF-055.
 *
 * ## Why the dry run is the primary operation and the import is the
 * afterthought
 *
 * A spreadsheet of four hundred journal lines has mistakes in it. Not might:
 * does. The person who prepared it has a column of account codes from last
 * year's chart, two rows where the sign is wrong, and one entry that is out
 * by the VAT.
 *
 * An import that posts what it can and reports the rest leaves them with a
 * partial month in the ledger and a spreadsheet they must now diff against
 * it. So nothing is written unless EVERYTHING passes, and the report names
 * every rejected row with its spreadsheet row number - the number they can
 * see in the spreadsheet, not an index into an array.
 *
 * ## Grouped into entries, because a spreadsheet is not a list of lines
 *
 * Rows carry a reference, and rows sharing one are an entry. Each entry has
 * to balance on its own: a file whose total is zero while two of its entries
 * are out by equal and opposite amounts is a file that would post two wrong
 * entries and look right.
 */
final readonly class ImportReport
{
    /**
     * @param  list<array{reference: string, lines: int, date: string, balances: bool, imbalance: string}>  $entries
     * @param  list<array{row: int, reference: ?string, field: string, code: string, message_ar: string, message_en: string}>  $rejections
     */
    public function __construct(
        public bool $clean,
        public int $rows,
        public array $entries,
        public array $rejections,
        public string $totalDebit,
        public string $totalCredit,
        public string $currency,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'clean' => $this->clean,
            'rows' => $this->rows,
            'entries' => $this->entries,
            'rejections' => $this->rejections,
            'rejected_rows' => count($this->rejections),
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'currency' => $this->currency,
        ];
    }

    /**
     * A one-line summary for the top of the screen - PF-016.
     *
     * Counting the ROWS rejected rather than the problems found, because one
     * row can have three problems and "27 problems" reads as 27 rows to fix.
     *
     * ## One ICU message per sentence, and no locale branch
     *
     * This was two `sprintf` templates behind `$locale === 'ar'`, with the
     * Arabic one assembling a sentence out of `ArabicCount` fragments. It had
     * two problems, and the English one is the embarrassing one:
     *
     *     Ready: 1 rows in 1 entries, all balanced.
     *
     * English got no pluralisation at all, because the branch that handled it
     * was the Arabic one. A locale branch in the code means every sentence is
     * written twice and only the locale the author speaks gets checked.
     *
     * So the sentence lives in the catalog as one ICU message per locale, and
     * CLDR decides the forms - six of them in Arabic, two in English, and
     * neither number appears here.
     */
    public function summary(string $locale): string
    {
        if ($this->clean) {
            return Message::of('import.summary.ready', [
                'rows' => $this->rows,
                'entries' => count($this->entries),
            ], $locale);
        }

        return Message::of('import.summary.rejected', [
            'bad' => count(array_unique(array_column($this->rejections, 'row'))),
            'rows' => $this->rows,
        ], $locale);
    }
}
