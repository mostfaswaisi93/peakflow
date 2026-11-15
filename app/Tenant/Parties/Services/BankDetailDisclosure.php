<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Parties\Exceptions\DisclosureRefused;
use App\Tenant\Parties\Models\PartyBankAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reading a bank account, and recording that somebody did - PF-035.
 *
 * ## Who LOOKED at a bank account is an auditable event
 *
 * Not only who changed it. The fraud this guards against does not need a
 * write: somebody who can read a supplier's IBAN can use it anywhere, and the
 * change log would show nothing because nothing changed.
 *
 * It is also the question an investigation starts from. "Who had this
 * number" is answerable only if every disclosure was recorded, and a log that
 * records writes alone answers it with silence - which reads as "nobody",
 * which is worse than "we do not know".
 *
 * ## The audit entry is written BEFORE the value is returned
 *
 * Not after. A disclosure that fails to audit must fail to disclose, and
 * returning first and recording second means an exception between them hands
 * out the number with no record. The order here is the only one where the
 * failure mode is a recorded read that did not happen, which is a confusing
 * log entry rather than an unrecorded disclosure.
 *
 * ## The number never reaches the log
 *
 * The entry says which account was read, by whom, for what. It does not say
 * what the number is - a log that copies the secret it is protecting has
 * doubled the number of places it can leak from, and the audit log is the one
 * place nobody can delete from.
 *
 * ## Why a service and not a method on the model
 *
 * Because the model's `revealIban()` is the raw accessor, and an architecture
 * rule confines its callers to this class. The rule is what makes the control
 * real: a future payment-file builder reaching for the model directly fails
 * the build rather than reading a supplier's account with no trace.
 */
final class BankDetailDisclosure
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * The IBAN, with the read recorded.
     *
     * @param  string  $reason  what it is being read FOR: a payment run, a
     *     reconciliation, a support request. The question an investigation
     *     asks is not "was it read" but "why", and a reason nobody typed is
     *     a question nobody can answer.
     *
     * @throws DisclosureRefused
     */
    public function revealIban(PartyBankAccount $account, string $reason): string
    {
        $this->assertMayRead($account, $reason);

        $iban = $account->revealIban();

        if ($iban === null) {
            throw DisclosureRefused::noIban($account->masked());
        }

        $this->recordTheRead($account, 'iban', $reason);

        return $iban;
    }

    /**
     * The domestic account number, with the read recorded.
     *
     * @throws DisclosureRefused
     */
    public function revealAccountNumber(PartyBankAccount $account, string $reason): string
    {
        $this->assertMayRead($account, $reason);

        $number = $account->revealAccountNumber();

        if ($number === null) {
            throw DisclosureRefused::noAccountNumber($account->masked());
        }

        $this->recordTheRead($account, 'account_number', $reason);

        return $number;
    }

    /**
     * How many times an account has been read, and by whom.
     *
     * For the party screen, so the people who own the relationship can see
     * it. A read log nobody can see is a read log that deters nobody - and
     * deterrence is most of what it is for.
     *
     * @return list<array{at: string, actor: ?string, field: string, reason: string}>
     */
    public function readsOf(PartyBankAccount $account): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT created_at, actor_name, after_state, reason
            FROM audit_log
            WHERE category = 'read'
              AND action = 'party.bank_account.disclosed'
              AND subject_id = cast(? AS char(26))
            ORDER BY created_at DESC
            LIMIT 200
        SQL, [(string) $account->getKey()]);

        return array_map(static function (object $row): array {
            $after = json_decode((string) ($row->after_state ?? '{}'), true);

            return [
                'at' => (string) $row->created_at,
                'actor' => $row->actor_name === null ? null : (string) $row->actor_name,
                'field' => (string) ($after['field'] ?? 'unknown'),
                'reason' => (string) ($row->reason ?? ''),
            ];
        }, $rows);
    }

    /**
     * @throws DisclosureRefused
     */
    private function assertMayRead(PartyBankAccount $account, string $reason): void
    {
        /*
         | `bank.manage` rather than a read permission of its own.
         |
         | Reading a supplier's account and changing it are the same risk:
         | somebody who can see the number can use it anywhere, and the
         | change log would show nothing because nothing changed. A separate
         | and weaker "may view" permission would be handed out widely for
         | convenience, which is precisely how the number escapes.
         */
        if (Gate::denies('bank.manage')) {
            throw DisclosureRefused::notPermitted($account->masked());
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw DisclosureRefused::needsAReason($account->masked());
        }
    }

    private function recordTheRead(PartyBankAccount $account, string $field, string $reason): void
    {
        $this->audit->record(
            /*
             | The `read` category, which is what makes these findable.
             |
             | An investigation asking "who saw this account" filters on one
             | category rather than reading every entry for the party - and
             | the log is indexed on (category, created_at) for exactly that
             | shape of question.
             */
            category: 'read',
            action: 'party.bank_account.disclosed',
            subjectType: PartyBankAccount::class,
            subjectId: (string) $account->getKey(),
            /*
             | The MASKED form as the label, never the number.
             |
             | A log that copies the secret it is protecting has doubled the
             | number of places it can leak from - and this is the one place
             | nobody can delete from.
             */
            subjectLabel: $account->masked(),
            after: [
                'field' => $field,
                'country' => (string) $account->country,
                'currency' => (string) $account->currency,
                'last_four' => (string) $account->last_four,
            ],
            reason: $reason,
            entityId: (string) $account->entity_id,
            partyId: (string) $account->party_id,
        );
    }
}
