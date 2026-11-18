<?php

declare(strict_types=1);

namespace App\Tenant\Messaging\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Messaging\Exceptions\MessageRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who agreed to be sent what, and on which number - PF-257.
 *
 * ## Consent and reachability are two different facts
 *
 * Consent is a decision the customer made. Reachability is a fact about
 * the number. Both are required before anything is sent, and neither
 * implies the other: a customer can agree and give a number with a
 * digit missing, and that digit belongs to somebody else who then
 * receives an invoice with a name and an amount on it.
 *
 * ## Withdrawal is a date, not a deletion
 *
 * "Did we have consent in March" is asked after a complaint, and a row
 * that was removed answers it with silence. So withdrawal sets a date
 * and a reason, and the row stays.
 *
 * ## The number is normalised to E.164 on the way in
 *
 * `+966 50 123 4567`, `00966501234567` and `0501234567` are one number
 * written three ways. A product that stored what somebody typed would
 * have consent for one spelling, refuse the next, and fail to match the
 * reply when it arrived.
 */
final class Consents
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Record that somebody agreed, and how.
     *
     * @return array<string, mixed>
     *
     * @throws MessageRefused
     */
    public function record(
        string $entityId,
        string $partyId,
        string $channel,
        string $address,
        string $basis,
        string $userId,
        ?Carbon $on = null,
    ): array {
        if (mb_strlen(trim($basis)) < 10) {
            throw MessageRefused::because(
                'Consent is recorded with HOW it was given: "ticked the box on the portal", "said so '.
                'on the telephone on the 3rd", "signed the contract clause". A regulator asking about '.
                'consent is asking exactly that, and a boolean answers none of it.',
            );
        }

        $normalised = $channel === 'email' ? trim($address) : $this->e164($address);

        $existing = DB::connection('tenant')->table('messaging_consents')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->where('channel', $channel)
            ->where('address', $normalised)
            ->first();

        if ($existing !== null && $existing->withdrawn_at === null) {
            return [
                'id' => (string) $existing->id,
                'address' => $normalised,
                'verified' => $existing->verified_at !== null,
                'why' => 'Consent was already on record for this number and channel.',
            ];
        }

        $id = (string) Str::ulid();
        $at = ($on ?? Carbon::now());

        if ($existing !== null) {
            /*
             | Re-consenting after a withdrawal is a NEW row, not a
             | cleared flag.
             |
             | The unique index is on (entity, party, channel, address),
             | so the withdrawn one has to go - but its facts are
             | carried into the audit trail first, because "did we have
             | consent in March" has to stay answerable.
             */
            $this->audit->record(
                category: 'messaging',
                action: 'consent.superseded',
                subjectType: 'messaging_consent',
                subjectId: (string) $existing->id,
                subjectLabel: $normalised,
                before: [
                    'consented_at' => (string) $existing->consented_at,
                    'withdrawn_at' => (string) $existing->withdrawn_at,
                    'withdrawn_reason' => (string) $existing->withdrawn_reason,
                ],
                after: ['consented_at' => $at->toDateTimeString()],
                reason: trim($basis),
                entityId: $entityId,
                partyId: $partyId,
            );

            DB::connection('tenant')->table('messaging_consents')
                ->where('id', (string) $existing->id)
                ->delete();
        }

        DB::connection('tenant')->table('messaging_consents')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'party_id' => $partyId,
            'channel' => $channel,
            'address' => $normalised,
            'consented_at' => $at->toDateTimeString(),
            'consent_basis' => trim($basis),
            'recorded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'messaging',
            action: 'consent.recorded',
            subjectType: 'messaging_consent',
            subjectId: $id,
            subjectLabel: sprintf('%s on %s', $normalised, $channel),
            after: ['address' => $normalised, 'channel' => $channel, 'consented_at' => $at->toDateTimeString()],
            reason: trim($basis),
            entityId: $entityId,
            partyId: $partyId,
        );

        return [
            'id' => $id,
            'address' => $normalised,
            'verified' => false,
            'why' => sprintf(
                'Consent recorded for %s on %s. Nothing will be sent until the number is verified as '.
                'reachable - consent is a decision the customer made, and reachability is a fact about '.
                'the number.',
                $normalised,
                $channel,
            ),
        ];
    }

    /**
     * Mark a number verified, against the code that was sent to it.
     *
     * @throws MessageRefused
     */
    public function verify(string $consentId, string $code): void
    {
        $consent = DB::connection('tenant')->table('messaging_consents')->where('id', $consentId)->first();

        if ($consent === null) {
            throw MessageRefused::because('There is no consent record with that id.');
        }

        if ($consent->withdrawn_at !== null) {
            throw MessageRefused::withdrawnConsent(
                (string) $consent->address,
                (string) $consent->withdrawn_at,
                (string) $consent->withdrawn_reason,
            );
        }

        if (mb_strlen(trim($code)) < 4) {
            throw MessageRefused::because(
                'A verification needs the code that was sent to the number, because the code arriving '.
                'back is the only evidence that the number belongs to the person who gave it.',
            );
        }

        DB::connection('tenant')->table('messaging_consents')->where('id', $consentId)->update([
            'verified_at' => now(),
            'verified_by_code' => trim($code),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'messaging',
            action: 'consent.verified',
            subjectType: 'messaging_consent',
            subjectId: $consentId,
            subjectLabel: (string) $consent->address,
            after: ['verified_at' => now()->toDateTimeString()],
            entityId: (string) $consent->entity_id,
            partyId: (string) $consent->party_id,
        );
    }

    /** @throws MessageRefused */
    public function withdraw(string $consentId, string $reason, string $userId): void
    {
        $consent = DB::connection('tenant')->table('messaging_consents')->where('id', $consentId)->first();

        if ($consent === null) {
            throw MessageRefused::because('There is no consent record with that id.');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw MessageRefused::because(
                'A withdrawal is recorded with its reason: the customer asked, the number stopped '.
                'working, somebody complained. The three lead to different conversations later.',
            );
        }

        DB::connection('tenant')->table('messaging_consents')->where('id', $consentId)->update([
            'withdrawn_at' => now(),
            'withdrawn_reason' => trim($reason),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'messaging',
            action: 'consent.withdrawn',
            subjectType: 'messaging_consent',
            subjectId: $consentId,
            subjectLabel: (string) $consent->address,
            before: ['consented_at' => (string) $consent->consented_at],
            after: ['withdrawn_at' => now()->toDateTimeString()],
            reason: trim($reason),
            entityId: (string) $consent->entity_id,
            partyId: (string) $consent->party_id,
        );
    }

    /**
     * The address to send to, or a refusal saying why there is none.
     *
     * @throws MessageRefused
     */
    public function addressFor(string $entityId, string $partyId, string $channel): string
    {
        $consent = DB::connection('tenant')->table('messaging_consents')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->where('channel', $channel)
            ->orderByDesc('consented_at')
            ->first();

        if ($consent === null) {
            throw MessageRefused::withoutConsent('this party', $channel);
        }

        if ($consent->withdrawn_at !== null) {
            throw MessageRefused::withdrawnConsent(
                (string) $consent->address,
                (string) $consent->withdrawn_at,
                (string) $consent->withdrawn_reason,
            );
        }

        if ($consent->verified_at === null) {
            throw MessageRefused::unverified((string) $consent->address);
        }

        return (string) $consent->address;
    }

    /** Which party a number belongs to, or null when nobody knows. */
    public function partyAt(string $entityId, string $channel, string $address): ?string
    {
        $id = DB::connection('tenant')->table('messaging_consents')
            ->where('entity_id', $entityId)
            ->where('channel', $channel)
            ->where('address', $channel === 'email' ? trim($address) : $this->e164($address))
            ->value('party_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * One number, one spelling.
     *
     * Digits only, with a leading plus. `00` is the international
     * prefix in most of the world and `+` is what E.164 wants; a local
     * number with a leading zero and no country code cannot be
     * normalised at all, and is refused rather than guessed - guessing
     * the country is how an invoice goes to the same digits in another
     * one.
     *
     * @throws MessageRefused
     */
    private function e164(string $address): string
    {
        $digits = (string) preg_replace('/[^0-9+]/', '', $address);

        if (str_starts_with($digits, '00')) {
            $digits = '+'.mb_substr($digits, 2);
        }

        if (! str_starts_with($digits, '+')) {
            throw MessageRefused::because(sprintf(
                '"%s" has no country code, so it cannot be compared against what a provider reports or '.
                'what a reply arrives from. Guessing the country is how an invoice reaches the same '.
                'digits somewhere else.',
                $address,
            ));
        }

        $normalised = '+'.preg_replace('/[^0-9]/', '', mb_substr($digits, 1));

        if (preg_match('/^\+[1-9][0-9]{6,14}$/', $normalised) !== 1) {
            throw MessageRefused::because(sprintf(
                '"%s" is not a telephone number this product can send to. E.164 allows a country code '.
                'and up to fifteen digits in total.',
                $address,
            ));
        }

        return $normalised;
    }
}
