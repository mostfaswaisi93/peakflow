<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Posting\Rules\SuspenseClearedRule;
use App\Tenant\Banking\Posting\Rules\UnmatchedToSuspenseRule;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Money the bank has and nobody can explain - PF-163.
 *
 * ## Why an unexplained item is posted rather than left
 *
 * The instinct is to leave it alone until somebody works out what it is.
 * That instinct costs a reconciliation a month: a line the bank has and the
 * ledger does not is an **unrecorded bank item**, so the bank account does
 * not agree with the bank, and every statement after it carries the same
 * difference until somebody re-derives the same conclusion about the same
 * item.
 *
 * Parking it posts the movement to the bank's GL account - which is a fact,
 * the bank really did move that money - and puts the uncertainty in a
 * control account named for it. The item is not less unexplained. It is
 * unexplained **in one place**, where it can be aged, reported, and where a
 * period close can refuse to walk past it.
 *
 * ## Age is measured from the bank's date, not ours
 *
 * From `booking_date`: how long the money has been sitting unexplained,
 * which is what somebody means by "that one is four months old". Measuring
 * from when we parked it would reset every time an item was re-parked, and
 * re-parking is what happens when half of it turns out to be explainable -
 * so the oldest, worst items would quietly report as the newest.
 *
 * ## Clearing is a reclassification, never an edit
 *
 * The parked entry stays as posted. That costs two entries where one would
 * do, and it buys the only thing suspense is for: that on the 14th nobody
 * knew what this was, and the ledger still says so after somebody found
 * out. An auditor asking how long an item was unexplained has no answer in
 * a ledger that rewrites its own history.
 *
 * ## And a period cannot close over it unsigned
 *
 * Registered as a closing check. A non-zero suspense balance stops the
 * close unless a controller signs it off, with a reason, in a record that
 * keeps the figure as it stood - because the thing being accepted is that
 * figure, and recomputing it later answers a different question.
 */
