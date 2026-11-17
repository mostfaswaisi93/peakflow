<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Support\Money\Money;
use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;
use Illuminate\Support\Facades\DB;

/**
 * Pass 2: the payment run - PF-156.
 *
 * ## The one pass that matches against something we sent
 *
 * Every other document pass compares a bank line against something a
 * counterparty did: an invoice they owe, a reference they typed, a figure
 * they happened to pay. This one compares it against an instruction **we**
 * issued, on a date we chose, for an amount we computed.
 *
 * That is why it sits second, above structured remittance and below an
 * exact reference. We know what we sent; the only question is which debit
 * on the statement is it.
 *
 * ## Two shapes, because banks debit batches two ways
 *
 * A bank handed a payment file either debits the whole batch as one line,
 * or debits each transfer separately. Both are normal, both are what the
 * same file produces at different banks, and an engine that only knew one
 * would leave every statement from the other bank to be matched by hand.
 *
 * - **The run's total.** One debit for the batch. Strong evidence: the
 *   figure was computed from the run's own lines minutes before the file
 *   went out, and nothing else on the statement is likely to equal it.
 * - **An instruction within it.** One debit per beneficiary. Weaker, and
 *   deliberately so: a 3,000 transfer in a run of forty is a figure other
 *   things on the statement may also equal, and two instructions in the
 *   same run at the same amount identify neither.
 *
 * ## Only released runs, and only once
 *
 * A prepared run has not been sent, so a debit equal to its total is a
 * coincidence - and proposing it would invite somebody to accept a match
 * for money that never left. A run already matched is excluded too: a
 * released run matching twice is the duplicate-payment case PF-180 exists
 * for, and the honest answer at this stage is that this debit is not the
 * same batch going out again.
 *
 * ## It never matches a receipt
 *
 * A payment run pays suppliers. An incoming credit equal to a run's total
 * is a coincidence with a very bad reading - "we were refunded exactly the
 * batch we sent" - and proposing it would put that reading in front of
 * somebody as though the engine believed it.
 */
final class PaymentRunPass implements MatchingPass
{
    public function key(): string
    {
        return 'payment_run';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        /*
         | Money out only.
         |
         | The sign is the question. A run is cash leaving, so a credit
         | cannot be one however well the figure agrees.
         */
        return $context->amount->isNegative();
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $window = (int) config('banking.matching.payment_run_window_days', 10);
        $amount = $context->amount->abs();
        $booking = $context->bookingDate();

        $runs = DB::connection('tenant')->select(<<<'SQL'
            SELECT r.id,
                   r.number,
                   r.pay_on,
                   r.net_total::text AS net_total,
                   r.instruction_count,
                   /*
                    | Already matched? Then this debit is not that batch.
                    |
                    | A released run matching twice is the duplicate-payment
                    | case, and the honest answer here is to say nothing
                    | rather than propose the same batch leaving again.
                    */
                   EXISTS (
                       SELECT 1 FROM matches m
                       WHERE m.matched_type = 'payment_run'
                         AND m.matched_id = r.id
                         AND m.reversed_at IS NULL
                   ) AS already_matched
            FROM payment_runs r
            WHERE r.entity_id = ?
              AND r.currency = ?
              AND r.state = 'released'
              AND r.pay_on BETWEEN ?::date - ?::int AND ?::date + ?::int
            ORDER BY r.pay_on DESC
        SQL, [
            $context->entityId,
            $amount->currency,
            $booking->toDateString(),
            $window,
            $booking->toDateString(),
            $window,
        ]);

        $candidates = [];

        foreach ($runs as $run) {
            if ($run->already_matched) {
                continue;
            }

            if (Money::of((string) $run->net_total, $amount->currency)->equals($amount)) {
                $candidates[] = $this->scoreTheBatch($context, $run, $amount, $window);
            }
        }

        return [...$candidates, ...$this->instructions($context, $runs, $amount, $window)];
    }

