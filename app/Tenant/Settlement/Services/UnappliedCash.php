<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * Cash nobody has claimed yet - PF-167.
 *
 * ## It is a liability, and that is correct rather than an error state
 *
 * A customer sent money and nothing says which invoices it settles. That
 * is not a failure of the system; it is the ordinary state of a payment
 * between arriving and being allocated, and it is money we are holding for
 * them.
 *
 * Treating it as an error produces the two bad fixes: reduce the
 * customer's balance anyway, which makes a ledger nobody can take apart,
 * or hold the receipt out of the books until somebody allocates it, which
 * makes the bank reconciliation carry it for a month.
 *
 * ## Old unapplied cash is a misidentified payer, nearly always
 *
 * Which is why it is aged. A receipt sitting unapplied for ninety days is
 * rarely a customer who has not decided what it was for: it is a payer
 * nobody matched to a party, or a payment recorded against the wrong one.
 * The ageing report is what makes somebody ask, and asking early is the
 * difference between a question to the customer and an archaeology
 * exercise.
 *
 * ## And it appears on the customer's statement
 *
 * Because the customer is the only person who definitely knows what the
 * money was for. A statement that shows their invoices and hides the
 * payment they made is a statement that starts an argument; one that shows
 * both lets them answer the question in a sentence.
 */
final class UnappliedCash
{
    /**
     * The control account's balance, from the ledger itself.
     *
     * Read from `journal_lines` rather than summed from payments, because
     * those are two different facts - and {@see reconciles()} exists to
     * notice when they disagree.
     */
    public function balance(string $entityId, string $currency): Money
    {
        $accountId = AccountRole::query()
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::UnappliedCash->value)
            ->value('account_id');

