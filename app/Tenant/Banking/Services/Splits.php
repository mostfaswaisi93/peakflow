<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Banking\Matching\OpenDocument;
use App\Tenant\Banking\Matching\OpenDocuments;
use App\Tenant\Banking\Models\BankTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One line across many documents, and one document across many lines - PF-162.
 *
 * ## Both directions happen constantly
 *
 * A customer pays four invoices with one transfer. The same customer pays
 * one invoice in three instalments. A system that models matching as a link
 * between one transaction and one document forces the first into a suspense
 * account and the second into a write-off, and both of those are a person
 * doing arithmetic in a spreadsheet to work around their own accounting
 * system.
 *
 * The schema carried both directions from the start. What this adds is the
 * operation: allocating one line across several documents **at once**, with
 * the remainder left open and visible on both sides.
 *
 * ## A split is one operation or it is a mess
 *
 * Three allocations applied one at a time is three states the system can be
 * found in, two of which are wrong. Worse, the second can fail - the invoice
 * was settled by another transaction five seconds ago - and a person is left
 * holding a line that is a third applied with no record of what the whole
 * was meant to be.
 *
 * So the allocations are validated as a set, then written in one database
 * transaction. Either the line is split the way somebody intended or nothing
 * happened and the refusal says which allocation could not be made.
 *
 * ## The remainder is the point, not a leftover
 *
 * A 10,000 receipt against a 4,000 invoice leaves 6,000 that is **not
 * explained**. That remainder is visible on the line, it keeps the line on
 * the unreconciled list, and the engine re-proposes against it rather than
 * against the original 10,000 - because what is left to explain is 6,000,
 * and proposing documents that add to 10,000 is answering a question that
 * has moved on.
 *
 * On the document side the same figure arrives by a different route: a
 * document's outstanding is recomputed from the matches applied to it, so a
 * part-settled invoice shows what is left without anything having to update
 * it.
 *
 * ## Unsplitting is a reversal, not a delete
 *
 * Every match the split made is reversed, with the reason recorded against
 * each one. The rows stay. "It was split four ways and somebody undid it"
 * is a question an auditor asks, and a table that deletes cannot answer it.
 */