final class Suspense
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Splits $splits,
    ) {}

    /**
     * Park what is left of a bank line in suspense.
     *
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function park(BankTransaction $transaction, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'Parking a line in suspense needs a reason. It is the note somebody reads when they '.
                'come back to an item months later, and "unknown" is what they already knew.',
            );
        }

        $remainder = $this->splits->remainder($transaction);

        if ($remainder->isZero()) {
            throw MatchRefused::because(
                'Every part of this line is already applied to a document, so there is nothing '.
                'unexplained to park. Reverse a match on it first if it was applied wrongly.',
            );
        }

        $entity = Entity::query()->findOrFail((string) $transaction->entity_id);
        $account = $transaction->bankAccount;

        if ($account === null || $account->gl_account_id === null) {
            throw MatchRefused::because(
                'This line belongs to a bank account with no GL account behind it, so there is no '.
                'account to post the movement to. Suspense holds the other side of an entry, not '.
                'the whole of it.',
            );
        }

        $this->assertSuspenseExists((string) $transaction->entity_id);

        /*
         | Signed as the bank moved it.
         |
         | Money in debits the bank account and credits suspense; money out
         | does the opposite. Taking the absolute value here and deciding
         | the direction from something else is how a refund ends up
         | increasing the bank balance.
         */
        $signed = $transaction->amount < 0 ? $remainder->negated() : $remainder;

        return DB::connection('tenant')->transaction(function () use (
            $transaction,
            $entity,
            $account,
            $signed,
            $remainder,
            $userId,
            $reason,
        ): array {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: UnmatchedToSuspenseRule::KEY,
                event: [
                    'gl_account_id' => (string) $account->gl_account_id,
                    'amount' => (string) $signed->amount,
                    'narration' => sprintf('Unexplained bank line: %s', $this->label($transaction)),
                ],
                accountingDate: Carbon::parse($transaction->booking_date->toDateString()),
                journalCode: 'BNK',
                sourceType: 'bank_transaction',
                sourceId: (string) $transaction->getKey(),
                narration: $reason,
            );

            $matchId = (string) Str::ulid();

            DB::connection('tenant')->table('matches')->insert([
                'id' => $matchId,
                'entity_id' => (string) $transaction->entity_id,
                'bank_transaction_id' => (string) $transaction->getKey(),
                'booking_date' => $transaction->booking_date->toDateString(),
                /*
                 | `unidentified`, which is a type the table already has.
                 |
                 | Not a document: there is no document. The row exists so
                 | the line stops counting as an unrecorded bank item - it
                 | IS recorded now, in suspense - and so the parked amount
                 | is a row somebody can list rather than a balance they
                 | have to take apart.
                 */
                'matched_type' => 'unidentified',
                'matched_id' => null,
                'amount' => (string) $remainder->amount,
                'currency' => (string) $transaction->currency,
                'pass' => 'suspense',
                'confidence' => '1.0000',
                'reason' => sprintf('Parked in suspense pending identification. %s', $reason),
                'applied_automatically' => false,
                'applied_at' => now(),
                'applied_by' => $userId,
                'journal_entry_id' => (string) $entry->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'banking',
                action: 'suspense.parked',
                subjectType: 'bank_transaction',
                subjectId: (string) $transaction->getKey(),
                subjectLabel: $this->label($transaction),
                after: [
                    'amount' => (string) $remainder->amount,
                    'currency' => (string) $transaction->currency,
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $reason,
                entityId: (string) $transaction->entity_id,
            );

            return [
                'match_id' => $matchId,
                'journal_entry_id' => (string) $entry->getKey(),
                'amount' => $remainder,
                'why' => sprintf(
                    '%s parked in suspense. The bank account now agrees with the bank, and the item '.
                    'is unexplained in one place rather than in every reconciliation after it. It '.
                    'ages from %s and will stop this period closing until it is cleared or signed '.
                    'off.',
                    (string) $remainder->toPresentation(),
                    $transaction->booking_date->toDateString(),
                ),
            ];
        });
    }

    /**
     * Reclassify a parked item to where the money turned out to belong.
     *
     * Part of an item can be cleared: the rest stays parked and keeps its
     * age, because the age is the bank's booking date and not when we last
     * touched it.
     *
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function clear(
        string $matchId,
        string $accountId,
        string $userId,
        string $reason,
        ?string $amount = null,
        ?string $partyId = null,
        ?Carbon $on = null,
    ): array {
        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'Clearing suspense needs a reason. It is the sentence that says what the money '.
                'turned out to be, and it is the only part of this an auditor reads.',
            );
        }

        $parked = DB::connection('tenant')->table('matches')->where('id', $matchId)->first();

        if ($parked === null || $parked->matched_type !== 'unidentified') {
            throw MatchRefused::because(
                'That is not a parked suspense item. Clearing applies to money posted to suspense '.
                'pending identification, not to a match against a document - reverse that instead.',
            );
        }

        if ($parked->reversed_at !== null) {
            throw MatchRefused::because(
                'This item has already been taken out of suspense. Clearing it again would post a '.
                'second reclassification of money that is no longer there.',
            );
        }

        $currency = (string) $parked->currency;
        $whole = Money::of((string) $parked->amount, $currency);
        $clearing = $amount === null ? $whole : Money::of($amount, $currency)->abs();

        if ($clearing->isZero()) {
            throw MatchRefused::because('Clearing nothing is not a reclassification.');
        }

        if ($clearing->amount->isGreaterThan($whole->amount)) {
            throw MatchRefused::because(sprintf(
                'This item holds %s and you are clearing %s. Suspense cannot give back more than '.
                'was put into it, and a negative balance in a control account is a difference '.
                'nobody can explain by looking at it.',
                (string) $whole->toPresentation(),
                (string) $clearing->toPresentation(),
            ));
        }

        $transaction = BankTransaction::query()
            ->where('id', (string) $parked->bank_transaction_id)
            ->where('booking_date', (string) $parked->booking_date)
            ->firstOrFail();

        $entity = Entity::query()->findOrFail((string) $parked->entity_id);
        $signed = $transaction->amount < 0 ? $clearing->negated() : $clearing;
        $left = $whole->amount->minus($clearing->amount);

        return DB::connection('tenant')->transaction(function () use (
            $parked,
            $matchId,
            $transaction,
            $entity,
            $accountId,
            $signed,
            $clearing,
            $left,
            $currency,
            $userId,
            $reason,
            $partyId,
            $on,
        ): array {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: SuspenseClearedRule::KEY,
                event: [
                    'account_id' => $accountId,
                    'amount' => (string) $signed->amount,
                    'party_id' => $partyId,
                    'narration' => sprintf('Suspense cleared: %s', $this->label($transaction)),
                ],
                /*
                 | Posted when the decision was made, not when the money
                 | arrived.
                 |
                 | A reclassification is today's act. Backdating it into
                 | the period the item was parked in would quietly change
                 | a reported figure, and the Poster refuses a closed
                 | period anyway - so the date is today unless a caller
                 | names one, and the ledger decides whether it may.
                 */
                accountingDate: $on ?? now(),
                journalCode: 'BNK',
                sourceType: 'bank_transaction',
                sourceId: (string) $transaction->getKey(),
                narration: $reason,
            );

            /*
             | The parked row is reversed, not edited.
             |
             | Same rule as every other match, and for the stronger reason
             | here: the parked row is the evidence of how long the money
             | was unexplained, which is the one fact a suspense report
             | exists to produce.
             */
            DB::connection('tenant')->table('matches')->where('id', $matchId)->update([
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => sprintf('Cleared out of suspense. %s', $reason),
                'updated_at' => now(),
            ]);

            $remainingId = null;

            if (! $left->isZero()) {
                /*
                 | What is still unexplained is parked again, same age.
                 |
                 | The age comes from the line's booking date, so this row
                 | reports exactly as old as the one it replaces. Re-parking
                 | with a fresh clock would make the oldest items read as
                 | the newest the moment somebody made progress on them.
                 */
                $remainingId = (string) Str::ulid();

                DB::connection('tenant')->table('matches')->insert([
                    'id' => $remainingId,
                    'entity_id' => (string) $parked->entity_id,
                    'bank_transaction_id' => (string) $parked->bank_transaction_id,
                    'booking_date' => (string) $parked->booking_date,
                    'matched_type' => 'unidentified',
                    'matched_id' => null,
                    'amount' => (string) $left,
                    'currency' => $currency,
                    'pass' => 'suspense',
                    'confidence' => '1.0000',
                    'reason' => sprintf(
                        'Still in suspense after %s of this item was cleared. %s',
                        (string) $clearing->toPresentation(),
                        $reason,
                    ),
                    'applied_automatically' => false,
                    'applied_at' => now(),
                    'applied_by' => $userId,
                    'journal_entry_id' => $parked->journal_entry_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                category: 'banking',
                action: 'suspense.cleared',
                subjectType: 'bank_transaction',
                subjectId: (string) $transaction->getKey(),
                subjectLabel: $this->label($transaction),
                before: ['parked' => (string) $parked->amount, 'match_id' => $matchId],
                after: [
                    'cleared' => (string) $clearing->amount,
                    'to_account' => $accountId,
                    'still_parked' => (string) $left,
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $reason,
                entityId: (string) $parked->entity_id,
            );

            return [
                'journal_entry_id' => (string) $entry->getKey(),
                'cleared' => $clearing,
                'still_parked' => Money::of((string) $left, $currency),
                'remaining_match_id' => $remainingId,
                'why' => $left->isZero()
                    ? 'Reclassified out of suspense. The original posting is untouched, so the '.
                        'ledger still says this was unexplained when it was.'
                    : sprintf(
                        'Reclassified %s out of suspense. %s is still unexplained and keeps its '.
                        'original age, because an item does not get younger when somebody makes '.
                        'progress on it.',
                        (string) $clearing->toPresentation(),
                        (string) Money::of((string) $left, $currency)->toPresentation(),
                    ),
            ];
        });
    }

    /**
     * What is sitting in suspense, by age.
     *
     * @return array<string, mixed>
     */
    public function ageing(string $entityId, string $currency, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT m.id,
                   m.amount::text AS amount,
                   m.currency,
                   m.booking_date,
                   m.reason,
                   (?::date - m.booking_date) AS age_days,
                   /*
                    | The direction the bank moved it.
                    |
                    | `matches.amount` is a magnitude; whether this item
                    | credited or debited suspense is a property of the
                    | line it came from, and without it the control
                    | account cannot be reconciled against the list.
                    */
                   sign(t.amount) AS direction,
                   t.bank_reference,
                   t.counterparty_name
            FROM matches m
            JOIN bank_transactions t
              ON t.id = m.bank_transaction_id
             AND t.booking_date = m.booking_date
            WHERE m.entity_id = ?
              AND m.matched_type = 'unidentified'
              AND m.reversed_at IS NULL
              AND m.booking_date <= ?::date
            ORDER BY m.booking_date, m.id
        SQL, [$asOf, $entityId, $asOf]);

        /*
         | The buckets a credit controller already thinks in.
         |
         | Not configurable, deliberately: a suspense report whose buckets
         | differ between two entities cannot be added up, and the whole
         | value of this report is seeing one organisation's unexplained
         | money in one table.
         */
        $buckets = [
            '0-30' => Money::zero($currency),
            '31-60' => Money::zero($currency),
            '61-90' => Money::zero($currency),
            '90+' => Money::zero($currency),
        ];

        $counts = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $total = Money::zero($currency);
        $signed = Money::zero($currency);
        $items = [];
        $oldest = 0;

        foreach ($rows as $row) {
            $age = (int) $row->age_days;
            $bucket = match (true) {
                $age <= 30 => '0-30',
                $age <= 60 => '31-60',
                $age <= 90 => '61-90',
                default => '90+',
            };

            $amount = Money::of((string) $row->amount, (string) $row->currency)->abs();

            $buckets[$bucket] = $buckets[$bucket]->plus($amount);
            $counts[$bucket]++;
            $total = $total->plus($amount);
            $oldest = max($oldest, $age);

            /*
             | Two totals, because they answer two questions.
             |
             | `total` is how much unexplained money there is, which is a
             | magnitude: 450 in and 120 out is 570 of unexplained money
             | and somebody has to look at both. `signed` is what the
             | control account should hold, and netting is exactly what a
             | balance does.
             */
            $signed = (int) $row->direction < 0
                ? $signed->minus($amount)
                : $signed->plus($amount);

            $items[] = [
                'match_id' => (string) $row->id,
                'amount' => (string) $row->amount,
                'booking_date' => (string) $row->booking_date,
                'age_days' => $age,
                'bucket' => $bucket,
                'bank_reference' => $row->bank_reference,
                'counterparty_name' => $row->counterparty_name,
                'reason' => (string) $row->reason,
            ];
        }

        return [
            'as_of' => $asOf,
            'total' => $total,
            'signed' => $signed,
            'items' => $items,
            'buckets' => $buckets,
            'counts' => $counts,
            'oldest_days' => $oldest,
            'why' => $items === []
                ? 'Nothing is in suspense.'
                : sprintf(
                    '%s across %d item%s, the oldest %d days old. %s',
                    (string) $total->toPresentation(),
                    \count($items),
                    \count($items) === 1 ? '' : 's',
                    $oldest,
                    $oldest > 90
                        ? 'An item over 90 days old is one nobody is going to identify by thinking '.
                            'harder - it needs somebody to ask the bank or the customer.'
                        : 'Clearing these is a reclassification, not a write-off.',
                ),
        ];
    }

    /**
     * The suspense control account's balance, from the ledger itself.
     *
     * Read from `journal_lines` rather than from the parked rows, because
     * those are two different facts and a report that conflates them cannot
     * notice when they disagree - which is exactly what {@see reconciles()}
     * is for.
     */
    public function balance(string $entityId, string $currency): Money
    {
        $accountId = AccountRole::query()
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::Suspense->value)
            ->value('account_id');

        if ($accountId === null) {
            return Money::zero($currency);
        }

        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(l.functional_amount), 0)::text AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.entity_id = ?
              AND l.account_id = ?
              /*
               | A DRAFT is not a balance - PF-146.
               |
               | `TrialBalance` and `AgingReport::controlAccountBalance()`
               | both filter this and say why; this did not, and neither did
               | `Suspense::balance()`. So two of the four definitions of a
               | control-account balance counted rows the other two did not -
               | and these two produce the figure frozen into a
               | reconciliation document an auditor re-performs.
               |
               | Latent today, because nothing in the application creates a
               | draft entry (PF-188) and a posted one cannot go back. The
               | day somebody builds the screen that saves a draft, it would
               | have arrived as a reconciliation that will not tie with the
               | difference explained by nothing.
               |
               | `reversed` is included with `posted`: a reversal is itself
               | a posted entry carrying the opposite lines, so both sides
               | are already in the sum.
               */
              AND e.status IN ('posted', 'reversed')
        SQL, [$entityId, (string) $accountId]);

        return Money::of((string) ($row->balance ?? '0'), $currency);
    }

    /**
     * Whether the parked items account for the control account's balance.
     *
     * The assertion `ledger:verify` makes. A suspense balance that no list
     * of items adds up to is the failure this whole design is meant to
     * prevent: a control account becomes a number somebody has to take
     * apart by reading the ledger, which is how an item stays in suspense
     * for three years.
     *
     * @return array{agrees: bool, balance: Money, parked: Money, why: string}
     */
    public function reconciles(string $entityId, string $currency): array
    {
        $balance = $this->balance($entityId, $currency);
        $ageing = $this->ageing($entityId, $currency);

        /*
         | The control account and the items cancel.
         |
         | Not a comparison of magnitudes: a receipt credits suspense and
         | a payment debits it, so 450 in and 120 out leave 330 in the
         | account against 570 of unexplained money. Comparing those two
         | figures reports every entity with movements both ways as
         | broken - which is what the first version of this did, and what
         | a check that only ever parked receipts would never have shown.
         |
         | The item's own sign is the direction the bank moved it, and
         | suspense took the opposite - so the account and the signed list
         | add to nothing when every riyal is accounted for.
         */
        $agrees = $balance->plus($ageing['signed'])->isZero();

        return [
            'agrees' => $agrees,
            'balance' => $balance,
            'parked' => $ageing['signed'],
            'why' => $agrees
                ? sprintf(
                    'The suspense account holds %s and the parked items account for all of it, so '.
                    'every figure in there traces to a bank line.',
                    (string) $balance->abs()->toPresentation(),
                )
                : sprintf(
                    'The suspense account holds %s and the parked bank items account for %s. The '.
                    'difference is money in a control account that no list accounts for - '.
                    'something posted to suspense without going through parking, which is how a '.
                    'suspense balance becomes a number nobody can take apart.',
                    (string) $balance->abs()->toPresentation(),
                    (string) $ageing['signed']->abs()->toPresentation(),
                ),
        ];
    }

    /** Whether this period already carries a sign-off. */
    public function isSignedOff(string $entityId, string $periodId): bool
    {
        return DB::connection('tenant')->table('suspense_signoffs')
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->exists();
    }

    private function assertSuspenseExists(string $entityId): void
    {
        $accountId = AccountRole::query()
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::Suspense->value)
            ->value('account_id');

        if ($accountId === null) {
            throw MatchRefused::because(
                'This entity has no suspense control account, so there is nowhere to park an '.
                'unexplained item. Assign the suspense role to an account in the chart first - '.
                'parking into whatever account seems closest is how unexplained money becomes '.
                'indistinguishable from explained money.',
            );
        }
    }

    private function label(BankTransaction $transaction): string
    {
        return sprintf(
            '%s %s on %s%s',
            (string) $transaction->currency,
            (string) $transaction->amount,
            $transaction->booking_date->toDateString(),
            $transaction->counterparty_name === null ? '' : ' from '.$transaction->counterparty_name,
        );
    }
}
