<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Lang\ArabicCount;

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
     * A one-line summary for the top of the screen.
     *
     * Counting the ROWS rejected rather than the problems found, because one
     * row can have three problems and "27 problems" reads as 27 rows to fix.
     */
    public function summary(string $locale): string
    {
        if ($this->clean) {
            return $locale === 'ar'
                ? sprintf(
                    'جاهز: %s في %s، كلها متوازنة. لم يُكتب شيء بعد.',
                    ArabicCount::rows($this->rows),
                    ArabicCount::entries(count($this->entries)),
                )
                : sprintf(
                    'Ready: %d rows in %d entries, all balanced. Nothing has been written yet.',
                    $this->rows,
                    count($this->entries),
                );
        }

        $rows = count(array_unique(array_column($this->rejections, 'row')));

        return $locale === 'ar'
            ? sprintf(
                'مرفوض: %d من %s بها مشكلة. لم يُكتب شيء - الملف يُرحَّل كاملًا أو لا يُرحَّل.',
                $rows,
                ArabicCount::rows($this->rows),
            )
            : sprintf(
                'Rejected: %d of %d rows have a problem. Nothing was written - the file posts whole or '.
                'not at all.',
                $rows,
                $this->rows,
            );
    }
}
