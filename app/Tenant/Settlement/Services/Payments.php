<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Ledger\Services\Reverser;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Posting\Rules\PaymentRecordedRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording that money moved - PF-166.
 *
 * ## It never touches a party balance
 *
 * The decision the whole settlement module rests on. "They sent 10,000"
 * and "10,000 of their invoices are settled" are different facts, and they
 * become one fact only when somebody says which invoices.
 *
 * Reducing the balance on receipt would produce the right total and a
 * customer ledger nobody can take apart: 4,000 outstanding that is three
 * invoices and a receipt, with nothing saying which invoice the receipt
 * was for. Every question about it afterwards is a reconstruction, and the
 * reconstruction happens in a spreadsheet.
 *
 * ## A currency mismatch is refused, never converted
 *
 * A payment of 5,000 EUR into a SAR account is not a payment this system
 * can post: it would have to pick a rate, and the rate it picked would be
 * invisible in the figure it produced. Refusing it costs somebody a
 * conversation with their bank; converting it silently costs an audit.
 *
 * ## Immutable once posted
 *
 * Corrected by reversal, which is a posting of its own. "We received
 * 10,000 on the 3rd and it turned out to be 1,000" is a fact about the
 * 3rd, and editing the row deletes it. The database says the same thing
 * from underneath.
 */
