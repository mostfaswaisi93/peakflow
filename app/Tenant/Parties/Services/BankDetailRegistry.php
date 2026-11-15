<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Services;

use App\Tenant\Identity\Services\AuditLog;
use App\Tenant\Parties\Exceptions\BankAccountNotVerified;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyBankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Records and verifies where a party's money goes - PF-061.
 *
 * ## The control is the second person, not the encryption
 *
 * Encryption protects the number from being read. It does nothing against
 * the actual attack, which is an authorised user - or someone with their
 * session - legitimately changing the account to one they control and then
 * releasing a payment to it.
 *
 * What stops that is the pair of rules: the change marks the account unusable
 * until a SECOND person confirms it, and the segregation-of-duties engine
 * refuses to let whoever changed it release the payment. Neither can be
 * overridden, because an override is exactly what an insider would request.
 *
 * ## "A channel the system did not supply"
 *
 * The verifier records HOW they confirmed. Ringing the number on an email
 * asking for the change is not verification - the email is the attack. The
 * number on last year's invoice, or on the supplier's published website, is.
 *
 * The system cannot enforce which channel was used, so it records the claim
 * and makes it visible: an auditor reading "confirmed by email" beside a
 * fraudulent payment knows immediately what happened, and the person who
 * typed it knew they were typing it.
 */
final class BankDetailRegistry
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Record a new or changed account.
     *
     * Always lands unverified. There is no path that writes a verified
     * account in one step, because that path would be the one every import
     * and every API client used.
     */
    public function record(
        Party $party,
        ?string $iban = null,
        ?string $accountNumber = null,
        ?string $country = null,
        ?string $bic = null,
        ?string $label = null,
        ?PartyBankAccount $existing = null,
    ): PartyBankAccount {
        return DB::connection('tenant')->transaction(function () use (
            $party,
            $iban,
            $accountNumber,
            $country,
            $bic,
            $label,
            $existing,
        ): PartyBankAccount {
            $account = $existing ?? new PartyBankAccount(['party_id' => $party->getKey()]);

            // The BEFORE state, masked. The audit log records that an account
            // changed and which one; it never records the number itself,
            // because an audit log is read by more people than the account is.
            $before = $account->exists
                ? ['last_four' => $account->last_four, 'bic' => $account->bic, 'country' => $account->country]
                : null;

            if ($iban !== null) {
                $account->setIban($iban);
            }

            if ($accountNumber !== null) {
                $account->setAccountNumber($accountNumber, $country ?? $party->country);
            }

            $account->fill(array_filter([
                'party_id' => $party->getKey(),
                'label' => $label,
                'bic' => $bic,
            ], static fn ($value): bool => $value !== null));

            // Explicit, not only via the trigger: a NEW account has nothing
            // to compare against, so the trigger would not fire, and an
            // account that arrived already usable is the hole.
            $account->requires_reverification = true;
            $account->verified_by_user_id = null;
            $account->verified_at = null;
            $account->verification_channel = null;

            $account->save();

            $this->audit->record(
                category: 'party',
                action: 'supplier.bank_details.update',
                subjectType: PartyBankAccount::class,
                subjectId: (string) $account->getKey(),
                subjectLabel: $party->legal_name,
                before: $before,
                after: ['last_four' => $account->last_four, 'bic' => $account->bic, 'country' => $account->country],
                reason: $before === null
                    ? 'a new bank account was added and is unusable until a second person verifies it'
                    : 'the bank account changed and is unusable until a second person verifies it',
                partyId: (string) $party->getKey(),
            );

            return $account;
        });
    }

    /**
     * A second person confirms the account.
     *
     * Refuses if the confirmer is the person who changed it. That check is
     * here as well as in the SoD engine because this is the moment it is
     * cheapest to explain, and because an account verified by its own author
     * is not verified at all.
     *
     * @throws BankAccountNotVerified
     */
    public function verify(
        PartyBankAccount $account,
        string $verifierUserId,
        string $channel,
    ): PartyBankAccount {
        $changedBy = $this->whoLastChanged($account);

        if ($changedBy !== null && $changedBy === $verifierUserId) {
            $this->audit->refusal(
                category: 'party',
                action: 'supplier.bank_details.verify.refused',
                subjectType: PartyBankAccount::class,
                subjectId: (string) $account->getKey(),
                subjectLabel: (string) $account->party?->legal_name,
                reason: 'the verifier is the person who changed the account',
                partyId: (string) $account->party_id,
            );

            throw BankAccountNotVerified::bySameUser();
        }

        $account->forceFill([
            'requires_reverification' => false,
            'verified_by_user_id' => $verifierUserId,
            'verified_at' => now(),
            'verification_channel' => $channel,
        ])->save();

        $this->audit->record(
            category: 'party',
            action: 'supplier.bank_details.verified',
            subjectType: PartyBankAccount::class,
            subjectId: (string) $account->getKey(),
            subjectLabel: (string) $account->party?->legal_name,
            after: ['last_four' => $account->last_four, 'channel' => $channel],
            // The channel is recorded as a CLAIM. The system cannot know
            // whether they really rang the number on an old invoice; what it
            // can do is put the claim beside the payment, where an auditor
            // will read it.
            reason: sprintf('confirmed through: %s', $channel),
            partyId: (string) $account->party_id,
        );

        return $account->refresh();
    }

    /**
     * Refuse a payment to an unverified account - PF-061.
     *
     * @throws BankAccountNotVerified
     */
    public function assertUsable(PartyBankAccount $account): void
    {
        if ($account->isUsableForPayment()) {
            return;
        }

        $this->audit->refusal(
            category: 'party',
            action: 'payment.release.refused',
            subjectType: PartyBankAccount::class,
            subjectId: (string) $account->getKey(),
            subjectLabel: (string) $account->party?->legal_name,
            reason: $account->active
                ? 'the bank account has changed and has not been verified by a second person'
                : 'the bank account is not active',
            partyId: (string) $account->party_id,
        );

        throw $account->active
            ? BankAccountNotVerified::sinceItChanged($account->masked())
            : BankAccountNotVerified::becauseItIsInactive($account->masked());
    }

    /**
     * Who last changed this account, from the audit log.
     *
     * Read from the log rather than from a column on the account, because a
     * column can be set by whatever writes the row - including the thing
     * trying to get round this - and the log cannot be edited at all.
     */
    private function whoLastChanged(PartyBankAccount $account): ?string
    {
        $row = DB::connection('tenant')->table('audit_log')
            ->where('action', 'supplier.bank_details.update')
            ->where('subject_id', (string) $account->getKey())
            ->orderByDesc('created_at')
            ->first(['actor_user_id']);

        $actor = $row?->actor_user_id;

        return is_string($actor) ? trim($actor) : null;
    }
}
