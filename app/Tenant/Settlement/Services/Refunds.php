<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Giving a customer their money back - PF-169.
 *
 * ## A document and a payment, never a negative receipt
 *
 * A negative receipt nets against the customer's cash in every report that
 * sums payments, so "how much have we refunded this year" becomes a query
 * nobody can write - and that figure is the one a finance director asks
 * about, usually after a chargeback.
 *
 * It is also the shape fraud takes. A negative receipt looks like a
 * correction; a refund has an amount, a reason, an approver and a payment,
 * and the approver is a person whose name is on a row.
 *
 * ## The credit is ordinary unapplied cash
 *
 * An overpayment is not a special kind of object: the customer sent more
 * than their invoices came to, the excess sits unallocated against them,
 * and that is a credit they can use on their next document or ask to have
 * back. Two ways to hold a customer's money would need a reconciliation
 * between them.
 *
 * So a refund is a **disbursement** that releases the held liability, and
 * refunding more than the credit is refused - because the money beyond it
 * was never theirs to get back, and paying it out is a payment on account
 * to somebody who does not have one.
 */
final class Refunds
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Payments $payments,
        private readonly UnappliedCash $cash,
        private readonly NumberAllocator $numbers,
    ) {}

    /**
     * What a party has on account, as a positive figure.
     *
     * Their unallocated receipts less their unallocated disbursements -
     * which is what a credit balance is, whatever produced it: an
     * overpayment, a cancelled order, a payment that arrived before its
     * invoice.
     */
    public function creditFor(string $entityId, string $partyId, string $currency): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(
                CASE WHEN p.direction = 'receipt' THEN 1 ELSE -1 END
                * (p.amount - coalesce((
                    SELECT sum(a.amount)
                    FROM allocations a
                    WHERE a.payment_id = p.id
                      AND a.reversed_at IS NULL
                  ), 0))
            ), 0)::text AS credit
            FROM payments p
            WHERE p.entity_id = ?
              AND p.party_id = ?
              AND p.currency = ?
              AND p.reversed_at IS NULL
        SQL, [$entityId, $partyId, $currency]);

        return Money::of((string) ($row->credit ?? '0'), $currency);
    }

    /**
     * Refund part or all of a party's credit balance.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function issue(
        string $entityId,
        string $partyId,
        Money $amount,
        string $reason,
        string $approvedBy,
        string $bankAccountId,
        ?Carbon $on = null,
    ): array {
        if (mb_strlen(trim($reason)) < 20) {
            throw PaymentRefused::because(
                'A refund needs a reason, at the length of a sentence. It is read when somebody '.
                'asks why money went back to a customer, and they are usually asking because they '.
                'have just seen the figure on a bank statement.',
            );
        }

        if (! $amount->isPositive()) {
            throw PaymentRefused::because('A refund is a positive amount of money going back.');
        }

        $credit = $this->creditFor($entityId, $partyId, $amount->currency);

        if ($amount->amount->isGreaterThan($credit->amount)) {
            /*
             | Refunding more than is on account is refused.
             |
             | The money beyond the credit was never theirs to get back.
             | Paying it out is a payment on account to somebody who does
             | not have one - which shows up later as a debit balance on a
             | customer nobody sold anything to.
             */
            throw PaymentRefused::because(sprintf(
                'This party has %s on account and the refund is for %s. The difference was never '.
                'theirs to get back, and paying it out would leave a debit balance on a customer '.
                'nobody sold anything to. Refund what is on account, or raise a credit note if the '.
                'rest is owed for another reason.',
                (string) $credit->toPresentation(),
                (string) $amount->toPresentation(),
            ));
        }

        $on ??= Carbon::now();

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $partyId,
            $amount,
            $reason,
            $approvedBy,
            $bankAccountId,
            $on,
            $credit,
        ): array {
            $number = $this->numbers->next($entityId, 'refund', 'REF', $on);

            /*
             | A disbursement, which releases the held liability.
             |
             | Dr unapplied cash, Cr bank - the exact opposite of the
             | receipt that created the credit. No special posting rule,
             | because a refund is not a special movement of money: it is
             | money going out, with a document saying why.
             */
            $payment = $this->payments->record(
                entityId: $entityId,
                direction: PaymentDirection::Disbursement,
                method: PaymentMethod::CreditTransfer,
                amount: $amount,
                valueDate: $on,
                userId: $approvedBy,
                bankAccountId: $bankAccountId,
                partyId: $partyId,
                note: sprintf('Refund %s: %s', $number, $reason),
            );

            $id = (string) Str::ulid();

            DB::connection('tenant')->table('refunds')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'number' => $number,
                'party_id' => $partyId,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                'refunded_on' => $on->toDateString(),
                'reason' => $reason,
                'payment_id' => (string) $payment['id'],
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'refund.issued',
                subjectType: 'refund',
                subjectId: $id,
                subjectLabel: sprintf('%s %s', $number, (string) $amount->toPresentation()),
                after: [
                    'amount' => (string) $amount->amount,
                    'currency' => $amount->currency,
                    'credit_before' => (string) $credit->amount,
                    'payment_id' => (string) $payment['id'],
                ],
                reason: $reason,
                entityId: $entityId,
                partyId: $partyId,
            );

            $left = $credit->minus($amount);

            return [
                'id' => $id,
                'number' => $number,
                'payment_id' => (string) $payment['id'],
                'credit_remaining' => $left,
                'why' => $left->isZero()
                    ? sprintf('%s refunded in full. The party has nothing left on account.', (string) $amount->toPresentation())
                    : sprintf(
                        '%s refunded, leaving %s on account - still usable against their next '.
                        'document.',
                        (string) $amount->toPresentation(),
                        (string) $left->toPresentation(),
                    ),
            ];
        });
    }

    /**
     * Every refund, for the report somebody asks for after a chargeback.
     *
     * @return list<array<string, mixed>>
     */
    public function report(string $entityId, ?string $from = null, ?string $until = null): array
    {
        $rows = DB::connection('tenant')->table('refunds as r')
            ->leftJoin('parties as p', 'p.id', '=', 'r.party_id')
            ->where('r.entity_id', $entityId)
            ->when($from !== null, fn ($q) => $q->where('r.refunded_on', '>=', $from))
            ->when($until !== null, fn ($q) => $q->where('r.refunded_on', '<', $until))
            ->orderByDesc('r.refunded_on')
            ->get(['r.number', 'r.amount', 'r.currency', 'r.refunded_on', 'r.reason', 'r.approved_by', 'p.legal_name']);

        return $rows->map(static fn (object $row): array => [
            'number' => (string) $row->number,
            'party' => $row->legal_name === null ? null : (string) $row->legal_name,
            'amount' => (string) $row->amount,
            'currency' => (string) $row->currency,
            'refunded_on' => (string) $row->refunded_on,
            'reason' => (string) $row->reason,
            'approved_by' => (string) $row->approved_by,
        ])->all();
    }
}
