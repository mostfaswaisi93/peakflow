<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Exceptions\ControlAccountNotConfigured;
use App\Tenant\Ledger\Models\AccountRole;
use Illuminate\Support\Carbon;

/**
 * What a rule is allowed to know - PF-047.
 *
 * Deliberately small. A rule gets the entity, its functional currency, the
 * accounting date and a way to resolve a control role; it does not get the
 * container, the request, or the ledger's models.
 *
 * The narrowness is the point: a rule that can reach anything will eventually
 * reach into another module, and the seam that keeps the ledger ignorant of
 * invoices only works while it is narrow enough that crossing it is obvious.
 */
final class PostingContext
{
    /** @var array<string, string> role => account id, resolved once per posting */
    private array $resolved = [];

    public function __construct(
        public readonly string $entityId,
        public readonly string $functionalCurrency,
        public readonly Carbon $accountingDate,
    ) {}

    /**
     * The account playing a control role in THIS entity.
     *
     * Refuses rather than returning null: a rule that silently skips a
     * receivable line produces an entry that balances without recording the
     * debt, and the subledger and the ledger disagree from then on.
     *
     * @throws ControlAccountNotConfigured
     */
    public function account(ControlRole $role): string
    {
        if (isset($this->resolved[$role->value])) {
            return $this->resolved[$role->value];
        }

        $accountId = AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->value('account_id');

        if ($accountId === null) {
            throw ControlAccountNotConfigured::forRole($role);
        }

        return $this->resolved[$role->value] = (string) $accountId;
    }

    /** A zero in the functional currency, for accumulating. */
    public function zero(): Money
    {
        return Money::zero($this->functionalCurrency);
    }

    public function money(string $amount): Money
    {
        return Money::of($amount, $this->functionalCurrency);
    }
}