final class Payments
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Reverser $reverser,
        private readonly NumberAllocator $numbers,
        private readonly \App\Tenant\Catalog\Services\ExchangeRates $rates,
    ) {}

    /**
     * Record a receipt or a disbursement.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function record(
        string $entityId,
        PaymentDirection $direction,
        PaymentMethod $method,
        Money $amount,
        Carbon $valueDate,
        string $userId,
        ?string $bankAccountId = null,
        ?string $cashAccountId = null,
        ?string $partyId = null,
        ?string $note = null,
        ?string $externalReference = null,
    ): array {
        if (! $amount->isPositive()) {
            throw PaymentRefused::because(
                'A payment is a positive amount and a direction. A negative one gives two ways to '.
                'say "money out", and a total over a mixed set would be a figure that means '.
                'nothing - which is a figure somebody puts on a screen.',
            );
        }

        $account = $this->resolveTheAccount($entityId, $method, $bankAccountId, $cashAccountId, $amount);
        $entity = Entity::query()->findOrFail($entityId);

        /*
         | Signed as the cash moved, once, here.
         |
         | The posting rule has one form because of this: it cannot be
         | right about the figure and wrong about the direction, and the
         | direction is decided in exactly one place.
         */
        $signed = $direction->increasesCash() ? $amount : $amount->negated();

        /*
         | The rate for the value date, resolved once - PF-170.
         |
         | The value date is when the money was ours, so it is the rate
         | the cash is worth at. Using today's would make the same receipt
         | worth a different figure depending on when somebody got round
         | to entering it, which is a figure nobody can defend.
         |
         | Null when the payment is already in the functional currency,
         | and that null is meaningful: a stored 1 is indistinguishable
         | from a rate somebody fetched.
         */
        $rate = $this->rateFor($entity, $amount, $valueDate);

        return DB::connection('tenant')->transaction(function () use ($rate, 
            $entityId,
            $entity,
            $direction,
            $method,
            $amount,
            $signed,
            $valueDate,
            $userId,
            $account,
            $partyId,
            $note,
            $externalReference,
        ): array {
            $number = $this->numbers->next($entityId, 'payment', 'PAY', $valueDate);

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: PaymentRecordedRule::KEY,
                event: [
                    'cash_account_id' => $account['gl_account_id'],
                    'amount' => (string) $signed->amount,
                    'currency' => $amount->currency,
                    'rate' => $rate,
                    'party_id' => $partyId,
                    'narration' => sprintf(
                        '%s %s by %s',
                        ucfirst($direction->labelEn()),
                        (string) $amount->toPresentation(),
                        $method->labelEn(),
                    ),
                ],
                accountingDate: $valueDate,
                journalCode: 'CSH',
                sourceType: 'payment',
                sourceId: $number,
                narration: $note,
            );

            $id = (string) Str::ulid();

            DB::connection('tenant')->table('payments')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'number' => $number,
                'direction' => $direction->value,
                'method' => $method->value,
                'bank_account_id' => $account['bank_account_id'],
                'cash_account_id' => $account['cash_account_id'],
                'party_id' => $partyId,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                'value_date' => $valueDate->toDateString(),
                'note' => $note,
                'external_reference' => $externalReference,
                'journal_entry_id' => (string) $entry->getKey(),
                'recorded_by' => $userId,
                'recorded_at' => now(),
                'fx_rate' => $rate === null ? null : (string) $rate->value,
                'fx_rate_set' => $rate?->rateSet,
                'fx_rate_date' => $rate === null ? null : $rate->date->format('Y-m-d'),
                'fx_rate_provider' => $rate?->provider,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'payment.recorded',
                subjectType: 'payment',
                subjectId: $id,
                subjectLabel: sprintf('%s %s', $number, (string) $amount->toPresentation()),
                after: [
                    'direction' => $direction->value,
                    'method' => $method->value,
                    'amount' => (string) $amount->amount,
                    'currency' => $amount->currency,
                    'value_date' => $valueDate->toDateString(),
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $note,
                entityId: $entityId,
                partyId: $partyId,
            );

            /*
             | Money moved, and one event says so for both directions - PF-048.
             |
             | Not `PaymentReceived` and `PaymentMade`. A listener that cares
             | which way the cash went reads `direction`; a listener that
             | cares that cash moved at all - the bank matcher, the cash
             | forecast, the audit stream - would otherwise have to subscribe
             | twice and stay subscribed twice, and the second subscription
             | is the one somebody forgets when a third direction is added.
             |
             | Carried as the UNSIGNED amount with the direction beside it,
             | because `$signed` is the ledger's view and a listener summing
             | signed figures across a mixed set gets a number that means
             | nothing. The sign is a posting decision, made above, once.
             */
            event(new \App\Tenant\Settlement\Events\PaymentRecorded(
                entityId: $entityId,
                paymentId: $id,
                number: $number,
                direction: $direction->value,
                amount: $amount,
                valueDate: $valueDate->toDateString(),
                partyId: $partyId,
                byUserId: $userId,
            ));

            return [
                'id' => $id,
                'number' => $number,
                'journal_entry_id' => (string) $entry->getKey(),
                'why' => sprintf(
                    '%s of %s recorded against %s. It sits in unapplied cash until somebody '.
                    'allocates it - what %s owes has not changed, because that changes when a '.
                    'document claims the money.',
                    ucfirst($direction->labelEn()),
                    (string) $amount->toPresentation(),
                    $account['label'],
                    $partyId === null ? 'the payer' : 'they',
                ),
            ];
        });
    }

    /**
     * Reverse a payment, which is a posting of its own.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function reverse(string $paymentId, string $userId, string $reason, ?Carbon $on = null): array
    {
        if (mb_strlen(trim($reason)) <= 10) {
            throw PaymentRefused::because(
                'Reversing a payment needs a reason. It withdraws a movement of money somebody '.
                'recorded, and "the receipt was entered twice" and "the cheque bounced" have '.
                'different consequences for the same figure.',
            );
        }

        $payment = DB::connection('tenant')->table('payments')->where('id', $paymentId)->first();

        if ($payment === null) {
            throw PaymentRefused::because('There is no payment with that id.');
        }

        if ($payment->reversed_at !== null) {
            throw PaymentRefused::because(
                'This payment has already been reversed. Reversing it again would record a second '.
                'withdrawal of money that is no longer there.',
            );
        }

        $allocated = DB::connection('tenant')->table('allocations')
            ->where('payment_id', $paymentId)
            ->whereNull('reversed_at')
            ->exists();

        if ($allocated) {
            /*
             | Allocations first, and the refusal says so.
             |
             | Reversing the cash under a live allocation would leave a
             | document reported as settled by money that was never
             | received - which is the one state the whole module is
             | built to make impossible.
             */
            throw PaymentRefused::because(
                'This payment has been allocated to documents. Reversing the cash underneath them '.
                'would leave invoices reported as settled by money that was never received - undo '.
                'the allocations first, which is an ordinary thing to do.',
            );
        }

        $on ??= Carbon::now();

        return DB::connection('tenant')->transaction(function () use ($payment, $paymentId, $userId, $reason, $on): array {
            $entry = $this->reverser->reverse(
                \App\Tenant\Ledger\Models\JournalEntry::query()->findOrFail((string) $payment->journal_entry_id),
                /*
                 | Today by default, and the caller may name a date.
                 |
                 | The original stands where it was posted; the correction
                 | is today's act, so today is the default. It is not
                 | forced, because the period that accepts postings is the
                 | ledger's decision and a caller may be correcting
                 | something in a month that is still open while today's
                 | is not yet generated. The ledger refuses a closed or
                 | future period either way, which is where that decision
                 | belongs.
                 */
                $on,
                $reason,
            );

            DB::connection('tenant')->table('payments')->where('id', $paymentId)->update([
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'payment.reversed',
                subjectType: 'payment',
                subjectId: $paymentId,
                subjectLabel: (string) $payment->number,
                before: ['amount' => (string) $payment->amount, 'currency' => (string) $payment->currency],
                after: ['reversing_entry_id' => (string) $entry->getKey()],
                reason: $reason,
                entityId: (string) $payment->entity_id,
                partyId: $payment->party_id === null ? null : (string) $payment->party_id,
            );

            return [
                'id' => $paymentId,
                'reversing_entry_id' => (string) $entry->getKey(),
                'why' => 'Reversed by a posting of its own. The original is kept exactly as it was '.
                    'recorded, because what happened on that day is what the ledger is for.',
            ];
        });
    }

    /**
     * The rate that turns this payment into the books' own currency.
     *
     * Null when they are the same currency, which is the common case and
     * is not a rate of 1: a stored 1 is indistinguishable from one
     * somebody fetched, and "which payments were translated" is the query
     * written when a realized figure looks wrong.
     *
     * @throws PaymentRefused
     */
    private function rateFor(Entity $entity, Money $amount, Carbon $valueDate): ?Rate
    {
        $functional = (string) $entity->functional_currency;

        if ($amount->currency === $functional) {
            return null;
        }

        try {
            return $this->rates->rateFor($amount->currency, $functional, $valueDate);
        } catch (\Throwable $e) {
            throw PaymentRefused::because(sprintf(
                'This payment is in %s and the books are kept in %s, and there is no rate for %s. '.
                'Posting it would mean choosing one, and a rate nobody reviewed is invisible in '.
                'every figure it produced afterwards. (%s)',
                $amount->currency,
                $functional,
                $valueDate->toDateString(),
                $e->getMessage(),
            ));
        }
    }

    /**
     * Which account the money moved through, and the GL account behind it.
     *
     * @return array{bank_account_id: string|null, cash_account_id: string|null, gl_account_id: string, label: string}
     *
     * @throws PaymentRefused
     */
    private function resolveTheAccount(
        string $entityId,
        PaymentMethod $method,
        ?string $bankAccountId,
        ?string $cashAccountId,
        Money $amount,
    ): array {
        if (($bankAccountId === null) === ($cashAccountId === null)) {
            throw PaymentRefused::because(
                'A payment names exactly one account - a bank account or a cash account. Naming '.
                'both is a payment nobody can trace, and naming neither is one that has not '.
                'happened anywhere.',
            );
        }

        if ($bankAccountId !== null) {
            if (! $method->movesThroughABank()) {
                throw PaymentRefused::because(sprintf(
                    'A payment by %s does not move through a bank account. Recording it against '.
                    'one puts a line on a bank reconciliation that no statement will ever carry.',
                    $method->labelEn(),
                ));
            }

            $account = BankAccount::query()
                ->where('entity_id', $entityId)
                ->where('id', $bankAccountId)
                ->first();

            if ($account === null) {
                throw PaymentRefused::because('That bank account does not belong to this entity.');
            }

            $this->assertTheCurrencyAgrees((string) $account->currency, $amount, (string) $account->label);

            return [
                'bank_account_id' => (string) $account->getKey(),
                'cash_account_id' => null,
                'gl_account_id' => (string) $account->gl_account_id,
                'label' => (string) $account->label,
            ];
        }

        $account = Account::query()
            ->where('entity_id', $entityId)
            ->where('id', $cashAccountId)
            ->first();

        if ($account === null) {
            throw PaymentRefused::because('That cash account does not belong to this entity.');
        }

        /*
         | A cash account's currency is the entity's functional one.
         |
         | A GL account has no currency of its own: it is denominated in
         | the books it is part of. So the test is against the entity,
         | and a foreign-currency till is a bank account as far as this
         | system is concerned - which is also how it has to be
         | reconciled.
         */
        $entity = Entity::query()->findOrFail($entityId);

        $this->assertTheCurrencyAgrees((string) $entity->functional_currency, $amount, (string) $account->name_en);

        return [
            'bank_account_id' => null,
            'cash_account_id' => (string) $account->getKey(),
            'gl_account_id' => (string) $account->getKey(),
            'label' => (string) $account->name_en,
        ];
    }

    /** @throws PaymentRefused */
    private function assertTheCurrencyAgrees(string $accountCurrency, Money $amount, string $label): void
    {
        if ($accountCurrency === $amount->currency) {
            return;
        }

        throw PaymentRefused::because(sprintf(
            'This payment is in %s and %s is held in %s. Converting it here would mean picking a '.
            'rate, and the rate would be invisible in the figure that came out - so it is refused. '.
            'Record it against an account in %s, or ask the bank what they actually credited.',
            $amount->currency,
            $label,
            $accountCurrency,
            $amount->currency,
        ));
    }
}