    /**
     * A debit equal to one transfer within a released run.
     *
     * @param  list<object>  $runs
     * @return list<Candidate>
     */
    private function instructions(MatchingContext $context, array $runs, Money $amount, int $window): array
    {
        $ids = array_map(static fn (object $run): string => (string) $run->id, $runs);

        if ($ids === []) {
            return [];
        }

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.run_id,
                   i.party_id,
                   i.sequence,
                   i.of,
                   i.amount::text AS amount,
                   r.number AS run_number,
                   r.pay_on,
                   p.legal_name AS party_name,
                   a.last_four,
                   /*
                    | How many transfers in the SAME run carry this figure.
                    |
                    | Two beneficiaries paid the same amount in one batch is
                    | ordinary - a standing charge, two identical orders -
                    | and neither is identified by the amount. The penalty
                    | belongs to both equally, so it is counted before
                    | either is scored.
                    */
                   (
                       SELECT count(*) FROM payment_run_instructions s
                       WHERE s.run_id = i.run_id AND s.amount = i.amount
                   ) AS competing,
                   EXISTS (
                       SELECT 1 FROM matches m
                       WHERE m.matched_type = 'payment_run_instruction'
                         AND m.matched_id = i.id
                         AND m.reversed_at IS NULL
                   ) AS already_matched
            FROM payment_run_instructions i
            JOIN payment_runs r ON r.id = i.run_id
            LEFT JOIN parties p ON p.id = i.party_id
            LEFT JOIN party_bank_accounts a ON a.id = i.party_bank_account_id
            WHERE i.run_id = ANY (?::char(26)[])
              AND i.amount = ?::numeric
            ORDER BY r.pay_on DESC, i.sequence
        SQL, [
            '{'.implode(',', $ids).'}',
            (string) $amount->amount,
        ]);

        $candidates = [];

        foreach ($rows as $row) {
            if ($row->already_matched) {
                continue;
            }

            $candidates[] = $this->scoreTheInstruction($context, $row, $amount, $window);
        }

