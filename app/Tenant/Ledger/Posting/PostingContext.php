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
    public function account(ControlRole $role, ?string $jurisdiction = null): string
    {
        /*
         | A per-jurisdiction role without one is refused, and so is the
         | reverse - PF-084.
         |
         | Not defaulted to the entity's country: an entity registered in two
         | jurisdictions would then post one of them to the other's account,
         | and the figure would look plausible in both returns. A rule that
         | posts VAT has to say which authority's VAT it is.
         */
        if ($role->isPerJurisdiction() && ($jurisdiction === null || $jurisdiction === '')) {
            throw ControlAccountNotConfigured::needsAJurisdiction($role);
        }

        if (! $role->isPerJurisdiction() && $jurisdiction !== null) {
            throw ControlAccountNotConfigured::hasNoJurisdiction($role, $jurisdiction);
        }

        $key = $role->value.'@'.($jurisdiction ?? '');

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $accountId = AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->value('account_id');

        if ($accountId === null) {
            throw $jurisdiction === null
                ? ControlAccountNotConfigured::forRole($role)
                : ControlAccountNotConfigured::forRoleIn($role, $jurisdiction);
        }

        return $this->resolved[$key] = (string) $accountId;
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