        if ($accountId === null) {
            return Money::zero($currency);
        }

        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(functional_amount), 0)::text AS balance
            FROM journal_lines
            WHERE entity_id = ?
              AND account_id = ?
        SQL, [$entityId, (string) $accountId]);

        return Money::of((string) ($row->balance ?? '0'), $currency);
    }

    /**
     * What is unapplied, by age and by payer.
     *
     * @return array<string, mixed>
     */
    public function ageing(string $entityId, string $currency, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT p.id,
                   p.number,
                   p.value_date,
                   p.currency,
                   p.party_id,
                   pt.legal_name AS party_name,
                   (?::date - p.value_date) AS age_days,
                   (p.amount - coalesce((
                       SELECT sum(a.amount)
                       FROM allocations a
                       WHERE a.payment_id = p.id
                         AND a.reversed_at IS NULL
                   ), 0))::text AS unapplied
            FROM payments p
            LEFT JOIN parties pt ON pt.id = p.party_id
            WHERE p.entity_id = ?
              AND p.currency = ?
              AND p.direction = 'receipt'
              AND p.reversed_at IS NULL
              AND p.value_date <= ?::date
            ORDER BY p.value_date, p.number
        SQL, [$asOf, $entityId, $currency, $asOf]);

        $buckets = [
            '0-30' => Money::zero($currency),
            '31-60' => Money::zero($currency),
            '61-90' => Money::zero($currency),
            '90+' => Money::zero($currency),
        ];

        $total = Money::zero($currency);
        $items = [];
        $oldest = 0;
        $unidentified = 0;

        foreach ($rows as $row) {
            $unapplied = Money::of((string) $row->unapplied, (string) $row->currency);

            if (! $unapplied->isPositive()) {
                continue;
            }

            $age = (int) $row->age_days;
            $bucket = match (true) {
                $age <= 30 => '0-30',
                $age <= 60 => '31-60',
                $age <= 90 => '61-90',
                default => '90+',
            };

            $buckets[$bucket] = $buckets[$bucket]->plus($unapplied);
            $total = $total->plus($unapplied);
            $oldest = max($oldest, $age);

            if ($row->party_id === null) {
                $unidentified++;
            }

            $items[] = [
                'payment_id' => (string) $row->id,
                'number' => (string) $row->number,
                'value_date' => (string) $row->value_date,
                'age_days' => $age,
                'bucket' => $bucket,
                'party_id' => $row->party_id === null ? null : (string) $row->party_id,
                'party_name' => $row->party_name === null ? null : (string) $row->party_name,
                'unapplied' => $unapplied,
            ];
        }

        /*
         | Reported as a credit as well as a magnitude - PF-169.
         |
         | The buckets are what is held, which is a positive figure
         | somebody can total. `credit_buckets` is the same thing with the
         | sign it has on a customer's account, so an ageing report can
         | show it as a negative bucket rather than netting it invisibly
         | into what they owe.
         */
        $credits = array_map(
            static fn (Money $bucket): Money => $bucket->negated(),
            $buckets,
        );

        return [
            'as_of' => $asOf,
            'total' => $total,
            'buckets' => $buckets,
            'credit_buckets' => $credits,
            'credit' => $total->negated(),
            'items' => $items,
            'oldest_days' => $oldest,
            'unidentified_payers' => $unidentified,
            'why' => $items === []
                ? 'Nothing is unapplied.'
                : sprintf(
                    '%s across %d receipt%s, the oldest %d days old%s. %s',
                    (string) $total->toPresentation(),
                    \count($items),
                    \count($items) === 1 ? '' : 's',
                    $oldest,
                    $unidentified === 0 ? '' : sprintf(', and %d with no payer on them', $unidentified),
                    $oldest > 90
                        ? 'A receipt unapplied for three months is rarely a customer who has not '.
                            'decided - it is usually a payer nobody matched, which is a question to '.
                            'ask rather than a figure to carry.'
                        : 'Clearing these is an allocation, never an adjustment.',
                ),
        ];
    }

    /**
     * Whether the unapplied receipts account for the control balance.
     *
     * The same assertion suspense makes, for the same reason: a control
     * account no list of items adds up to is a number somebody has to take
     * apart by reading the ledger.
     *
     * @return array{agrees: bool, balance: Money, unapplied: Money, why: string}
     */
    public function reconciles(string $entityId, string $currency): array
    {
        $balance = $this->balance($entityId, $currency);
        $ageing = $this->ageing($entityId, $currency);

        /*
         | The control account is a credit; the list is a magnitude.
         |
         | Unapplied cash is a liability, so receipts credit it and the
         | balance is negative in a signed ledger. Adding the two gives
         | zero when every riyal is accounted for.
         */
        $agrees = $balance->plus($ageing['total'])->isZero();

        return [
            'agrees' => $agrees,
            'balance' => $balance,
            'unapplied' => $ageing['total'],
            'why' => $agrees
                ? sprintf(
                    'Unapplied cash holds %s and the unallocated receipts account for all of it.',
                    (string) $balance->abs()->toPresentation(),
                )
                : sprintf(
                    'Unapplied cash holds %s and the unallocated receipts account for %s. The '.
                    'difference is money in a control account that no receipt explains - which is '.
                    'how a clearing position stops being a list and becomes a number nobody can '.
                    'take apart.',
                    (string) $balance->abs()->toPresentation(),
                    (string) $ageing['total']->toPresentation(),
                ),
        ];
    }

    /**
     * A customer's statement: what they owe and what they have sent.
     *
     * Both, because the customer is the only person who definitely knows
     * what a payment was for. A statement that lists their invoices and
     * hides the payment they made starts an argument; one that shows both
     * lets them answer in a sentence.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function statementFor(string $entityId, string $partyId, ?string $asOf = null): array
    {
        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;
        $asOf ??= now()->toDateString();

        $documents = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.number,
                   i.issue_date,
                   (i.gross_amount
                     - coalesce((
                         SELECT sum(a.amount)
                         FROM allocations a
                         WHERE a.document_type = 'invoice'
                           AND a.document_id = i.id
                           AND a.reversed_at IS NULL
                       ), 0))::text AS open_balance
            FROM invoices i
            WHERE i.entity_id = ?
              AND i.party_id = ?
              AND i.status = 'issued'
              AND i.document_type IN ('380', '383', '386')
              AND i.issue_date <= ?::date
            ORDER BY i.issue_date, i.number
        SQL, [$entityId, $partyId, $asOf]);

        $ageing = $this->ageing($entityId, $currency, $asOf);

        $theirs = array_values(array_filter(
            $ageing['items'],
            static fn (array $item): bool => $item['party_id'] === $partyId,
        ));

        $owed = Money::zero($currency);
        $lines = [];

        foreach ($documents as $document) {
            $open = Money::of((string) $document->open_balance, $currency);

            if (! $open->isPositive()) {
                continue;
            }

            $owed = $owed->plus($open);

            $lines[] = [
                'number' => (string) $document->number,
                'issue_date' => (string) $document->issue_date,
                'open_balance' => $open,
            ];
        }

        $held = Money::zero($currency);

        foreach ($theirs as $item) {
            $held = $held->plus($item['unapplied']);
        }

        return [
            'as_of' => $asOf,
            'currency' => $currency,
            'documents' => $lines,
            'unapplied' => $theirs,
            'owed' => $owed,
            /*
             | Shown as its own figure, never netted into what is owed.
             |
             | PF-169 asks for this directly, and the reason is that the
             | two are different conversations: "you owe 4,000" and "you
             | owe 5,000 and we are holding 1,000 of yours" lead to
             | different replies, and only the second lets the customer
             | tell you what the 1,000 was for.
             */
            'held' => $held,
            'credit' => $held->negated(),
            'net' => $owed->minus($held),
            'why' => $theirs === []
                ? sprintf('%s is owed on %d open document(s).', (string) $owed->toPresentation(), \count($lines))
                : sprintf(
                    '%s is owed on %d open document(s), and %s of theirs is sitting here '.
                    'unallocated. Showing both is the point: they are the only person who knows '.
                    'what that payment was for.',
                    (string) $owed->toPresentation(),
                    \count($lines),
                    (string) $held->toPresentation(),
                ),
        ];
    }
}
