<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Exceptions\EntryDoesNotBalance;
use App\Tenant\Ledger\Exceptions\NoPeriodForDate;
use App\Tenant\Ledger\Exceptions\PeriodDoesNotAcceptPostings;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes an entry from a posting rule - PF-047.
 *
 * ## The rule's output is validated BEFORE anything is written
 *
 * A rule that returns unbalanced lines is a bug in that rule. If the lines
 * went straight to the database, the failure would surface at COMMIT as a
 * constraint violation naming a trigger - true, and useless: it says the
 * entry does not balance, not which of forty rules produced it.
 *
 * So the sum is checked here, in `Money`, and the failure names the rule.
 * The database check remains as the thing that cannot be bypassed; this one
 * exists so a developer knows where to look.
 *
 * ## `peakflow.posting_rule` is set for the transaction
 *
 * That session variable is what lets a control account accept this entry. It
 * is set with `set_config(..., true)` - local to the transaction - so it
 * cannot leak into the next statement on a pooled connection and quietly
 * authorise a manual entry.
 */
final class Poster
{
    public function __construct(
        private readonly PostingRuleRegistry $rules,
        private readonly FiscalCalendarGenerator $calendar,
        private readonly NumberAllocator $numbers,
    ) {}

    /**
     * Post an event through a registered rule.
     *
     * @param  array<string, mixed>  $event
     */
    public function post(
        Entity $entity,
        string $ruleKey,
        array $event,
        Carbon $accountingDate,
        string $journalCode = 'GEN',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $narration = null,
    ): JournalEntry {
        $rule = $this->rules->resolve($ruleKey);

        $context = new PostingContext(
            entityId: (string) $entity->getKey(),
            functionalCurrency: (string) $entity->functional_currency,
            accountingDate: $accountingDate,
        );

        $lines = $rule->lines($event, $context);

        $this->assertBalanced($lines, $ruleKey, $context);

        $period = $this->calendar->periodFor((string) $entity->getKey(), $accountingDate)
            ?? throw NoPeriodForDate::on($accountingDate);

        if (! $period->acceptsPostings()) {
            throw PeriodDoesNotAcceptPostings::in($period->label, $period->state);
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity,
            $ruleKey,
            $lines,
            $period,
            $accountingDate,
            $journalCode,
            $sourceType,
            $sourceId,
            $narration,
            $context,
        ): JournalEntry {
            /*
             | Transaction-local, so it cannot survive into the next statement
             | on a pooled connection and silently authorise a manual entry
             | against a control account.
             */
            DB::connection('tenant')->statement(
                "SELECT set_config('peakflow.posting_rule', ?, true)",
                [$ruleKey],
            );

            $journalId = DB::connection('tenant')->table('journals')
                ->where('entity_id', $entity->getKey())
                ->where('code', $journalCode)
                ->value('id');

            if ($journalId === null) {
                throw new \RuntimeException(sprintf(
                    'Entity %s has no journal "%s". Posting into a journal that does not exist would put the '.
                    'entry somewhere nobody looks.',
                    $entity->code,
                    $journalCode,
                ));
            }

            $entry = JournalEntry::create([
                'entity_id' => $entity->getKey(),
                'journal_id' => $journalId,
                'period_id' => $period->getKey(),
                'number' => $this->numbers->next((string) $entity->getKey(), 'journal', $journalCode, $accountingDate),
                'accounting_date' => $accountingDate->toDateString(),
                'entered_at' => now(),
                'narration' => $narration,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'posting_rule' => $ruleKey,
                'status' => 'posted',
                'posted_by_user_id' => auth()->guard('web')->id(),
                'posted_at' => now(),
                'created_by_user_id' => auth()->guard('web')->id(),
            ]);

            $rows = [];

            foreach ($lines as $line) {
                $accountId = $line->role !== null
                    ? $context->account($line->role)
                    : (string) $line->accountId;

                $amount = (string) $line->amount->amount;

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entity->getKey(),
                    'journal_entry_id' => $entry->getKey(),
                    'accounting_date' => $accountingDate->toDateString(),
                    'period_id' => $period->getKey(),
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'currency' => $line->amount->currency,
                    /*
                     | Always null today, because `assertBalanced` refuses a
                     | line that is not already in the functional currency -
                     | translation is the rule's job, since which rate applies
                     | is a property of the transaction.
                     |
                     | Null rather than 1: a rate of 1 asserts that a
                     | translation happened and came out even, which is a
                     | different claim from no translation at all, and the two
                     | are indistinguishable afterwards.
                     |
                     | Multi-currency rules in PF-06x will pass a rate through
                     | and this becomes the place it lands.
                     */
                    'fx_rate' => null,
                    'functional_amount' => $amount,
                    'party_id' => $line->partyId,
                    'tax_code_id' => $line->taxCodeId,
                    'narration' => $line->narration,
                    'dimension_1' => $line->dimensions[1] ?? null,
                    'dimension_2' => $line->dimensions[2] ?? null,
                    'dimension_3' => $line->dimensions[3] ?? null,
                    'dimension_4' => $line->dimensions[4] ?? null,
                    'dimension_5' => $line->dimensions[5] ?? null,
                    'dimension_6' => $line->dimensions[6] ?? null,
                    'dimension_7' => $line->dimensions[7] ?? null,
                    'dimension_8' => $line->dimensions[8] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::connection('tenant')->table('journal_lines')->insert($rows);

            return $entry;
        });
    }

    /**
     * The lines must sum to zero in the functional currency.
     *
     * Summed with `Money`, never with floats - two lines of 0.1 and -0.1 add
     * to exactly zero here and to -1.3877787807814e-17 in a float, and the
     * second one is refused by the database at commit with no indication of
     * which rule produced it.
     *
     * @param  list<PostingLine>  $lines
     *
     * @throws EntryDoesNotBalance
     */
    private function assertBalanced(array $lines, string $ruleKey, PostingContext $context): void
    {
        if ($lines === []) {
            throw EntryDoesNotBalance::producedNothing($ruleKey);
        }

        $total = $context->zero();

        foreach ($lines as $line) {
            if ($line->amount->currency !== $context->functionalCurrency) {
                // A rule returning a foreign-currency line without a
                // translation has not decided what the entry is worth, and
                // the ledger cannot decide for it.
                throw EntryDoesNotBalance::wrongCurrency(
                    $ruleKey,
                    $line->amount->currency,
                    $context->functionalCurrency,
                );
            }

            $total = $total->plus($line->amount);
        }

        if (! $total->isZero()) {
            throw EntryDoesNotBalance::by($ruleKey, $total, count($lines));
        }
    }

    /**
     * What a rule would post, without posting it.
     *
     * For the inspection screen and for a dry run. The balance check runs, so
     * a broken rule is reported here rather than at the moment somebody
     * issues a real invoice.
     *
     * @param  array<string, mixed>  $event
     * @return list<PostingLine>
     */
    public function preview(Entity $entity, string $ruleKey, array $event, Carbon $accountingDate): array
    {
        $context = new PostingContext(
            entityId: (string) $entity->getKey(),
            functionalCurrency: (string) $entity->functional_currency,
            accountingDate: $accountingDate,
        );

        $lines = $this->rules->resolve($ruleKey)->lines($event, $context);

        $this->assertBalanced($lines, $ruleKey, $context);

        return $lines;
    }
}
