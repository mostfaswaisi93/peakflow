<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Database\DatabaseRefusal;
use App\Support\Iso\Bic;
use App\Support\Iso\Domestic\DomesticScheme;
use App\Support\Iso\Iban;
use App\Tenant\Banking\Exceptions\BankAccountRefused;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opening a bank account - PF-145, PF-146.
 *
 * ## The identifier is validated before it is stored, not when it is used
 *
 * An invalid IBAN cannot be stored. {@see Iban} checks the country's declared
 * length and the mod-97 check digits, which exist precisely so that a typo is
 * caught before the money moves - and the place to catch it is where somebody
 * typed it, not in a payment file three weeks later.
 *
 * Where IBAN does not apply - the United States, Canada, Australia, India,
 * which between them are most of the world's payment traffic - a domestic
 * scheme validates instead. A schema that modelled only IBAN would make
 * those accounts unrepresentable, and the usual workaround is an IBAN column
 * with a routing number in it, which breaks every validation that column
 * exists for.
 *
 * ## The GL account must already carry the `bank` role
 *
 * Not created here, and not given the role here. The chart is the ledger's
 * and a service that added a control role to an account as a side effect of
 * opening a bank account would be writing the chart from the banking module -
 * which is how an entity ends up with four accounts carrying the `bank` role
 * and no idea which the trial balance means.
 *
 * ## The refusals are the interesting part
 *
 * A shared account, a GL link to something that is not a bank account, an
 * opening balance changed after a statement arrived. Each is somebody
 * modelling a real arrangement in the only way the software appeared to
 * allow, so each refusal says what to do instead.
 */
