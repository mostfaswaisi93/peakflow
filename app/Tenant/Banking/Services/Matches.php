<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\MatchingEngine;
use App\Tenant\Banking\Matching\OpenDocuments;
use App\Tenant\Banking\Matching\PartyIdentifiers;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Models\MatchProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applying a match, and taking one back - PF-156, PF-161.
 *
 * ## Applied automatically is a property of the match, not a different table
 *
 * `applied_automatically` is a column, so "show me everything the engine did
 * by itself this month" is a filter rather than a reconstruction. PF-161
 * asks for a review list over a configurable window, and that window is a
 * query over this column - which also means an automatic match and a
 * confirmed one are the same shape, individually reversible in the same way.
 *
 * ## Reversal keeps the original
 *
 * "This was matched to invoice 4471 and somebody changed it" is the question
 * an auditor asks, and a deleted row cannot answer it. So a reversal stamps
 * the match rather than removing it, and the document's outstanding figure -
 * which is recomputed from the applied matches - moves back by itself.
 *
 * ## Correcting a proposal teaches the engine
 *
 * Accepting or correcting stores the counterparty's account against the
 * party, so the same payer is identified exactly next time. The engine
 * learns from the decision, never from its own guess: an identifier learned
 * from an unreviewed automatic match is the engine teaching itself, and two
 * confident mistakes later nobody can say where the belief came from.
 */
