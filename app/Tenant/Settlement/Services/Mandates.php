<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mandates, pre-notification and collections - PF-177.
 *
 * ## The sequence type is derived, never set
 *
 * SEPA distinguishes the first collection under a mandate (`FRST`) from
 * every one after it (`RCUR`), and getting it wrong is a scheme-level
 * rejection: a second `FRST` is refused because the debtor's bank has
 * already seen the mandate, and an `RCUR` before any `FRST` because it
 * has not.
 *
 * So no caller supplies it. It comes from the mandate's own collection
 * history, and a unique index makes a second `FRST` impossible even by a
 * direct insert.
 *
 * ## Pre-notification is checked, not assumed
 *
 * The scheme requires the debtor be told a set number of days ahead. A
 * collection without that is one they can reverse without giving a reason
 * for thirteen months under Core - so a collection whose lead time is
 * short is refused, naming the earliest date it could be made.
 *
 * And the notification has to be for THIS collection: the amount and the
 * date were what was promised, so a collection for more than was notified
 * is not covered by it. That is the half a product skips, and the symptom
 * is a refund thirteen months later nobody can explain.
 */
final class Mandates
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Record a signed mandate.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function record(
        string $entityId,
        string $partyId,
        string $reference,
        string $scheme,
        Carbon $signedOn,
        string $signatureChannel,
        string $debtorName,
        string $userId,
        ?string $debtorAccountId = null,
        ?Carbon $expiresOn = null,
    ): array {
        $definition = $this->scheme($scheme);
        $reference = trim($reference);

        if ($reference === '' || mb_strlen($reference) > 35) {
            throw PaymentRefused::because(
                'A mandate reference is between 1 and 35 characters: it is what the debtor\'s bank and '.
                'the debtor\'s own statement identify the mandate by, and the schema carries 35.',
            );
        }

        if (DB::connection('tenant')->table('mandates')
            ->where('entity_id', $entityId)
            ->where('reference', $reference)
            ->exists()) {
            throw PaymentRefused::because(sprintf(
                'Mandate reference "%s" is already in use. The debtor\'s bank matches on it, so two '.
                'mandates sharing one reference means the second collection goes out as RCUR against '.
                'a mandate the bank has never seen.',
                $reference,
            ));
        }

        $id = (string) Str::ulid();

        return DB::connection('tenant')->transaction(function () use (
            $id,
            $entityId,
            $partyId,
            $reference,
            $scheme,
            $definition,
            $signedOn,
            $signatureChannel,
            $debtorName,
            $debtorAccountId,
            $expiresOn,
            $userId,
        ): array {
            DB::connection('tenant')->table('mandates')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'party_id' => $partyId,
                'reference' => $reference,
                'scheme' => $scheme,
                'signed_on' => $signedOn->toDateString(),
                'signature_channel' => $signatureChannel,
                'debtor_account_id' => $debtorAccountId,
                'debtor_name' => $debtorName,
                'state' => 'active',
                'expires_on' => $expiresOn?->toDateString(),
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'mandate.recorded',
                subjectType: 'mandate',
                subjectId: $id,
                subjectLabel: $reference,
                after: [
                    'scheme' => $scheme,
                    'signed_on' => $signedOn->toDateString(),
                    'channel' => $signatureChannel,
                    'expires_on' => $expiresOn?->toDateString(),
                ],
                reason: sprintf('a %s mandate was signed on %s', (string) $definition['label'], $signedOn->toDateString()),
                entityId: $entityId,
                partyId: $partyId,
            );

            return [
                'id' => $id,
                'reference' => $reference,
                'scheme' => $scheme,
                'why' => sprintf(
                    'Mandate %s recorded under %s. The first collection will go out as FRST and every '.
                    'one after it as RCUR - derived from the history rather than set, because a second '.
                    'FRST is refused by the debtor\'s bank at the scheme level. The debtor must be told '.
                    '%d days before each collection.',
                    $reference,
                    (string) $definition['label'],
                    (int) $definition['lead_days'],
                ),
            ];
        });
    }

    /**
     * Tell the debtor what is coming, and when.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function notify(
        string $mandateId,
        Money $amount,
        Carbon $collectionDate,
        string $channel,
        string $userId,
        ?Carbon $notifiedOn = null,
    ): array {
        $mandate = $this->liveMandate($mandateId);
        $definition = $this->scheme((string) $mandate->scheme);
        $notifiedOn ??= Carbon::now();

        $lead = (int) $notifiedOn->copy()->startOfDay()->diffInDays($collectionDate->copy()->startOfDay(), false);

        if ($lead < (int) $definition['lead_days']) {
            /*
             | Refused at the moment of the promise, not of the collection.
             |
             | Telling somebody on the day is worse than not telling them:
             | it looks like notice and is not, and the refund right runs
             | for thirteen months either way.
             */
            throw PaymentRefused::because(sprintf(
                '%s requires %d days\' notice and this gives %d. The earliest collection this '.
                'notification can cover is %s. Notice that is too short is not notice: the debtor can '.
                'reverse the collection without giving a reason for %d months, and nobody budgets for '.
                'that.',
                (string) $definition['label'],
                (int) $definition['lead_days'],
                $lead,
                $notifiedOn->copy()->addDays((int) $definition['lead_days'])->toDateString(),
                (int) $definition['refund_months'],
            ));
        }

        $id = (string) Str::ulid();

        DB::connection('tenant')->table('mandate_notifications')->insert([
            'id' => $id,
            'entity_id' => (string) $mandate->entity_id,
            'mandate_id' => $mandateId,
            'amount' => (string) $amount->amount,
            'currency' => $amount->currency,
            'collection_date' => $collectionDate->toDateString(),
            'notified_on' => $notifiedOn->toDateString(),
            'channel' => $channel,
            'notified_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'why' => sprintf(
                'The debtor was told on %s that %s will be collected on %s - %d days\' notice, where '.
                '%s requires %d.',
                $notifiedOn->toDateString(),
                (string) $amount->toPresentation(),
                $collectionDate->toDateString(),
                $lead,
                (string) $definition['label'],
                (int) $definition['lead_days'],
            ),
        ];
    }

    /**
     * Present a collection under a mandate.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function collect(
        string $mandateId,
        Money $amount,
        Carbon $collectionDate,
        string $userId,
        ?string $invoiceId = null,
        bool $final = false,
    ): array {
        $mandate = $this->liveMandate($mandateId);
        $definition = $this->scheme((string) $mandate->scheme);

        if ($definition['currency'] !== null && $amount->currency !== $definition['currency']) {
            throw PaymentRefused::because(sprintf(
                'This collection is in %s and %s carries %s only.',
                $amount->currency,
                (string) $definition['label'],
                (string) $definition['currency'],
            ));
        }

        $this->assertTheDebtorWasTold($mandate, $definition, $amount, $collectionDate);

        $sequence = $this->sequenceFor($mandateId, $final);
        $id = (string) Str::ulid();

        return DB::connection('tenant')->transaction(function () use (
            $id,
            $mandate,
            $mandateId,
            $amount,
            $collectionDate,
            $sequence,
            $invoiceId,
            $userId,
            $definition
        ): array {
            DB::connection('tenant')->table('collections')->insert([
                'id' => $id,
                'entity_id' => (string) $mandate->entity_id,
                'mandate_id' => $mandateId,
                'invoice_id' => $invoiceId,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                'collection_date' => $collectionDate->toDateString(),
                'sequence_type' => $sequence,
                'state' => 'presented',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'collection.presented',
                subjectType: 'collection',
                subjectId: $id,
                subjectLabel: sprintf('%s %s', (string) $mandate->reference, (string) $amount->toPresentation()),
                after: [
                    'mandate' => (string) $mandate->reference,
                    'sequence_type' => $sequence,
                    'amount' => (string) $amount->amount,
                    'collection_date' => $collectionDate->toDateString(),
                ],
                reason: sprintf('a %s collection was presented as %s', (string) $definition['label'], $sequence),
                entityId: (string) $mandate->entity_id,
                partyId: (string) $mandate->party_id,
            );

            return [
                'id' => $id,
                'sequence_type' => $sequence,
                'why' => sprintf(
                    '%s will be collected on %s under mandate %s, as %s - %s',
                    (string) $amount->toPresentation(),
                    $collectionDate->toDateString(),
                    (string) $mandate->reference,
                    $sequence,
                    $sequence === 'FRST'
                        ? 'the first collection under this mandate, which the debtor\'s bank has not '.
                          'seen before'
                        : 'a recurring collection, because the debtor\'s bank has already seen this mandate',
                ),
            ];
        });
    }

    /**
     * A returned collection: the money came back, with a reason code.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function returned(
        string $collectionId,
        string $reasonCode,
        string $reason,
        Carbon $returnedOn,
        string $userId,
        bool $isRefund = false,
    ): array {
        $collection = DB::connection('tenant')->table('collections')->where('id', $collectionId)->first();

        if ($collection === null) {
            throw PaymentRefused::because('There is no collection with that id.');
        }

        if (\in_array((string) $collection->state, ['returned', 'refunded'], true)) {
            throw PaymentRefused::because(sprintf(
                'That collection was already %s on %s, under reason code %s.',
                (string) $collection->state,
                (string) $collection->returned_on,
                (string) $collection->return_reason_code,
            ));
        }

        if (trim($reasonCode) === '') {
            throw PaymentRefused::because(
                'A return needs the bank\'s reason code. It is what tells an insufficient balance from '.
                'a revoked mandate from an account that no longer exists - three facts with three '.
                'different responses, and a return without one is a figure somebody has to telephone '.
                'the bank about.',
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $collection,
            $collectionId,
            $reasonCode,
            $reason,
            $returnedOn,
            $userId,
            $isRefund,
        ): array {
            DB::connection('tenant')->table('collections')->where('id', $collectionId)->update([
                'state' => $isRefund ? 'refunded' : 'returned',
                'return_reason_code' => trim($reasonCode),
                'return_reason' => $reason,
                'returned_on' => $returnedOn->toDateString(),
                'updated_at' => now(),
            ]);

            /*
             | A revoked mandate cancels itself on the return.
             |
             | `MD01` and `MD02` mean the debtor says there is no mandate,
             | or that it is not valid. Presenting again would be returned
             | again and would look, to the debtor's bank, like a business
             | that does not read its returns - which is how a creditor
             | identifier gets suspended.
             */
            $revoked = \in_array(mb_strtoupper(trim($reasonCode)), ['MD01', 'MD02', 'MD06', 'MD07'], true);

            if ($revoked) {
                DB::connection('tenant')->table('mandates')
                    ->where('id', (string) $collection->mandate_id)
                    ->where('state', 'active')
                    ->update([
                        'state' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancelled_by' => $userId,
                        'cancellation_reason' => sprintf(
                            'the bank returned a collection under reason code %s, which means the debtor '.
                            'disputes the mandate - presenting again would be returned again',
                            mb_strtoupper(trim($reasonCode)),
                        ),
                        'updated_at' => now(),
                    ]);
            }

            $this->audit->record(
                category: 'settlement',
                action: $isRefund ? 'collection.refunded' : 'collection.returned',
                subjectType: 'collection',
                subjectId: $collectionId,
                subjectLabel: (string) $collection->amount,
                before: ['state' => (string) $collection->state],
                after: [
                    'state' => $isRefund ? 'refunded' : 'returned',
                    'reason_code' => trim($reasonCode),
                    'returned_on' => $returnedOn->toDateString(),
                    'mandate_cancelled' => $revoked,
                ],
                reason: $reason,
                entityId: (string) $collection->entity_id,
            );

            return [
                'id' => $collectionId,
                'state' => $isRefund ? 'refunded' : 'returned',
                'mandate_cancelled' => $revoked,
                'why' => sprintf(
                    'The collection came back on %s under code %s.%s',
                    $returnedOn->toDateString(),
                    trim($reasonCode),
                    $revoked
                        ? ' That code means the debtor disputes the mandate, so it is cancelled - '.
                          'presenting again would be returned again, and a creditor that does not read '.
                          'its returns is one whose identifier gets suspended.'
                        : ' The mandate stands: this is a collection that failed, not an authority that '.
                          'was withdrawn.',
                ),
            ];
        });
    }

    /**
     * Withdraw a mandate.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function cancel(string $mandateId, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw PaymentRefused::because(
                'Cancelling a mandate needs a reason. It ends a standing authority, and the next person '.
                'to ask why a customer is no longer on direct debit needs an answer that is not a '.
                'timestamp.',
            );
        }

        $mandate = DB::connection('tenant')->table('mandates')->where('id', $mandateId)->first();

        if ($mandate === null) {
            throw PaymentRefused::because('There is no mandate with that id.');
        }

        if ((string) $mandate->state !== 'active') {
            throw PaymentRefused::because(sprintf(
                'Mandate %s is already %s.',
                (string) $mandate->reference,
                (string) $mandate->state,
            ));
        }

        DB::connection('tenant')->table('mandates')->where('id', $mandateId)->update([
            'state' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancellation_reason' => trim($reason),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'settlement',
            action: 'mandate.cancelled',
            subjectType: 'mandate',
            subjectId: $mandateId,
            subjectLabel: (string) $mandate->reference,
            before: ['state' => 'active'],
            after: ['state' => 'cancelled'],
            reason: trim($reason),
            entityId: (string) $mandate->entity_id,
            partyId: (string) $mandate->party_id,
        );

        return [
            'id' => $mandateId,
            'state' => 'cancelled',
            'why' => sprintf(
                'Mandate %s is withdrawn. Collections under it are refused here rather than returned by '.
                'the bank days later, after the customer has seen one on their statement.',
                (string) $mandate->reference,
            ),
        ];
    }

    /**
     * The sequence type this mandate's next collection carries.
     *
     * Derived, so that no caller can produce the second `FRST` a bank
     * refuses. `OOFF` is a one-off with no recurrence at all and is a
     * different decision, taken when the mandate is signed rather than
     * computed here.
     */
    public function sequenceFor(string $mandateId, bool $final = false): string
    {
        $presented = DB::connection('tenant')->table('collections')
            ->where('mandate_id', $mandateId)
            /*
             | A RETURNED first collection still counts as seen.
             |
             | The debtor's bank has the mandate on file either way - the
             | collection failed, the registration did not. Presenting a
             | second FRST after a return is the commonest way a business
             | earns a scheme-level rejection on top of the one it already
             | had.
             */
            ->whereIn('sequence_type', ['FRST', 'RCUR'])
            ->exists();

        if ($final) {
            return 'FNAL';
        }

        return $presented ? 'RCUR' : 'FRST';
    }

    /** @return array<string, mixed> */
    public function show(string $mandateId): array
    {
        $mandate = DB::connection('tenant')->table('mandates')->where('id', $mandateId)->first();

        if ($mandate === null) {
            throw PaymentRefused::because('There is no mandate with that id.');
        }

        $collections = DB::connection('tenant')->table('collections')
            ->where('mandate_id', $mandateId)
            ->orderBy('collection_date')
            ->get();

        return [
            'id' => $mandateId,
            'reference' => (string) $mandate->reference,
            'scheme' => (string) $mandate->scheme,
            'state' => (string) $mandate->state,
            'signed_on' => (string) $mandate->signed_on,
            'expires_on' => $mandate->expires_on === null ? null : (string) $mandate->expires_on,
            'next_sequence' => (string) $mandate->state === 'active' ? $this->sequenceFor($mandateId) : null,
            'collections' => $collections->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'amount' => Money::of((string) $row->amount, (string) $row->currency),
                'collection_date' => (string) $row->collection_date,
                'sequence_type' => (string) $row->sequence_type,
                'state' => (string) $row->state,
                'return_reason_code' => $row->return_reason_code === null ? null : (string) $row->return_reason_code,
            ])->all(),
        ];
    }

    /**
     * The notification covering this collection, or a refusal.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws PaymentRefused
     */
    private function assertTheDebtorWasTold(
        object $mandate,
        array $definition,
        Money $amount,
        Carbon $collectionDate,
    ): void {
        $notification = DB::connection('tenant')->table('mandate_notifications')
            ->where('mandate_id', (string) $mandate->id)
            ->where('collection_date', $collectionDate->toDateString())
            ->where('currency', $amount->currency)
            /*
             | Covering THIS amount.
             |
             | The notification promised a figure. Collecting more than
             | was promised is not covered by it, and the debtor's refund
             | right runs for thirteen months - so a collection for more
             | needs its own notice. Less is fine: nobody disputes being
             | charged less than they were told.
             */
            ->where('amount', '>=', (string) $amount->amount)
            ->orderByDesc('notified_on')
            ->first();

        if ($notification !== null) {
            return;
        }

        $closest = DB::connection('tenant')->table('mandate_notifications')
            ->where('mandate_id', (string) $mandate->id)
            ->where('collection_date', $collectionDate->toDateString())
            ->orderByDesc('amount')
            ->first();

        throw PaymentRefused::because($closest === null
            ? sprintf(
                'The debtor has not been told about a collection on %s. %s requires %d days\' notice, '.
                'and a collection without it is one they can reverse without giving a reason for %d '.
                'months.',
                $collectionDate->toDateString(),
                (string) $definition['label'],
                (int) $definition['lead_days'],
                (int) $definition['refund_months'],
            )
            : sprintf(
                'The debtor was told %s would be collected on %s, and this is for %s. More than was '.
                'notified is not covered by the notice: it needs its own, %d days ahead, or the debtor '.
                'can reverse the whole collection for %d months.',
                (string) Money::of((string) $closest->amount, (string) $closest->currency)->toPresentation(),
                $collectionDate->toDateString(),
                (string) $amount->toPresentation(),
                (int) $definition['lead_days'],
                (int) $definition['refund_months'],
            ));
    }

    /** @throws PaymentRefused */
    private function liveMandate(string $mandateId): object
    {
        $mandate = DB::connection('tenant')->table('mandates')->where('id', $mandateId)->first();

        if ($mandate === null) {
            throw PaymentRefused::because('There is no mandate with that id.');
        }

        if ((string) $mandate->state !== 'active') {
            throw PaymentRefused::because(sprintf(
                'Mandate %s is %s, so there is no authority to collect under it.%s',
                (string) $mandate->reference,
                (string) $mandate->state,
                $mandate->cancellation_reason === null
                    ? ''
                    : ' It was withdrawn because '.(string) $mandate->cancellation_reason.'.',
            ));
        }

        return $mandate;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    private function scheme(string $key): array
    {
        $schemes = (array) config('settlement.collection_schemes', []);

        if (! \array_key_exists($key, $schemes)) {
            throw PaymentRefused::because(sprintf(
                '"%s" is not a collection scheme this release knows: %s.',
                $key,
                implode(', ', array_keys($schemes)),
            ));
        }

        return (array) $schemes[$key];
    }
}