final class BankAccounts
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Open an account.
     *
     * @throws BankAccountRefused
     */
    public function open(
        Entity $entity,
        string $label,
        string $institutionName,
        string $institutionCountry,
        string $holderLegalName,
        string $currency,
        string $glAccountId,
        Carbon $openingDate,
        string $openingBalance = '0',
        ?string $iban = null,
        ?string $bic = null,
        ?string $domesticScheme = null,
        ?string $domesticIdentifier = null,
        ?string $accountNumber = null,
        ?string $branch = null,
        ?string $userId = null,
        ?string $note = null,
    ): BankAccount {
        $entityId = (string) $entity->getKey();

        $this->assertTheGlAccountIsABankAccount($glAccountId, $entityId);

        if ($iban === null && $domesticIdentifier === null) {
            throw BankAccountRefused::because(
                'An account needs an IBAN or a domestic identifier. A record with neither is an '.
                'account nobody can pay into, which looks complete and is discovered on the day '.
                'somebody tries to pay from it.',
            );
        }

        $account = new BankAccount([
            'entity_id' => $entityId,
            'label' => $label,
            'status' => 'active',
            'institution_name' => $institutionName,
            'institution_country' => strtoupper($institutionCountry),
            'branch' => $branch,
            'holder_legal_name' => $holderLegalName,
            'currency' => strtoupper($currency),
            'gl_account_id' => $glAccountId,
            'opening_balance' => $openingBalance,
            'opening_date' => $openingDate->toDateString(),
            'created_by' => $userId,
            'note' => $note,
        ]);

        if ($iban !== null) {
            $account->setIban($iban);
            $this->assertTheIbanSuitsTheCountry($iban, $institutionCountry);
        }

        if ($bic !== null) {
            if (Bic::tryFrom($bic) === null) {
                throw BankAccountRefused::because(sprintf(
                    '"%s" is not a valid BIC. ISO 9362 is eight characters for an institution or '.
                    'eleven for a branch, and nothing in between - a nine-character value is a typo '.
                    'somebody will send money with.',
                    $bic,
                ));
            }

            $account->bic = strtoupper($bic);
        }

        if ($domesticIdentifier !== null) {
            $scheme = $this->domesticSchemeFor($domesticScheme, $institutionCountry);

            $parsed = $scheme->tryParse($domesticIdentifier);

            if ($parsed === null) {
                throw BankAccountRefused::because(sprintf(
                    '"%s" is not a valid %s. The scheme\'s own value object refused it%s.',
                    $domesticIdentifier,
                    $scheme->label(),
                    $scheme->hasCheckDigit()
                        ? ', and it carries a check digit - so this is a transposition rather than a '.
                          'length'
                        : '',
                ));
            }

            $account->setDomesticIdentifier($scheme->value, (string) $parsed);
        }

        if ($accountNumber !== null) {
            $account->setAccountNumber($accountNumber);
        }

        try {
            $account->save();
        } catch (QueryException $e) {
            throw BankAccountRefused::because(DatabaseRefusal::sentence($e->getMessage()));
        }

        $this->audit->record(
            category: 'banking',
            action: 'bank_account.opened',
            subjectType: 'bank_account',
            subjectId: (string) $account->getKey(),
            subjectLabel: $account->shortIdentifier(),
            after: [
                /*
                 | The identifier is NOT in the audit record.
                 |
                 | An audit log is read by more people than the account
                 | screen is, exported more often, and retained longer. The
                 | tail identifies the account for anybody who needs to know
                 | which one this was.
                 */
                'institution' => $institutionName,
                'currency' => strtoupper($currency),
                'gl_account_id' => $glAccountId,
                'opening_balance' => $openingBalance,
                'opening_date' => $openingDate->toDateString(),
                'identifier_tail' => $account->iban_tail ?? $account->domestic_identifier_tail,
            ],
            entityId: $entityId,
        );

        return $account->refresh();
    }

    /**
     * Change the opening balance, while that is still possible.
     *
     * Possible only before the first statement: it is the one figure in the
     * account that nothing else can reconstruct, so changing it afterwards
     * puts every reconciliation since the first import out by the difference
     * with no document recording why. The database refuses it too.
     *
     * @throws BankAccountRefused
     */
    public function correctOpeningBalance(
        BankAccount $account,
        string $balance,
        Carbon $openingDate,
        string $userId,
        string $reason,
    ): BankAccount {
        if (\strlen(trim($reason)) <= 10) {
            throw BankAccountRefused::because(
                'Correcting an opening balance needs a reason. It is the figure every subsequent '.
                'reconciliation is measured from, and "corrected" with nothing behind it is what '.
                'somebody finds while asking why the first month does not tie.',
            );
        }

        $before = (string) $account->opening_balance;

        try {
            $account->update([
                'opening_balance' => $balance,
                'opening_date' => $openingDate->toDateString(),
            ]);
        } catch (QueryException $e) {
            throw BankAccountRefused::because(DatabaseRefusal::sentence($e->getMessage()));
        }

        $this->audit->record(
            category: 'banking',
            action: 'bank_account.opening_balance_corrected',
            subjectType: 'bank_account',
            subjectId: (string) $account->getKey(),
            subjectLabel: $account->shortIdentifier(),
            before: ['opening_balance' => $before],
            after: [
                'opening_balance' => $balance,
                'opening_date' => $openingDate->toDateString(),
            ],
            reason: trim($reason),
            entityId: (string) $account->entity_id,
        );

        return $account->refresh();
    }

    /**
     * Close an account.
     *
     * Refused while the bank still says there is money in it. Closing it
     * would leave cash in an account nothing reports on, which is how a
     * balance comes to be discovered years later with nobody able to say
     * what it was for.
     *
     * @throws BankAccountRefused
     */
    public function close(BankAccount $account, string $userId, string $reason): BankAccount
    {
        if (\strlen(trim($reason)) <= 5) {
            throw BankAccountRefused::because('Closing an account needs a reason.');
        }

        $balance = app(BankBalances::class)->statement($account);

        if (! $balance->isZero()) {
            throw BankAccountRefused::because(sprintf(
                'Account %s still has a statement balance of %s %s. Closing it would leave cash in an '.
                'account nothing reports on: transfer the balance or record what became of it, then '.
                'close the account.',
                $account->shortIdentifier(),
                (string) $account->currency,
                (string) $balance->toPresentation(),
            ));
        }

        $account->update(['status' => 'closed']);

        $this->audit->record(
            category: 'banking',
            action: 'bank_account.closed',
            subjectType: 'bank_account',
            subjectId: (string) $account->getKey(),
            subjectLabel: $account->shortIdentifier(),
            before: ['status' => 'active'],
            after: ['status' => 'closed'],
            reason: trim($reason),
            entityId: (string) $account->entity_id,
        );

        return $account->refresh();
    }

    /**
     * Whether the GL account is one a bank account may mirror.
     *
     * ## The bank role names a subtree, not a single account
     *
     * `account_roles` is unique on `(entity_id, role)`, so exactly one
     * account per entity carries the `bank` role - which is right, because
     * the posting rules that credit "the bank" need one unambiguous target.
     *
     * But a business with a SAR operating account and a USD account is
     * entirely ordinary, and requiring the role on every bank account's GL
     * link would cap an entity at one bank account for ever. So a bank
     * account may mirror either the role account itself - the simple case,
     * one account, one chart line - or any postable account beneath it:
     *
     *     1.1.1.2     Bank              <- carries the role
     *     1.1.1.2.1   Al Rajhi, SAR     <- a bank account may mirror this
     *     1.1.1.2.2   SAB, USD          <- and this
     *
     * Beneath it rather than anywhere, because that is what keeps the
     * requirement meaningful: the subtree is the part of the chart the
     * trial balance reports as cash, and an account outside it is one the
     * ledger does not think is a bank account. Posting cash to a receivable
     * is how a cash figure comes to include money nobody has.
     *
     * Found while checking PF-145: the first version asked for the role
     * itself, which the unique index made a one-account-per-entity limit.
     *
     * ## This duplicates the trigger deliberately, and says something else
     *
     * The database guards all of this too, and it is the guarantee: a
     * service guard is what somebody bypasses with an `update()` in a
     * console command. What this adds is the sentence - the database states
     * the invariant, and the service says what to do about it. A check
     * asserts each layer by its own wording, because when the two messages
     * were near-identical the check could not tell that this guard had been
     * removed.
     *
     * @throws BankAccountRefused
     */
    private function assertTheGlAccountIsABankAccount(string $glAccountId, string $entityId): void
    {
        $row = DB::connection('tenant')->table('accounts as a')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.id', $glAccountId)
            ->select(['a.id', 'a.entity_id', 'a.code', 'a.path', 'a.type', 'a.postable', 'r.role'])
            ->first();

        if ($row === null) {
            throw BankAccountRefused::because(sprintf(
                'There is no GL account %s. A bank account mirrors one that already exists: the chart '.
                'is the ledger\'s, and creating an account as a side effect of opening a bank account '.
                'is how an entity ends up with four accounts carrying the bank role and no idea which '.
                'the trial balance means.',
                $glAccountId,
            ));
        }

        if ((string) $row->entity_id !== $entityId) {
            throw BankAccountRefused::because(sprintf(
                'GL account %s belongs to another entity. A bank account and the chart line it mirrors '.
                'are the same entity\'s, because the cash belongs to whichever entity holds the '.
                'account - and a link across the boundary makes both entities\' cash figures a guess.',
                (string) $row->code,
            ));
        }

        /*
         | A header account cannot be posted to, so it cannot be mirrored.
         |
         | The link exists to make the two balances comparable, and a GL
         | account nothing can post to has a ledger balance that is
         | permanently zero. The failure would surface on the first payment,
         | as a refused posting with nothing to do with banking.
         */
        if ((bool) $row->postable !== true) {
            throw BankAccountRefused::because(sprintf(
                'GL account %s is a header and nothing can post to it. A bank account mirrors a '.
                'postable account, because the point of the link is that the two balances can be '.
                'compared - and a header\'s ledger balance is permanently zero. Add a postable '.
                'sub-account under it and mirror that.',
                (string) $row->code,
            ));
        }

        $role = DB::connection('tenant')->table('accounts as a')
            ->join('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.entity_id', $entityId)
            ->where('r.role', ControlRole::Bank->value)
            ->select(['a.id', 'a.code', 'a.path'])
            ->first();

        if ($role === null) {
            throw BankAccountRefused::because(
                'This entity\'s chart has no account carrying the "bank" role, so there is nothing '.
                'for a bank account to mirror. Assign the role to the chart\'s bank account first: '.
                'doing it the other way round leaves the ledger with a cash balance it does not know '.
                'is cash.',
            );
        }

        $isTheRoleAccount = (string) $row->id === (string) $role->id;
        $isBeneathIt = str_starts_with((string) $row->path, (string) $role->path.'.');

        if (! $isTheRoleAccount && ! $isBeneathIt) {
            throw BankAccountRefused::because(sprintf(
                'GL account %s is not the bank account %s, nor one of its sub-accounts%s. A bank '.
                'account mirrors a chart line the ledger reports as cash: posting cash to an account '.
                'the ledger does not know is a bank account is how a cash figure comes to include a '.
                'receivable. For a second bank account, add a sub-account under %s - the role itself '.
                'stays on the parent, because the posting rules that credit "the bank" need one '.
                'unambiguous target.',
                (string) $row->code,
                (string) $role->code,
                ($row->role ?? null) === null
                    ? ''
                    : sprintf(' - it carries the role "%s"', (string) $row->role),
                (string) $role->code,
            ));
        }

        $existing = BankAccount::query()->where('gl_account_id', $glAccountId)->first();

        if ($existing !== null) {
            throw BankAccountRefused::because(sprintf(
                'GL account %s already mirrors bank account %s. Two bank accounts sharing one GL '.
                'account cannot be reconciled separately: the ledger has one balance and the bank has '.
                'two, and no arithmetic recovers which part is which. Add a sub-account under it for '.
                'the second bank account.',
                (string) $row->code,
                $existing->shortIdentifier(),
            ));
        }
    }

    /**
     * Whether an IBAN's own country matches the institution's.
     *
     * A warning in most systems and a refusal here. An IBAN carries its
     * country in the first two characters, so a German IBAN against a Saudi
     * institution is one of two mistakes: the wrong IBAN, or the wrong
     * institution. Both are worth stopping, and neither is worth guessing
     * at.
     *
     * @throws BankAccountRefused
     */
    private function assertTheIbanSuitsTheCountry(string $iban, string $country): void
    {
        $parsed = Iban::tryFrom($iban);

        if ($parsed === null) {
            throw BankAccountRefused::invalidIban($iban);
        }

        $ibanCountry = substr((string) $parsed, 0, 2);

        if ($ibanCountry !== strtoupper($country)) {
            throw BankAccountRefused::because(sprintf(
                'The IBAN is a %s one and the institution is in %s. An IBAN carries its country in '.
                'its first two characters, so this is either the wrong IBAN or the wrong institution '.
                '- and a payment file built from the pair would be rejected by the first bank that '.
                'read it.',
                $ibanCountry,
                strtoupper($country),
            ));
        }
    }

    /**
     * @throws BankAccountRefused
     */
    private function domesticSchemeFor(?string $scheme, string $country): DomesticScheme
    {
        if ($scheme !== null) {
            $known = DomesticScheme::tryFrom($scheme);

            if ($known !== null) {
                return $known;
            }

            throw BankAccountRefused::because(sprintf(
                '"%s" is not a domestic scheme PeakFlow knows. The ones it does are: %s.',
                $scheme,
                implode(', ', array_map(
                    static fn (DomesticScheme $case): string => $case->value,
                    DomesticScheme::cases(),
                )),
            ));
        }

        $forCountry = DomesticScheme::forCountry(strtoupper($country));

        if ($forCountry === null) {
            throw BankAccountRefused::because(sprintf(
                'No domestic identifier scheme is known for %s, and no IBAN was given. %s uses IBAN '.
                'if it uses anything, so an account there is identified by one.',
                strtoupper($country),
                strtoupper($country),
            ));
        }

        return $forCountry;
    }
}