final class Matches
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly MatchingEngine $engine,
        private readonly PartyIdentifiers $parties = new PartyIdentifiers(),
        private readonly OpenDocuments $documents = new OpenDocuments(),
    ) {}

    /**
     * Accept a proposal, and learn from it.
     *
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function accept(MatchProposal $proposal, string $userId, bool $automatically = false): array
    {
        if ($proposal->isDecided()) {
            throw MatchRefused::because(sprintf(
                'This proposal was already %s on %s. Deciding it twice would make the accuracy '.
                'report count a decision nobody made, and would leave two matches against one bank '.
                'line with no way to tell which is current.',
                (string) $proposal->status,
                $proposal->decided_at?->toDateTimeString() ?? 'an earlier date',
            ));
        }

        if ($proposal->matched_type === 'unidentified') {
            throw MatchRefused::because(
                'This proposal says who, not what. It names a party the counterparty resembles and '.
                'no document, so accepting it would settle nothing - confirm the party and the '.
                'engine will identify them exactly next time, then match the transaction to a '.
                'document.',
            );
        }

        $transaction = $this->transactionOf($proposal);

        return DB::connection('tenant')->transaction(function () use ($proposal, $transaction, $userId, $automatically): array {
            $this->assertItFits($proposal, $transaction);

            $matchId = (string) Str::ulid();

            DB::connection('tenant')->table('matches')->insert([
                'id' => $matchId,
                'entity_id' => (string) $proposal->entity_id,
                'bank_transaction_id' => (string) $proposal->bank_transaction_id,
                'booking_date' => $proposal->booking_date->toDateString(),
                'matched_type' => (string) $proposal->matched_type,
                'matched_id' => $proposal->matched_id,
                'amount' => (string) $proposal->amount,
                'currency' => (string) $proposal->currency,
                'pass' => (string) $proposal->pass,
                'confidence' => (string) $proposal->confidence,
                'reason' => (string) $proposal->reason,
                'applied_automatically' => $automatically,
                'applied_at' => now(),
                'applied_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $proposal->update([
                'status' => 'accepted',
                'decided_at' => now(),
                'decided_by' => $userId,
                'match_id' => $matchId,
            ]);

            /*
             | What happens to the other proposals depends on the remainder.
             |
             | A fully applied line: they are **rejected**. The person
             | chose one of them and that is a decision about the rest - a
             | transaction with an accepted match and three open proposals
             | reads as unfinished work, and the accuracy report would
             | count them as neither accepted nor rejected, which is how a
             | pass comes to look worse than it is.
             |
             | A part-applied line - PF-162: they are **superseded**. They
             | were never judged wrong; they answered "what is this 10,000"
             | and the question is now "what is the remaining 6,000". The
             | engine re-proposes against what is left, and calling the old
             | set rejected would charge six passes with being wrong about
             | a question nobody asked them.
             */
            $remainder = $this->remainderOf($transaction);
            $settled = $remainder->isZero();

            MatchProposal::query()
                ->where('bank_transaction_id', (string) $proposal->bank_transaction_id)
                ->where('id', '!=', (string) $proposal->getKey())
                ->where('status', 'proposed')
                ->update([
                    'status' => $settled ? 'rejected' : 'superseded',
                    'decided_at' => now(),
                    'decided_by' => $userId,
                ]);

            $learned = $automatically
                ? 0
                : $this->learnFrom($proposal, $transaction, $userId);

            $this->audit->record(
                category: 'banking',
                action: $automatically ? 'match.applied_automatically' : 'match.accepted',
                subjectType: 'bank_transaction',
                subjectId: (string) $transaction->getKey(),
                subjectLabel: $this->label($transaction),
                after: [
                    'matched_type' => (string) $proposal->matched_type,
                    'matched_id' => $proposal->matched_id,
                    'amount' => (string) $proposal->amount,
                    'pass' => (string) $proposal->pass,
                    'confidence' => (string) $proposal->confidence,
                    'identifiers_learned' => $learned,
                ],
                reason: (string) $proposal->reason,
                entityId: (string) $proposal->entity_id,
            );

            /*
             | A remainder is reported, not left for somebody to notice.
             |
             | A part-applied line looks matched on every screen that asks
             | "is there a match" rather than "is it explained". Saying so
             | at the moment of applying is the cheapest place to say it.
             */
            $leftToExplain = $settled
                ? ''
                : sprintf(
                    ' %s of this line is still unexplained, and it stays on the unreconciled list '.
                    'until it is - the engine will propose against what is left, not against the '.
                    'whole line.',
                    (string) $remainder->toPresentation(),
                );

            return [
                'match_id' => $matchId,
                'identifiers_learned' => $learned,
                'remainder' => $remainder,
                'why' => $automatically
                    ? sprintf(
                        'Applied automatically at %s, at or above the threshold. It is on the review '.
                        'list and can be reversed on its own.%s',
                        (string) $proposal->confidence,
                        $leftToExplain,
                    )
                    : sprintf(
                        'Matched. %s%s',
                        $learned === 0
                            ? 'Nothing new was learned about the counterparty.'
                            : sprintf(
                                '%d identifier%s stored against the party, so the next transfer from '.
                                'them is identified exactly rather than guessed at.',
                                $learned,
                                $learned === 1 ? '' : 's',
                            ),
                        $leftToExplain,
                    ),
            ];
        });
    }

    /**
     * Reject a proposal, which is information rather than a non-event.
     *
     * @throws MatchRefused
     */
    public function reject(MatchProposal $proposal, string $userId, string $reason): MatchProposal
    {
        if ($proposal->isDecided()) {
            throw MatchRefused::because('This proposal was already decided.');
        }

        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'Rejecting a proposal needs a reason. The accuracy report reads these: a pass whose '.
                'proposals are rejected for the same reason every week is a pass with a fixable '.
                'problem, and "rejected" on its own says only that somebody disagreed.',
            );
        }

        $proposal->update([
            'status' => 'rejected',
            'decided_at' => now(),
            'decided_by' => $userId,
        ]);

        $this->audit->record(
            category: 'banking',
            action: 'match.rejected',
            subjectType: 'bank_transaction',
            subjectId: (string) $proposal->bank_transaction_id,
            subjectLabel: sprintf('%s at %s', (string) $proposal->pass, (string) $proposal->confidence),
            reason: trim($reason),
            entityId: (string) $proposal->entity_id,
        );

        return $proposal->refresh();
    }

    /**
     * Reverse an applied match.
     *
     * @throws MatchRefused
     */
    public function reverse(string $matchId, string $userId, string $reason): void
    {
        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'Reversing a match needs a reason. "This was matched to invoice 4471 and somebody '.
                'changed it" is the question an auditor asks, and the reason is the only part of '.
                'the answer that is not already in the row.',
            );
        }

        $match = DB::connection('tenant')->table('matches')->where('id', $matchId)->first();

        if ($match === null) {
            throw MatchRefused::because('There is no such match.');
        }

        if ($match->reversed_at !== null) {
            throw MatchRefused::because('This match was already reversed.');
        }

        DB::connection('tenant')->table('matches')->where('id', $matchId)->update([
            'reversed_at' => now(),
            'reversed_by' => $userId,
            'reversal_reason' => trim($reason),
            'updated_at' => now(),
        ]);

        /*
         | The proposal goes back to being a proposal that was corrected.
         |
         | Not back to `proposed`: it was decided, and the decision turned
         | out wrong. `corrected` is what the accuracy report counts, and
         | counting it as never-decided would hide exactly the outcome the
         | report exists to show.
         */
        MatchProposal::query()
            ->where('match_id', $matchId)
            ->update(['status' => 'corrected', 'decided_at' => now(), 'decided_by' => $userId]);

        $this->audit->record(
            category: 'banking',
            action: 'match.reversed',
            subjectType: 'bank_transaction',
            subjectId: (string) $match->bank_transaction_id,
            subjectLabel: sprintf('%s %s', (string) $match->matched_type, (string) $match->matched_id),
            before: ['matched_type' => (string) $match->matched_type, 'amount' => (string) $match->amount],
            after: ['reversed' => true],
            reason: trim($reason),
            entityId: (string) $match->entity_id,
        );
    }

    /**
     * Run the engine and apply anything certain enough.
     *
     * @return array<string, mixed>
     */
    public function settle(BankTransaction $transaction, string $userId): array
    {
        $considered = $this->engine->consider($transaction);
        $proposals = $this->engine->propose($transaction);
        $appliable = $considered['auto_appliable'];

        if ($appliable === null) {
            return [
                'applied' => false,
                'proposals' => \count($proposals),
                'ambiguous' => $considered['ambiguous'],
                'why' => $considered['why'],
            ];
        }

        $proposal = $this->proposalFor($proposals, $appliable);

        if ($proposal === null) {
            return [
                'applied' => false,
                'proposals' => \count($proposals),
                'ambiguous' => $considered['ambiguous'],
                'why' => $considered['why'],
            ];
        }

        $result = $this->accept($proposal, $userId, automatically: true);

        return [
            'applied' => true,
            'match_id' => $result['match_id'],
            'proposals' => \count($proposals),
            'ambiguous' => false,
            'why' => $considered['why'],
        ];
    }

    /**
     * Per-pass accuracy, derived from the proposals themselves - PF-161.
     *
     * Derived rather than counted, because a counter drifts the first time a
     * match is reversed or two tabs decide the same proposal - and a
     * drifting accuracy report is worse than none: a degrading pass is
     * exactly what it exists to show.
     *
     * @return array<string, mixed>
     */
    public function accuracy(string $entityId, ?string $since = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT pass,
                   count(*) AS proposed,
                   count(*) FILTER (WHERE status = 'accepted') AS accepted,
                   count(*) FILTER (WHERE status = 'corrected') AS corrected,
                   count(*) FILTER (WHERE status = 'rejected') AS rejected,
                   count(*) FILTER (WHERE status = 'proposed') AS undecided,
                   round(avg(confidence), 4) AS average_confidence
            FROM match_proposals
            WHERE entity_id = ?
              AND (?::timestamp IS NULL OR created_at >= ?::timestamp)
            GROUP BY pass
            ORDER BY pass
        SQL, [$entityId, $since, $since]);

        $passes = [];

        foreach ($rows as $row) {
            $decided = (int) $row->accepted + (int) $row->corrected + (int) $row->rejected;

            $passes[] = [
                'pass' => (string) $row->pass,
                'proposed' => (int) $row->proposed,
                'accepted' => (int) $row->accepted,
                'corrected' => (int) $row->corrected,
                'rejected' => (int) $row->rejected,
                'undecided' => (int) $row->undecided,
                'average_confidence' => (string) $row->average_confidence,
                /*
                 | Out of DECIDED, not out of proposed.
                 |
                 | A pass whose proposals are mostly still open is not
                 | accurate or inaccurate yet, and dividing by the total
                 | would make a new pass look terrible on its first day
                 | and a stale one look excellent.
                 */
                'accuracy' => $decided === 0
                    ? null
                    : round((int) $row->accepted / $decided, 4),
                'decided' => $decided,
            ];
        }

        return [
            'entity_id' => $entityId,
            'since' => $since,
            'passes' => $passes,
            'why' => $passes === []
                ? 'Nothing has been proposed yet.'
                : 'Accuracy is accepted out of decided, not out of proposed: a pass whose proposals '.
                  'are mostly still open is neither accurate nor inaccurate yet.',
        ];
    }

    /**
     * Everything the engine applied by itself, for review - PF-161.
     *
     * @return array<string, mixed>
     */
    public function appliedAutomatically(string $entityId, int $windowDays = 30): array
    {
        $since = now()->subDays($windowDays);

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT m.id, m.bank_transaction_id, m.matched_type, m.matched_id,
                   m.amount, m.currency, m.pass, m.confidence, m.reason,
                   m.applied_at, m.reversed_at
            FROM matches m
            WHERE m.entity_id = ?
              AND m.applied_automatically = true
              AND m.applied_at >= ?
            ORDER BY m.applied_at DESC
        SQL, [$entityId, $since->toDateTimeString()]);

        return [
            'window_days' => $windowDays,
            'since' => $since->toDateString(),
            'matches' => array_map(static fn (object $row): array => (array) $row, $rows),
            'why' => sprintf(
                'Everything applied without a person looking, for the last %d days. Each is '.
                'reversible on its own - a match applied to the wrong document is corrected by '.
                'reversing that one match, not by unreconciling a month.',
                $windowDays,
            ),
        ];
    }

    /**
     * What is left of a line to explain, after every live match on it.
     *
     * Read here rather than through {@see Splits}, which depends on this
     * service: the figure is one SQL sum and sharing it the other way
     * round would make a cycle out of a subtraction.
     */
    private function remainderOf(BankTransaction $transaction): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(abs(amount)), 0)::text AS applied
            FROM matches
            WHERE bank_transaction_id = ?
              AND reversed_at IS NULL
        SQL, [(string) $transaction->getKey()]);

        $line = Money::of((string) $transaction->amount, (string) $transaction->currency)->abs();
        $left = $line->amount->minus((string) ($row->applied ?? '0'));

        return Money::of((string) ($left->isNegative() ? '0' : $left), (string) $transaction->currency);
    }

    /**
     * @throws MatchRefused
     */
    private function assertItFits(MatchProposal $proposal, BankTransaction $transaction): void
    {
        if ($proposal->matched_id === null) {
            return;
        }

        $currency = (string) $proposal->currency;
        $amount = Money::of((string) $proposal->amount, $currency)->abs();

        if (\in_array((string) $proposal->matched_type, ['payment_run', 'payment_run_instruction'], true)) {
            $this->assertTheRunFits($proposal, $amount);

            return;
        }

        $open = $proposal->matched_type === 'invoice'
            ? $this->documents->receivables((string) $proposal->entity_id, $currency)
            : $this->documents->payables((string) $proposal->entity_id, $currency);

        foreach ($open as $document) {
            if ($document->id !== (string) $proposal->matched_id) {
                continue;
            }

            /*
             | Checked against what is outstanding NOW, not when proposed.
             |
             | A proposal made this morning may have been settled by another
             | transaction since. Applying it anyway would over-settle the
             | document, and the outstanding figure - recomputed from the
             | matches - would go negative with nothing saying why.
             */
            if ($amount->amount->isGreaterThan($document->outstanding->abs()->amount)) {
                throw MatchRefused::because(sprintf(
                    'This proposal is for %s and %s now has only %s outstanding - something else '.
                    'was matched to it since the proposal was made. Re-run the matching for this '.
                    'transaction rather than applying a figure the document can no longer absorb.',
                    (string) $amount->toPresentation(),
                    $document->label(),
                    (string) $document->outstanding->abs()->toPresentation(),
                ));
            }

            return;
        }

        throw MatchRefused::because(sprintf(
            'The document this proposal names is no longer open, so there is nothing for %s to '.
            'settle. That is usually another transaction having been matched to it since.',
            (string) $amount->toPresentation(),
        ));
    }

    /**
     * A payment-run match fits if the run still says that figure, once.
     *
     * The document path asks whether a receivable can still absorb the
     * amount. A payment run has no outstanding balance to absorb
     * anything: it is an instruction that was sent, so the questions are
     * different ones - is it still released, is the figure still the one
     * it was proposed for, and has this debit already been accounted for
     * by another line.
     *
     * The last is the important one. A released run matching two bank
     * lines is a duplicate payment, and the pass declines to propose one
     * it has already matched - but a pass cannot defend against two
     * proposals accepted in the same second, and this is the moment that
     * can.
     *
     * @throws MatchRefused
     */
    private function assertTheRunFits(MatchProposal $proposal, Money $amount): void
    {
        $id = (string) $proposal->matched_id;
        $isBatch = (string) $proposal->matched_type === 'payment_run';

        $row = $isBatch
            ? DB::connection('tenant')->selectOne(
                'SELECT r.number AS label, r.state, r.net_total::text AS figure '.
                'FROM payment_runs r WHERE r.id = ? AND r.entity_id = ?',
                [$id, (string) $proposal->entity_id],
            )
            : DB::connection('tenant')->selectOne(
                'SELECT r.number AS label, r.state, i.amount::text AS figure '.
                'FROM payment_run_instructions i JOIN payment_runs r ON r.id = i.run_id '.
                'WHERE i.id = ? AND i.entity_id = ?',
                [$id, (string) $proposal->entity_id],
            );

        if ($row === null) {
            throw MatchRefused::because(
                $isBatch
                    ? 'The payment run this proposal names no longer exists in this entity.'
                    : 'The transfer this proposal names no longer exists in this entity.',
            );
        }

        if ((string) $row->state !== 'released') {
            throw MatchRefused::because(sprintf(
                'Run %s is %s, not released, so no money has left for this debit to be. A debit '.
                'equal to a prepared run\'s total is a coincidence, and accepting it would record a '.
                'payment the business has not made.',
                (string) $row->label,
                (string) $row->state,
            ));
        }

        if (! Money::of((string) $row->figure, $amount->currency)->equals($amount)) {
            throw MatchRefused::because(sprintf(
                'This proposal is for %s and run %s now says %s. A released run is frozen, so that '.
                'is the proposal having been made against a different row - re-run the matching for '.
                'this transaction.',
                (string) $amount->toPresentation(),
                (string) $row->label,
                (string) Money::of((string) $row->figure, $amount->currency)->toPresentation(),
            ));
        }

        $already = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT m.bank_transaction_id::text AS line
            FROM matches m
            WHERE m.matched_type = ?
              AND m.matched_id = ?
              AND m.reversed_at IS NULL
            LIMIT 1
        SQL, [(string) $proposal->matched_type, $id]);

        if ($already !== null && (string) $already->line !== (string) $proposal->bank_transaction_id) {
            throw MatchRefused::because(sprintf(
                '%s is already matched to another bank line, so accepting this one would say the '.
                'same money left twice. If this debit is a second payment, it needs its own run - '.
                'and if the first match was wrong, reverse that one.',
                $isBatch ? sprintf('Run %s', (string) $row->label) : sprintf('That transfer in run %s', (string) $row->label),
            ));
        }
    }

    private function learnFrom(MatchProposal $proposal, BankTransaction $transaction, string $userId): int
    {
        $partyId = $this->partyOf($proposal);

        if ($partyId === null) {
            return 0;
        }

        return $this->parties->learn(
            entityId: (string) $proposal->entity_id,
            partyId: $partyId,
            account: $transaction->counterparty_account,
            bic: $transaction->counterparty_bic,
            counterpartyName: $transaction->counterparty_name,
            userId: $userId,
            transactionId: (string) $transaction->getKey(),
        );
    }

    private function partyOf(MatchProposal $proposal): ?string
    {
        if ($proposal->matched_id === null) {
            return null;
        }

        /*
         | A batch debit names no single party, and an instruction does.
         |
         | Learning the counterparty account from an accepted instruction
         | match is how pass 3 gets better for suppliers: the account the
         | bank paid becomes the identifier that identifies them next
         | time. A batch line is several beneficiaries at once, so there
         | is nothing to learn from it and pretending otherwise would
         | teach the engine that one supplier owns the batch account.
         */
        $table = match ((string) $proposal->matched_type) {
            'invoice' => 'invoices',
            'payment_run_instruction' => 'payment_run_instructions',
            'payment_run' => null,
            default => 'bills',
        };

        if ($table === null) {
            return null;
        }

        $party = DB::connection('tenant')->table($table)
            ->where('id', (string) $proposal->matched_id)
            ->value('party_id');

        return $party === null ? null : (string) $party;
    }

    private function transactionOf(MatchProposal $proposal): BankTransaction
    {
        $transaction = BankTransaction::query()
            ->whereKey((string) $proposal->bank_transaction_id)
            ->first();

        if ($transaction === null) {
            throw MatchRefused::because('The transaction this proposal is about no longer exists.');
        }

        return $transaction;
    }

    /** @param list<MatchProposal> $proposals */
    private function proposalFor(array $proposals, Candidate $candidate): ?MatchProposal
    {
        foreach ($proposals as $proposal) {
            if ((string) $proposal->matched_type === $candidate->matchedType
                && (string) $proposal->matched_id === (string) $candidate->matchedId
                && (string) $proposal->pass === $candidate->pass) {
                return $proposal;
            }
        }

        return null;
    }

    private function label(BankTransaction $transaction): string
    {
        return sprintf(
            '%s %s on %s',
            (string) $transaction->currency,
            (string) $transaction->amount,
            $transaction->booking_date->toDateString(),
        );
    }
}
