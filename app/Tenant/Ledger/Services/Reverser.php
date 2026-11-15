<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Tenant\Ledger\Enums\EntryStatus;
use App\Tenant\Ledger\Exceptions\CannotReverse;
use App\Tenant\Ledger\Exceptions\NoPeriodForDate;
use App\Tenant\Ledger\Exceptions\PeriodDoesNotAcceptPostings;
use App\Tenant\Ledger\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrects a posted entry by reversing it - PF-056.
 *
 * ## A correction is two entries, never an edit
 *
 * The original was reported on. Editing it leaves a trial balance that no
 * longer reconciles to the statements printed from it, and nothing recording
 * why. A reversal leaves both visible: what was recorded, and that it was
 * undone.
 *
 * ## A reversal cannot itself be reversed
 *
 * Refused, so a chain of reversals cannot obscure the original. Reversing a
 * reversal is almost always an attempt to undo a correction, and the honest
 * form of that is a NEW entry restating the original - which is one entry to
 * read rather than four to reconcile.
 *
 * ## Reversing into a LATER period is normal
 *
 * An accrual raised in March and reversed in April is ordinary accounting,
 * not an exception. What is refused is reversing into a period that does not
 * accept postings - including the original's own period once it is closed,
 * which is exactly when somebody wants to.
 */
final class Reverser
{
    public function __construct(
        private readonly FiscalCalendarGenerator $calendar,
        private readonly NumberAllocator $numbers,
    ) {}

    /**
     * @throws CannotReverse
     */
    public function reverse(JournalEntry $entry, Carbon $on, string $reason): JournalEntry
    {
        if ($entry->status === EntryStatus::Draft) {
            throw CannotReverse::becauseItIsADraft($entry->number);
        }

        if ($entry->status === EntryStatus::Reversed) {
            throw CannotReverse::becauseItIsAlreadyReversed($entry->number);
        }

        if ($entry->reverses_entry_id !== null) {
            throw CannotReverse::becauseItIsItselfAReversal($entry->number);
        }

        if (trim($reason) === '') {
            throw CannotReverse::withoutAReason($entry->number);
        }

        $period = $this->calendar->periodFor((string) $entry->entity_id, $on)
            ?? throw NoPeriodForDate::on($on);

        if (! $period->acceptsPostings()) {
            throw PeriodDoesNotAcceptPostings::in($period->label, $period->state);
        }

        return DB::connection('tenant')->transaction(function () use ($entry, $on, $reason, $period): JournalEntry {
            /*
             | The reversal posts to the same accounts, including control
             | accounts - so it needs the same authorisation the original had.
             | Marked as a reversal rather than reusing the original rule's
             | key, so an audit can tell the two apart.
             */
            DB::connection('tenant')->statement(
                "SELECT set_config('peakflow.posting_rule', ?, true)",
                ['ledger.reversal'],
            );

            $journalCode = (string) DB::connection('tenant')->table('journals')
                ->where('id', $entry->journal_id)
                ->value('code');

            $reversal = JournalEntry::create([
                'entity_id' => $entry->entity_id,
                'journal_id' => $entry->journal_id,
                'period_id' => $period->getKey(),
                'number' => $this->numbers->next((string) $entry->entity_id, 'journal', $journalCode, $on),
                'accounting_date' => $on->toDateString(),
                'entered_at' => now(),
                'narration' => sprintf('Reversal of %s: %s', $entry->number, $reason),
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'posting_rule' => 'ledger.reversal',
                'status' => EntryStatus::Posted->value,
                'reverses_entry_id' => $entry->getKey(),
                'posted_by_user_id' => auth()->guard('web')->id(),
                'posted_at' => now(),
                'created_by_user_id' => auth()->guard('web')->id(),
            ]);

            /*
             | Every line, negated. Copied rather than recomputed from the
             | source document: the document may have changed since, and a
             | reversal that does not exactly undo what was posted leaves a
             | residue nobody can explain.
             */
            $original = DB::connection('tenant')->table('journal_lines')
                ->where('journal_entry_id', $entry->getKey())
                ->get();

            $rows = [];

            foreach ($original as $line) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $line->entity_id,
                    'journal_entry_id' => $reversal->getKey(),
                    'accounting_date' => $on->toDateString(),
                    'period_id' => $period->getKey(),
                    'account_id' => $line->account_id,
                    // Negated as a STRING operation on the numeric, never
                    // through a float - `-1 * 0.1` is not -0.1 in binary
                    // floating point, and the reversal would not cancel.
                    'amount' => $this->negate((string) $line->amount),
                    'currency' => $line->currency,
                    'fx_rate' => $line->fx_rate,
                    'functional_amount' => $this->negate((string) $line->functional_amount),
                    'party_id' => $line->party_id,
                    'tax_code_id' => $line->tax_code_id,

                    /*
                     | The ORIGINAL tax point, carried over - PF-086.
                     |
                     | A reversal of a May supply belongs in May's return, not
                     | in the return of whenever somebody noticed. Where May
                     | is already filed the correction is PF-087's
                     | adjustment, and the tax point is what says which period
                     | is being corrected.
                     */
                    'tax_point' => $line->tax_point,
                    'narration' => $line->narration,
                    'dimension_1' => $line->dimension_1,
                    'dimension_2' => $line->dimension_2,
                    'dimension_3' => $line->dimension_3,
                    'dimension_4' => $line->dimension_4,
                    'dimension_5' => $line->dimension_5,
                    'dimension_6' => $line->dimension_6,
                    'dimension_7' => $line->dimension_7,
                    'dimension_8' => $line->dimension_8,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows === []) {
                throw CannotReverse::becauseItHasNoLines($entry->number);
            }

            DB::connection('tenant')->table('journal_lines')->insert($rows);

            // The original records that it was undone. This is the only
            // mutation a posted entry permits.
            $entry->update(['status' => EntryStatus::Reversed->value]);

            return $reversal;
        });
    }

    /**
     * Negate a decimal held as a string.
     *
     * Never `-1 * (float) $value`: 0.1 is not representable in binary
     * floating point, so the product is not exactly -0.1 and the reversal
     * leaves a residue of about 1e-17 that the balance check then rejects -
     * or worse, does not, and the account never quite clears.
     */
    private function negate(string $amount): string
    {
        if (str_starts_with($amount, '-')) {
            return substr($amount, 1);
        }

        return '-'.$amount;
    }
}
