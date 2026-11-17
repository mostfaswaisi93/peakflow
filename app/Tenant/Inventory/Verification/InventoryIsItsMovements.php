<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Verification;

use App\Support\Money\Money;
use App\Support\Verification\LedgerCheck;
use App\Tenant\Inventory\Services\Stock;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * The inventory account equals what the movements are worth - PF-248.
 *
 * ## The failure this catches
 *
 * The inventory control account holds 184,000 and the movements add to
 * 171,500. The difference is stock the ledger says exists and no movement
 * accounts for - a movement written without its posting, a posting made
 * by hand to force a balance sheet to agree, an import that brought the
 * value and not the entry.
 *
 * That is the point at which stock stops being a list of things and
 * becomes a number. A number cannot be counted, so every stock count from
 * then on produces a variance that is really the difference plus the real
 * variance, and the write-off each quarter is called shrinkage.
 *
 * ## Critical, not a warning
 *
 * A control account that does not reconcile to its own subsidiary record
 * is the same class of fault as a receivables control that disagrees with
 * the customer ledger. Nobody would call that advice.
 */
final class InventoryIsItsMovements implements LedgerCheck
{
    public function __construct(private readonly Stock $stock) {}

    public function name(): string
    {
        return 'inventory';
    }

    /** @return list<array{severity: string, kind: string, detail: string, consequence: string}> */
    public function findings(?string $entityId): array
    {
        $findings = [];

        $entities = Entity::query()
            ->when($entityId !== null, fn ($q) => $q->where('id', $entityId))
            ->get();

        foreach ($entities as $entity) {
            $currency = (string) $entity->functional_currency;
            $account = $this->accountFor((string) $entity->getKey());

            if ($account === null) {
                continue;
            }

            /*
             | Summed in the database and read as text.
             |
             | A money total through a float is a total that disagrees with
             | itself at the fourth decimal, which on a control-account
             | reconciliation is indistinguishable from the fault this
             | check exists to find.
             */
            $row = DB::connection('tenant')->selectOne(
                'SELECT coalesce(sum(functional_amount), 0)::text AS balance '.
                'FROM journal_lines WHERE entity_id = ? AND account_id = ?',
                [(string) $entity->getKey(), $account],
            );

            $ledger = Money::of((string) ($row->balance ?? '0'), $currency);
            $movements = $this->stock->value((string) $entity->getKey(), $currency);

            if ($ledger->equals($movements)) {
                continue;
            }

            $findings[] = [
                'severity' => 'critical',
                'kind' => 'the inventory account does not equal its movements',
                'detail' => sprintf(
                    '%s: the inventory control account holds %s and the stock movements add to %s, a '.
                    'difference of %s.',
                    (string) $entity->code,
                    (string) $ledger->toPresentation(),
                    (string) $movements->toPresentation(),
                    (string) $ledger->minus($movements)->abs()->toPresentation(),
                ),
                'consequence' => 'A difference here is stock the ledger says exists that no movement '.
                    'accounts for - a movement written without its posting, a journal made by hand to '.
                    'force a balance sheet to agree, or an import that brought the value and not the '.
                    'entry. Until it is resolved the stock figure cannot be counted: every count '.
                    'produces this difference plus the real variance, and the write-off each quarter '.
                    'gets called shrinkage.',
            ];
        }

        return $findings;
    }

    private function accountFor(string $entityId): ?string
    {
        $id = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::Inventory->value)
            ->value('account_id');

        return $id === null ? null : (string) $id;
    }
}