        return $candidates;
    }

    private function scoreTheBatch(MatchingContext $context, object $run, Money $amount, int $window): Candidate
    {
        $days = $this->daysFrom($context, (string) $run->pay_on);

        $confidence = Confidence::for($this->key())
            ->because('batch_total_matches', 0.15, sprintf(
                'the debit equals the whole of run %s, which is a figure computed from its own lines '.
                'minutes before the file went out',
                (string) $run->number,
            ))
            ->when($days === 0, 'debited_on_the_pay_date', 0.05,
                'the bank debited it on the date the run asked to pay')
            ->when($days > 0 && $days <= 3, 'debited_within_days', 0.03, sprintf(
                'the bank debited it %d day%s from the pay date, which is ordinary settlement',
                $days,
                $days === 1 ? '' : 's',
            ))
            ->when($days > $window / 2, 'debited_well_after_the_pay_date', -0.10, sprintf(
                'the debit is %d days from the pay date, so the amount agreeing means less',
                $days,
            ))
            /*
             | A single-instruction run is the weak case, not the strong one.
             |
             | Its total IS its one transfer, so "the batch total matches"
             | and "a transfer matches" are the same fact counted once -
             | and that fact is no more deliberate than any other single
             | payment of that amount.
             */
            ->when((int) $run->instruction_count === 1, 'the_batch_is_one_transfer', -0.10,
                'the run holds a single instruction, so its total is not a batch figure - it is one '.
                'transfer, and a transfer of this amount could be other things');

        return new Candidate(
            pass: $this->key(),
            matchedType: 'payment_run',
            matchedId: (string) $run->id,
            label: sprintf('payment run %s', (string) $run->number),
            amount: $amount,
            confidence: $confidence->score(),
            reason: sprintf(
                'Run %s was released to pay %s on %s, and this debit is exactly that. The bank has '.
                'debited the batch as one line, which is one of the two ways a payment file comes '.
                'back. %s',
                (string) $run->number,
                (string) Money::of((string) $run->net_total, $amount->currency)->toPresentation(),
                (string) $run->pay_on,
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
        );
    }

    private function scoreTheInstruction(MatchingContext $context, object $row, Money $amount, int $window): Candidate
    {
        $days = $this->daysFrom($context, (string) $row->pay_on);
        $competing = (int) $row->competing;
        $party = $context->identifiedParty();
        $partyAgrees = $party !== null && $party === (string) $row->party_id;

        /*
         | The last four, which costs nothing and decrypts nothing.
         |
         | The stored account is encrypted and read only through the
         | disclosure service; `last_four` exists for exactly this kind of
         | comparison. It is weak evidence - four digits collide - so it
         | scores well below an identified party and is never the thing
         | that carries a match on its own.
         */
        $tail = $row->last_four === null ? null : (string) $row->last_four;
        $statementAccount = $context->transaction->counterparty_account;
        $tailAgrees = $tail !== null
            && $statementAccount !== null
            && $tail !== ''
            && str_ends_with(preg_replace('/\s+/', '', (string) $statementAccount) ?? '', $tail);

        $confidence = Confidence::for($this->key())
            ->because('instruction_matches', 0.05, sprintf(
                'the debit equals one transfer in run %s - part %d of %d',
                (string) $row->run_number,
                (int) $row->sequence,
                (int) $row->of,
            ))
            /*
             | Lower than the batch total, always.
             |
             | A batch figure is computed and all but unique; one transfer
             | within it is an amount other things on the statement may
             | also equal.
             */
            ->when(true, 'not_the_batch_total', -0.10,
                'this is one transfer rather than the batch, and a single amount is less distinctive '.
                'than a computed total')
            ->when($partyAgrees, 'beneficiary_is_identified', 0.15,
                'the account the bank paid is on file as this beneficiary, so who was paid is known '.
                'rather than inferred from the amount')
            ->when($party !== null && ! $partyAgrees, 'beneficiary_disagrees', -0.35,
                'the account the bank paid is on file as a different party, so this instruction is '.
                'not what this debit settled')
            ->when($party === null && $tailAgrees, 'account_tail_agrees', 0.05, sprintf(
                'the statement account ends %s, as does the beneficiary\'s - weak on its own, since '.
                'four digits collide',
                $tail ?? '',
            ))
            ->when($party === null && ! $tailAgrees, 'beneficiary_is_unknown', -0.15,
                'nothing identifies the account the bank paid, so only the amount connects this debit '.
                'to this instruction')
            ->when($days === 0, 'debited_on_the_pay_date', 0.05,
                'the bank debited it on the date the run asked to pay')
            ->when($days > $window / 2, 'debited_well_after_the_pay_date', -0.10, sprintf(
                'the debit is %d days from the pay date, so the amount agreeing means less',
                $days,
            ))
            ->when($competing > 1, 'several_instructions_at_this_amount', -0.25, sprintf(
                '%d transfers in the same run are for this amount, so the amount identifies none of them',
                $competing,
            ));

        return new Candidate(
            pass: $this->key(),
            matchedType: 'payment_run_instruction',
            matchedId: (string) $row->id,
            label: sprintf(
                '%s in run %s',
                $row->party_name === null ? 'a transfer' : 'the transfer to '.(string) $row->party_name,
                (string) $row->run_number,
            ),
            amount: $amount,
            confidence: $confidence->score(),
            reason: sprintf(
                'Run %s was released on %s with a transfer of %s to %s, and this debit is that '.
                'amount. The bank has debited each transfer separately, which is the other way a '.
                'payment file comes back. %s',
                (string) $row->run_number,
                (string) $row->pay_on,
                (string) $amount->toPresentation(),
                $row->party_name === null ? 'a beneficiary' : (string) $row->party_name,
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
            partyId: $row->party_id === null ? null : (string) $row->party_id,
        );
    }

    private function daysFrom(MatchingContext $context, string $payOn): int
    {
        return (int) abs(\Illuminate\Support\Carbon::parse($payOn)->diffInDays($context->bookingDate()));
    }
}