final class Splits
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Matches $matches,
        private readonly OpenDocuments $documents = new OpenDocuments(),
    ) {}

    /**
     * What is applied to a bank line, from every live match on it.
     */
    public function applied(BankTransaction $transaction): Money
    {
        /*
         | Summed in SQL and read as a string.
         |
         | A query-builder aggregate comes back through a numeric cast on
         | some drivers, and a money figure that has been through a float
         | is a money figure that has lost the argument before it starts.
         | `numeric` out of PostgreSQL arrives as a string, and it stays
         | one until `Money` makes it a decimal.
         */
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(abs(amount)), 0)::text AS applied
            FROM matches
            WHERE bank_transaction_id = ?
              AND reversed_at IS NULL
        SQL, [(string) $transaction->getKey()]);

        return Money::of((string) ($row->applied ?? '0'), (string) $transaction->currency);
    }

    /**
     * What is left of a bank line to explain.
     *
     * Always positive or zero, and in the line's own currency. The sign of
     * the line says which direction the money went; the remainder is how
     * much of it has no document behind it yet, which is the same question
     * whichever way it moved.
     */
    public function remainder(BankTransaction $transaction): Money
    {
        $line = Money::of((string) $transaction->amount, (string) $transaction->currency)->abs();
        $applied = $this->applied($transaction);

        $left = $line->amount->minus($applied->amount);

        /*
         | Clamped at zero rather than reported negative.
         |
         | A negative remainder means the line is over-applied, which the
         | database refuses - so if one is ever computed here it is a
         | symptom of something that bypassed that refusal, and a negative
         | "left to explain" on a screen reads as money owed back.
         */
        return Money::of((string) ($left->isNegative() ? '0' : $left), (string) $transaction->currency);
    }

    public function isFullyApplied(BankTransaction $transaction): bool
    {
        return $this->remainder($transaction)->amount->isZero();
    }

    /**
     * Split one bank line across several documents, as one operation.
     *
     * @param  list<array{type: string, id: string, amount: string}>  $allocations
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function split(
        BankTransaction $transaction,
        array $allocations,
        string $userId,
        string $reason,
    ): array {
        if (\count($allocations) < 2) {
            throw MatchRefused::because(
                'A split needs at least two allocations. One allocation is an ordinary match, and '.
                'calling it a split would put a single decision through a path built to keep '.
                'several of them consistent with each other.',
            );
        }

        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'A split needs a reason. Somebody looking at four matches against one line a year '.
                'from now is asking why it was divided that way, and "split" is not an answer.',
            );
        }

        $currency = (string) $transaction->currency;
        $prepared = $this->prepare($transaction, $allocations, $currency);

        $total = $prepared['total'];
        $remainder = $this->remainder($transaction);

        if ($total->amount->isGreaterThan($remainder->amount)) {
            throw MatchRefused::because(sprintf(
                'These allocations come to %s and only %s of this line is unexplained. A split '.
                'that settles more than the line is worth leaves the bank balance and the ledger '.
                'disagreeing by the difference, with every individual match looking correct.',
                (string) $total->toPresentation(),
                (string) $remainder->toPresentation(),
            ));
        }

        return DB::connection('tenant')->transaction(function () use (
            $transaction,
            $prepared,
            $total,
            $remainder,
            $userId,
            $reason,
            $currency,
        ): array {
            $ids = [];
            $rows = [];

            foreach ($prepared['allocations'] as $allocation) {
                $id = (string) Str::ulid();
                $ids[] = $id;

                $rows[] = [
                    'id' => $id,
                    'entity_id' => (string) $transaction->entity_id,
                    'bank_transaction_id' => (string) $transaction->getKey(),
                    'booking_date' => $transaction->booking_date->toDateString(),
                    'matched_type' => $allocation['type'],
                    'matched_id' => $allocation['id'],
                    'amount' => (string) $allocation['amount']->amount,
                    'currency' => $currency,
                    'pass' => 'split',
                    'confidence' => '1.0000',
                    'reason' => sprintf(
                        '%s of a %s bank line, split across %d documents by a person. %s',
                        (string) $allocation['amount']->toPresentation(),
                        (string) Money::of((string) $transaction->amount, $currency)->abs()->toPresentation(),
                        \count($prepared['allocations']),
                        $reason,
                    ),
                    'applied_automatically' => false,
                    'applied_at' => now(),
                    'applied_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            /*
             | One insert, one round trip.
             |
             | What makes this all-or-nothing is the surrounding
             | transaction, not the single statement: row by row inside it
             | would roll back the same way, and a bite that rewrote this
             | as a loop changed nothing. It is one statement because it
             | can be.
             */
            DB::connection('tenant')->table('matches')->insert($rows);

            $left = $remainder->amount->minus($total->amount);

            $this->audit->record(
                category: 'banking',
                action: 'match.split',
                subjectType: 'bank_transaction',
                subjectId: (string) $transaction->getKey(),
                subjectLabel: $this->label($transaction),
                after: [
                    'allocations' => array_map(
                        static fn (array $a): array => [
                            'type' => $a['type'],
                            'id' => $a['id'],
                            'amount' => (string) $a['amount']->amount,
                        ],
                        $prepared['allocations'],
                    ),
                    'applied' => (string) $total->amount,
                    'remainder' => (string) $left,
                ],
                reason: $reason,
                entityId: (string) $transaction->entity_id,
            );

            return [
                'match_ids' => $ids,
                'applied' => $total,
                'remainder' => Money::of((string) $left, $currency),
                'why' => $left->isZero()
                    ? sprintf(
                        'Split across %d documents, and the line is fully explained.',
                        \count($ids),
                    )
                    : sprintf(
                        'Split across %d documents. %s of this line is still unexplained and it '.
                        'stays on the unreconciled list until it is.',
                        \count($ids),
                        (string) Money::of((string) $left, $currency)->toPresentation(),
                    ),
            ];
        });
    }

    /**
     * Take back every live match on a line, as one operation.
     *
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function unsplit(BankTransaction $transaction, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'Unsplitting needs a reason. It moves money back out of documents somebody '.
                'reported as settled, and a reversal nobody explained is the one an auditor stops '.
                'on.',
            );
        }

        $live = DB::connection('tenant')
            ->table('matches')
            ->where('bank_transaction_id', (string) $transaction->getKey())
            ->whereNull('reversed_at')
            ->orderBy('applied_at')
            ->get(['id']);

        if ($live->isEmpty()) {
            throw MatchRefused::because(
                'Nothing is applied to this line, so there is nothing to take back.',
            );
        }

        return DB::connection('tenant')->transaction(function () use ($live, $transaction, $userId, $reason): array {
            $reversed = [];

            /*
             | Each match is reversed on its own path.
             |
             | Not one bulk update: a reversal is audited per match, and a
             | split undone as a single stamped update would leave four
             | documents moving with one record between them. The point of
             | reversible-individually is that the record is per match
             | whether somebody took back one of them or all four.
             */
            foreach ($live as $row) {
                $this->matches->reverse((string) $row->id, $userId, $reason);
                $reversed[] = (string) $row->id;
            }

            $this->audit->record(
                category: 'banking',
                action: 'match.unsplit',
                subjectType: 'bank_transaction',
                subjectId: (string) $transaction->getKey(),
                subjectLabel: $this->label($transaction),
                after: ['reversed' => $reversed],
                reason: $reason,
                entityId: (string) $transaction->entity_id,
            );

            return [
                'reversed' => $reversed,
                'remainder' => $this->remainder($transaction),
                'why' => sprintf(
                    '%d match%s taken back. Every one of them is kept and stamped with who '.
                    'reversed it and why, and the line is unexplained again in full.',
                    \count($reversed),
                    \count($reversed) === 1 ? '' : 'es',
                ),
            ];
        });
    }

    /**
     * Every bank line that has been applied to one document.
     *
     * The other direction: one invoice paid in three instalments shows the
     * three transactions and what is still outstanding. Without this, a
     * part-paid document shows a figure and no way to ask where it came
     * from - and "where did the other 4,000 come from" is the first thing
     * anybody asks about a part-paid invoice.
     *
     * @return array<string, mixed>
     */
    public function appliedTo(string $entityId, string $type, string $documentId, string $currency): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT m.id,
                   m.amount,
                   m.currency,
                   m.applied_at,
                   m.pass,
                   t.booking_date,
                   t.bank_reference,
                   t.counterparty_name
            FROM matches m
            JOIN bank_transactions t
              ON t.id = m.bank_transaction_id
             AND t.booking_date = m.booking_date
            WHERE m.entity_id = ?
              AND m.matched_type = ?
              AND m.matched_id = ?
              AND m.reversed_at IS NULL
            ORDER BY m.applied_at
        SQL, [$entityId, $type, $documentId]);

        $settled = Money::zero($currency);

        foreach ($rows as $row) {
            $settled = $settled->plus(Money::of((string) $row->amount, (string) $row->currency)->abs());
        }

        $document = $this->documentOf($entityId, $type, $documentId, $currency);

        return [
            'lines' => array_map(static fn (object $row): array => [
                'match_id' => (string) $row->id,
                'amount' => (string) $row->amount,
                'booking_date' => (string) $row->booking_date,
                'bank_reference' => $row->bank_reference,
                'counterparty_name' => $row->counterparty_name,
                'pass' => (string) $row->pass,
            ], $rows),
            'settled' => $settled,
            'outstanding' => $document?->outstanding->abs(),
            'why' => $document === null
                ? sprintf(
                    'Settled by %d bank line%s. The document is no longer open, so there is '.
                    'nothing outstanding on it.',
                    \count($rows),
                    \count($rows) === 1 ? '' : 's',
                )
                : sprintf(
                    'Settled by %d bank line%s so far, %s of %s. %s is still outstanding.',
                    \count($rows),
                    \count($rows) === 1 ? '' : 's',
                    (string) $settled->toPresentation(),
                    (string) $document->gross->abs()->toPresentation(),
                    (string) $document->outstanding->abs()->toPresentation(),
                ),
        ];
    }

    /**
     * Validate the allocations as a set, before any of them is written.
     *
     * @param  list<array{type: string, id: string, amount: string}>  $allocations
     * @return array{allocations: list<array{type: string, id: string, amount: Money}>, total: Money}
     *
     * @throws MatchRefused
     */
    private function prepare(BankTransaction $transaction, array $allocations, string $currency): array
    {
        $entityId = (string) $transaction->entity_id;
        $open = [
            'invoice' => $this->documents->receivables($entityId, $currency),
            'bill' => $this->documents->payables($entityId, $currency),
        ];

        $prepared = [];
        $total = Money::zero($currency);
        $perDocument = [];

        foreach ($allocations as $index => $allocation) {
            $type = (string) ($allocation['type'] ?? '');
            $id = (string) ($allocation['id'] ?? '');

            if (! isset($open[$type])) {
                throw MatchRefused::because(sprintf(
                    'Allocation %d names a "%s", and a split allocates against invoices and bills. '.
                    'A write-off or an unidentified amount is a decision of its own rather than a '.
                    'share of one.',
                    $index + 1,
                    $type,
                ));
            }

            $amount = Money::of((string) ($allocation['amount'] ?? '0'), $currency)->abs();

            if ($amount->amount->isZero()) {
                throw MatchRefused::because(sprintf(
                    'Allocation %d is for nothing. A zero allocation records a decision that '.
                    'settles nothing and makes the split read as having more parts than it has.',
                    $index + 1,
                ));
            }

            $document = $this->find($open[$type], $id);

            if ($document === null) {
                throw MatchRefused::because(sprintf(
                    'Allocation %d names a document that is not open. Nothing happened - the whole '.
                    'split is refused rather than the three allocations that would have worked '.
                    'being applied and this one not.',
                    $index + 1,
                ));
            }

            /*
             | Two allocations against one document are added together.
             |
             | Each would fit on its own and the pair would over-settle the
             | document - the same failure the trigger catches on the line
             | side, in the direction a trigger on `matches` cannot see
             | without recomputing credit notes.
             */
            $perDocument[$type.':'.$id] = isset($perDocument[$type.':'.$id])
                ? $perDocument[$type.':'.$id]->plus($amount)
                : $amount;

            if ($perDocument[$type.':'.$id]->amount->isGreaterThan($document->outstanding->abs()->amount)) {
                throw MatchRefused::because(sprintf(
                    'Allocation %d brings this split to %s against %s, which has %s outstanding. '.
                    'Nothing was applied.',
                    $index + 1,
                    (string) $perDocument[$type.':'.$id]->toPresentation(),
                    $document->label(),
                    (string) $document->outstanding->abs()->toPresentation(),
                ));
            }

            $prepared[] = ['type' => $type, 'id' => $id, 'amount' => $amount];
            $total = $total->plus($amount);
        }

        return ['allocations' => $prepared, 'total' => $total];
    }

    /** @param  list<OpenDocument>  $documents */
    private function find(array $documents, string $id): ?OpenDocument
    {
        foreach ($documents as $document) {
            if ($document->id === $id) {
                return $document;
            }
        }

        return null;
    }

    private function documentOf(string $entityId, string $type, string $id, string $currency): ?OpenDocument
    {
        $open = $type === 'invoice'
            ? $this->documents->receivables($entityId, $currency)
            : $this->documents->payables($entityId, $currency);

        return $this->find($open, $id);
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
