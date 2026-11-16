<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Ledger\Services\Reverser;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Posting\Rules\AllocationPostedRule;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saying which documents a payment settled - PF-168.
 *
 * ## The invariant is in the database, and this explains it
 *
 * An allocation exceeds neither the payment's unallocated amount nor the
 * document's open balance. Both are enforced by a trigger, because both
 * are the invariant that keeps the receivables subledger equal to its
 * control account - and a service is one code path while an import, a
 * correction script and a bulk tool are three others.
 *
 * What this adds is the sentence a person reads when it refuses, and the
 * posting that moves the balance.
 *
 * ## Strategies propose; they never apply
 *
 * `oldest first`, `by reference`, `instructed`. Each returns a proposed
 * set with the figures and the reason, and a caller applies it. The
 * division is the same one the matching engine makes, for the same
 * reason: a strategy that applied its own answer is one nobody inspects
 * until it is wrong, and "oldest first" is wrong whenever a customer has
 * told you which invoice they are paying.
 *
 * ## Reversal, then re-allocation
 *
 * Ordinary. Somebody allocated to the wrong invoice, undid it, allocated
 * correctly. The reversed row stays, because "it was against 4471 and
 * somebody changed it" is what a customer disputing a statement is
 * asking.
 */
final class Allocations
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Reverser $reverser,
        private readonly \App\Tenant\Banking\Services\Differences $differences,
    ) {}

    /**
     * Allocate part or all of a payment to one document.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function allocate(
        string $paymentId,
        string $documentType,
        string $documentId,
        Money $amount,
        string $userId,
        string $strategy = 'explicit',
        ?string $reason = null,
        ?Carbon $on = null,
    ): array {
        $payment = $this->paymentOr404($paymentId);

        if (! $amount->isPositive()) {
            throw PaymentRefused::because(
                'An allocation is a positive amount. Releasing cash by allocating a negative '.
                'figure would be a refund wearing an allocation\'s clothes, and a refund is a '.
                'payment of its own.',
            );
        }

        if ($amount->currency !== (string) $payment->currency) {
            throw PaymentRefused::because(sprintf(
                'This allocation is in %s and the payment is in %s. An allocation in another '.
                'currency is arithmetic between two different units, and the figure it produced '.
                'would look like a rate nobody can find.',
                $amount->currency,
                (string) $payment->currency,
            ));
        }

        $entity = Entity::query()->findOrFail((string) $payment->entity_id);
        $on ??= Carbon::now();

        /*
         | Both rates, resolved before anything is written - PF-170.
         |
         | The document's is what it was booked at; the payment's is what
         | the money arrived at. Null for both when the settlement is in
         | the books' own currency, which is the common case and realizes
         | nothing.
         */
        $rates = $this->ratesFor($entity, $payment, $documentType, $documentId, $amount);

        return DB::connection('tenant')->transaction(function () use ($rates, 
            $payment,
            $paymentId,
            $documentType,
            $documentId,
            $amount,
            $userId,
            $strategy,
            $reason,
            $entity,
            $on,
        ): array {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: AllocationPostedRule::KEY,
                event: [
                    'amount' => (string) $amount->amount,
                    'currency' => $amount->currency,
                    'payment_rate' => $rates['payment'],
                    'document_rate' => $rates['document'],
                    'party_id' => $payment->party_id === null ? null : (string) $payment->party_id,
                    'payable' => \in_array($documentType, ['bill', 'supplier_credit'], true),
                    'narration' => sprintf(
                        '%s of %s allocated to %s',
                        (string) $payment->number,
                        (string) $amount->toPresentation(),
                        $documentType,
                    ),
                ],
                accountingDate: $on,
                journalCode: 'CSH',
                sourceType: 'allocation',
                sourceId: $documentId,
                narration: $reason,
            );

            $id = (string) Str::ulid();

            /*
             | The row goes in after the posting, inside one transaction.
             |
             | So a refusal from the trigger rolls the posting back with
             | it. The other order would leave an entry crediting
             | receivables for an allocation the database then refused -
             | which is a control account moved by an allocation that
             | does not exist.
             */
            DB::connection('tenant')->table('allocations')->insert([
                'id' => $id,
                'entity_id' => (string) $payment->entity_id,
                'payment_id' => $paymentId,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'party_id' => $payment->party_id,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                'allocated_on' => $on->toDateString(),
                'strategy' => $strategy,
                'reason' => $reason,
                'journal_entry_id' => (string) $entry->getKey(),
                'allocated_by' => $userId,
                'allocated_at' => now(),
                /*
                 | Both rates and the figure they produced, stored.
                 |
                 | "We used 3.75" cannot be checked. "The invoice was
                 | booked at 3.70 and the money arrived at 3.75, so the
                 | difference on 1,000 is 50" is arithmetic an auditor
                 | re-performs in a line.
                 */
                'document_fx_rate' => $rates['document'] === null ? null : (string) $rates['document']->value,
                'payment_fx_rate' => $rates['payment'] === null ? null : (string) $rates['payment']->value,
                'fx_rate_date' => $rates['payment'] === null ? null : $rates['payment']->date->format('Y-m-d'),
                'realized_fx' => $rates['payment'] === null
                    ? null
                    : (string) $this->realizedOn($amount, $rates),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'allocation.posted',
                subjectType: 'payment',
                subjectId: $paymentId,
                subjectLabel: (string) $payment->number,
                after: [
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'amount' => (string) $amount->amount,
                    'strategy' => $strategy,
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $reason,
                entityId: (string) $payment->entity_id,
                partyId: $payment->party_id === null ? null : (string) $payment->party_id,
            );

            $left = $this->unallocated($paymentId);

            return [
                'id' => $id,
                'journal_entry_id' => (string) $entry->getKey(),
                'unallocated' => $left,
                'why' => $left->isZero()
                    ? sprintf('%s is fully allocated.', (string) $payment->number)
                    : sprintf(
                        '%s of %s is still unallocated, and stays in unapplied cash until a '.
                        'document claims it.',
                        (string) $left->toPresentation(),
                        (string) $payment->number,
                    ),
            ];
        });
    }

    /**
     * Take an allocation back, as a posting of its own.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function reverse(string $allocationId, string $userId, string $reason, ?Carbon $on = null): array
    {
        if (mb_strlen(trim($reason)) <= 10) {
            throw PaymentRefused::because(
                'Reversing an allocation needs a reason. It reopens a document somebody reported '.
                'as settled, and the customer may already have been told.',
            );
        }

        $row = DB::connection('tenant')->table('allocations')->where('id', $allocationId)->first();

        if ($row === null) {
            throw PaymentRefused::because('There is no allocation with that id.');
        }

        if ($row->reversed_at !== null) {
            throw PaymentRefused::because(
                'This allocation has already been reversed. Reversing it again would release cash '.
                'that is already back in unapplied.',
            );
        }

        $on ??= Carbon::now();

        return DB::connection('tenant')->transaction(function () use ($row, $allocationId, $userId, $reason, $on): array {
            $entry = $this->reverser->reverse(
                JournalEntry::query()->findOrFail((string) $row->journal_entry_id),
                $on,
                $reason,
            );

            DB::connection('tenant')->table('allocations')->where('id', $allocationId)->update([
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'allocation.reversed',
                subjectType: 'payment',
                subjectId: (string) $row->payment_id,
                subjectLabel: sprintf('%s of %s', (string) $row->document_type, (string) $row->amount),
                before: ['document_id' => (string) $row->document_id, 'amount' => (string) $row->amount],
                after: ['reversing_entry_id' => (string) $entry->getKey()],
                reason: $reason,
                entityId: (string) $row->entity_id,
                partyId: $row->party_id === null ? null : (string) $row->party_id,
            );

            return [
                'id' => $allocationId,
                'reversing_entry_id' => (string) $entry->getKey(),
                'unallocated' => $this->unallocated((string) $row->payment_id),
                'why' => 'Reversed. The document is open again and the cash is back in unapplied - '.
                    'allocating it correctly now is an ordinary thing to do, and the row that was '.
                    'wrong is still there to explain the statement the customer received.',
            ];
        });
    }

    /**
     * The two rates a settlement in a foreign currency is measured by.
     *
     * The document's is what it was booked at - stored on the document,
     * not fetched again, because a rate that was corrected since would
     * restate a gain somebody already reported. The payment's is what the
     * money arrived at, stored when it was recorded for the same reason.
     *
     * @return array{document: Rate|null, payment: Rate|null}
     *
     * @throws PaymentRefused
     */
    private function ratesFor(
        Entity $entity,
        object $payment,
        string $documentType,
        string $documentId,
        Money $amount,
    ): array {
        if ($amount->currency === (string) $entity->functional_currency) {
            return ['document' => null, 'payment' => null];
        }

        if ($payment->fx_rate === null) {
            throw PaymentRefused::because(
                'This payment was recorded without a rate, so there is nothing to measure the '.
                'settlement against. It cannot be allocated until the rate it arrived at is known.',
            );
        }

        $table = $documentType === 'invoice' ? 'invoices' : 'bills';

        $row = DB::connection('tenant')->table($table)
            ->where('id', $documentId)
            ->first(['fx_rate', 'fx_rate_date', 'currency']);

        if ($row === null || $row->fx_rate === null) {
            throw PaymentRefused::because(sprintf(
                'This %s was booked without a rate, so the difference between what it was worth '.
                'then and what the money is worth now cannot be computed. A realized gain nobody '.
                'can derive is a figure nobody can defend.',
                $documentType,
            ));
        }

        if ((string) $row->currency !== $amount->currency) {
            throw PaymentRefused::because(sprintf(
                'This allocation is in %s and the %s is in %s. Settling a document in another '.
                'currency is a conversion, and a conversion is a decision somebody makes rather '.
                'than one an allocation performs.',
                $amount->currency,
                $documentType,
                (string) $row->currency,
            ));
        }

        $functional = (string) $entity->functional_currency;

        return [
            'document' => new Rate(
                $amount->currency,
                $functional,
                (string) $row->fx_rate,
                new \DateTimeImmutable((string) ($row->fx_rate_date ?? $payment->value_date)),
                'document',
                (string) ($payment->fx_rate_set ?? Rate::SET_MARKET),
            ),
            'payment' => new Rate(
                $amount->currency,
                $functional,
                (string) $payment->fx_rate,
                new \DateTimeImmutable((string) ($payment->fx_rate_date ?? $payment->value_date)),
                (string) ($payment->fx_rate_provider ?? 'payment'),
                (string) ($payment->fx_rate_set ?? Rate::SET_MARKET),
            ),
        ];
    }

    /**
     * The realized difference on this allocation, and only on it.
     *
     * Computed on the amount being allocated rather than on the document,
     * which is what makes a partial settlement realize a proportional
     * share - the case that is easy to get wrong, because the figure that
     * looks right is the whole document's difference.
     *
     * @param  array{document: Rate|null, payment: Rate|null}  $rates
     */
    private function realizedOn(Money $amount, array $rates): BigDecimal
    {
        $policy = \App\Support\Money\RoundingPolicy::halfUp(\App\Support\Money\Scale::Amount);

        $atPayment = $rates['payment']->convert($amount, $policy);
        $atDocument = $rates['document']->convert($amount, $policy);

        return $atPayment->amount->minus($atDocument->amount);
    }

    /**
     * Close a document whose last unit is a rounding difference - PF-171.
     *
     * A customer pays 999.99 against 1,000.00. The penny is not a debt
     * anybody is going to chase and not a discount anybody granted: two
     * correct figures differ by the smallest unit the currency expresses,
     * usually because one side rounded a tax line the other way.
     *
     * ## The receivable is closed and the difference is named
     *
     * Not quietly shrunk. A receivable reduced to make it agree with a
     * payment is a figure that no longer traces to the invoice behind it,
     * and the penny nobody posted is the one that makes a control account
     * disagree with its subledger by an amount too small to investigate
     * and too persistent to go away.
     *
     * So it is a posting of its own, to `rounding_difference`, through
     * PF-164's classification - which is where the tolerance, the
     * platform ceiling and the report already live. Above the tolerance
     * this refuses, and the refusal says what it is: not a rounding
     * difference, and a question for whoever classifies them.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function closeRounding(
        string $documentType,
        string $documentId,
        string $entityId,
        string $userId,
        ?string $partyId = null,
        ?Carbon $on = null,
    ): array {
        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;

        $open = $this->openBalance($documentType, $documentId, $currency);

        if (! $open->isPositive()) {
            throw PaymentRefused::because(
                'This document has nothing left on it, so there is no rounding difference to '.
                'close. Closing one anyway would post a figure against a document that is already '.
                'settled.',
            );
        }

        /*
         | Through PF-164, not around it.
         |
         | The tolerance, the platform ceiling that bounds it, the
         | approver above it and the report by type all exist there
         | already. A second rounding path in this module would be a
         | second tolerance to keep in step, and the one that drifts is
         | always the one nobody reads.
         */
        return $this->differences->record(
            \App\Tenant\Banking\Enums\DifferenceType::Rounding,
            $open,
            [
                'entity_id' => $entityId,
                'subject_type' => $documentType,
                'subject_id' => $documentId,
                'counter_account_id' => $this->receivableAccount($entityId, $documentType),
                'party_id' => $partyId,
                'arose_on' => ($on ?? Carbon::now())->toDateString(),
            ],
            $userId,
            sprintf(
                'The last %s of %s, closed as a rounding difference when the payment settled the '.
                'rest of it.',
                (string) $open->toPresentation(),
                $documentType,
            ),
        );
    }

    private function receivableAccount(string $entityId, string $documentType): string
    {
        $role = \in_array($documentType, ['bill', 'supplier_credit'], true)
            ? \App\Tenant\Ledger\Enums\ControlRole::AccountsPayable
            : \App\Tenant\Ledger\Enums\ControlRole::AccountsReceivable;

        $accountId = \App\Tenant\Ledger\Models\AccountRole::query()
            ->where('entity_id', $entityId)
            ->where('role', $role->value)
            ->value('account_id');

        if ($accountId === null) {
            throw PaymentRefused::because(sprintf(
                'This entity has no account carrying the "%s" role, so a rounding difference has '.
                'nowhere to relieve.',
                $role->value,
            ));
        }

        return (string) $accountId;
    }

    /** What is left of a payment to allocate. */
    public function unallocated(string $paymentId): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT p.amount::text AS amount,
                   p.currency,
                   coalesce((
                       SELECT sum(a.amount)
                       FROM allocations a
                       WHERE a.payment_id = p.id
                         AND a.reversed_at IS NULL
                   ), 0)::text AS allocated
            FROM payments p
            WHERE p.id = ?
        SQL, [$paymentId]);

        if ($row === null) {
            throw PaymentRefused::because('There is no payment with that id.');
        }

        $left = BigDecimal::of((string) $row->amount)->minus((string) $row->allocated);

        return Money::of((string) $left, (string) $row->currency);
    }

    /** What is left of a document to settle. */
    public function openBalance(string $documentType, string $documentId, string $currency): Money
    {
        $sql = $documentType === 'invoice'
            ? <<<'SQL'
                SELECT i.gross_amount
                     - coalesce((
                         SELECT sum(c.gross_amount)
                         FROM invoices c
                         JOIN invoice_references r ON r.invoice_id = c.id
                         WHERE r.referenced_invoice_id = i.id
                           AND c.document_type = '381'
                           AND c.status = 'issued'
                       ), 0)
                     - coalesce((
                         SELECT sum(a.amount)
                         FROM allocations a
                         WHERE a.document_type = 'invoice'
                           AND a.document_id = i.id
                           AND a.reversed_at IS NULL
                       ), 0)
                     - coalesce((
                         SELECT sum(d.amount)
                         FROM reconciliation_differences d
                         WHERE d.subject_type = ?
                           AND d.subject_id = ?
                       ), 0) AS open_balance
                FROM invoices i
                WHERE i.id = ?
            SQL
            : <<<'SQL'
                SELECT b.gross_amount
                     - coalesce((
                         SELECT sum(a.amount)
                         FROM allocations a
                         WHERE a.document_type = 'bill'
                           AND a.document_id = b.id
                           AND a.reversed_at IS NULL
                       ), 0)
                     - coalesce((
                         SELECT sum(d.amount)
                         FROM reconciliation_differences d
                         WHERE d.subject_type = ?
                           AND d.subject_id = ?
                       ), 0) AS open_balance
                FROM bills b
                WHERE b.id = ?
            SQL;

        /*
         | Less what a difference already relieved - PF-171.
         |
         | A rounding unit, a written-off shortfall, tax withheld at
         | source: each posts against the document and relieves the
         | control account. A subledger that did not subtract them would
         | say a penny was still owed on an invoice the ledger reports as
         | settled - which is the divergence this phase exists to prevent,
         | arriving by a route nobody was watching.
         */
        $row = DB::connection('tenant')->selectOne($sql, [$documentType, $documentId, $documentId]);

        return Money::of((string) ($row->open_balance ?? '0'), $currency);
    }

    /**
     * What an automatic strategy would do, without doing it.
     *
     * Returns the proposed allocations with their figures and the reason
     * each was chosen. Nothing is written: a strategy that applied its own
     * answer is one nobody inspects until it is wrong, and "oldest first"
     * is wrong whenever the customer has said which invoice they are
     * paying.
     *
     * @return array{proposed: list<array<string, mixed>>, unallocated: Money, why: string}
     *
     * @throws PaymentRefused
     */
    public function propose(string $paymentId, string $strategy, ?string $reference = null): array
    {
        $payment = $this->paymentOr404($paymentId);
        $left = $this->unallocated($paymentId);

        if ($left->isZero()) {
            return [
                'proposed' => [],
                'unallocated' => $left,
                'why' => 'This payment is fully allocated, so there is nothing left to propose.',
            ];
        }

        $open = $this->openDocumentsFor(
            (string) $payment->entity_id,
            $payment->party_id === null ? null : (string) $payment->party_id,
            (string) $payment->currency,
        );

        if ($open === []) {
            return [
                'proposed' => [],
                'unallocated' => $left,
                'why' => $payment->party_id === null
                    ? 'This payment names no payer, so there is nothing to allocate it against. '.
                        'Old unallocated cash is almost always a payer nobody identified - '.
                        'identifying them is the step that resolves it.'
                    : 'This payer has no open documents in this currency.',
            ];
        }

        $proposed = [];
        $remaining = $left;

        if ($strategy === 'by_reference' && $reference !== null) {
            foreach ($open as $document) {
                if (! str_contains(mb_strtoupper((string) $document->number), mb_strtoupper($reference))) {
                    continue;
                }

                [$proposed, $remaining] = $this->take($proposed, $remaining, $document, sprintf(
                    'the payment quotes "%s", which is this document\'s number - a payer naming a '.
                    'document is the strongest instruction there is',
                    $reference,
                ));
            }
        }

        if ($strategy === 'oldest_first' || ($strategy === 'by_reference' && $proposed === [])) {
            foreach ($open as $document) {
                if ($remaining->isZero()) {
                    break;
                }

                [$proposed, $remaining] = $this->take($proposed, $remaining, $document, sprintf(
                    'oldest first: this document is dated %s and nothing on this payment says '.
                    'otherwise',
                    (string) $document->document_date,
                ));
            }
        }

        return [
            'proposed' => $proposed,
            'unallocated' => $remaining,
            'why' => $proposed === []
                ? 'Nothing matched this strategy. Allocating explicitly is the honest alternative - '.
                    'a strategy that reached for a different rule when its own found nothing would '.
                    'be a strategy nobody could predict.'
                : sprintf(
                    '%d document%s proposed, leaving %s unallocated. Nothing is written: a strategy '.
                    'proposes and a person applies, because "oldest first" is wrong whenever the '.
                    'customer has told you which invoice they are paying.',
                    \count($proposed),
                    \count($proposed) === 1 ? '' : 's',
                    (string) $remaining->toPresentation(),
                ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proposed
     * @return array{0: list<array<string, mixed>>, 1: Money}
     */
    private function take(array $proposed, Money $remaining, object $document, string $why): array
    {
        $open = Money::of((string) $document->open_balance, (string) $document->currency);

        /*
         | Whichever is smaller: what is left, or what the document is
         | owed.
         |
         | Which is also the shape the database enforces, so a proposal
         | that ignored either bound would be a proposal that cannot be
         | applied - and a strategy offering something impossible is
         | worse than one offering nothing.
         */
        $take = $open->amount->isLessThan($remaining->amount) ? $open : $remaining;

        if (! $take->isPositive()) {
            return [$proposed, $remaining];
        }

        $proposed[] = [
            'document_type' => (string) $document->document_type,
            'document_id' => (string) $document->id,
            'number' => (string) $document->number,
            'amount' => $take,
            'why' => $why,
        ];

        return [$proposed, $remaining->minus($take)];
    }

    /** @return list<object> */
    private function openDocumentsFor(string $entityId, ?string $partyId, string $currency): array
    {
        return DB::connection('tenant')->select(<<<'SQL'
            SELECT 'invoice'::text AS document_type,
                   i.id,
                   i.number,
                   i.currency,
                   i.issue_date AS document_date,
                   (i.gross_amount
                     - coalesce((
                         SELECT sum(c.gross_amount)
                         FROM invoices c
                         JOIN invoice_references r ON r.invoice_id = c.id
                         WHERE r.referenced_invoice_id = i.id
                           AND c.document_type = '381'
                           AND c.status = 'issued'
                       ), 0)
                     - coalesce((
                         SELECT sum(a.amount)
                         FROM allocations a
                         WHERE a.document_type = 'invoice'
                           AND a.document_id = i.id
                           AND a.reversed_at IS NULL
                       ), 0))::text AS open_balance
            FROM invoices i
            WHERE i.entity_id = ?
              AND i.currency = ?
              AND i.status = 'issued'
              AND i.document_type IN ('380', '383', '386')
              AND (?::char(26) IS NULL OR i.party_id = ?::char(26))
            ORDER BY i.issue_date, i.number
        SQL, [$entityId, $currency, $partyId, $partyId]);
    }

    private function paymentOr404(string $paymentId): object
    {
        $payment = DB::connection('tenant')->table('payments')->where('id', $paymentId)->first();

        if ($payment === null) {
            throw PaymentRefused::because('There is no payment with that id.');
        }

        if ($payment->reversed_at !== null) {
            throw PaymentRefused::because(
                'This payment was reversed. Allocating it would report a document settled by money '.
                'that was taken back.',
            );
        }

        return $payment;
    }
}
