<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Verification;

use App\Support\Money\Money;
use App\Support\Verification\LedgerCheck;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Settlement\Services\TransitClearing;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * In transit equals released-and-unreconciled - PF-179.
 *
 * ## The failure this catches
 *
 * The in-transit control account holds 43,000 and the released
 * instructions nothing has cleared add to 31,000. The difference is
 * money the ledger says is on its way and no instruction accounts for -
 * a clearing posted twice, a rejection applied without reversing, a
 * manual journal somebody wrote to make a reconciliation balance.
 *
 * That is the point at which in transit stops being a list of payments
 * and becomes a number. A number cannot be aged, cannot be chased, and
 * cannot be cleared - and it sits in the middle of the three-way tie
 * between AP, the bank and the GL, so every bank reconciliation from
 * then on has a difference nobody can name.
 *
 * ## Critical, not a warning
 *
 * A control account that does not reconcile to its own subsidiary record
 * is the same class of fault as a receivables control that disagrees with
 * the customer ledger. Nobody would call that advice.
 */
final class TransitIsReleasedAndUnreconciled implements LedgerCheck
{
    public function __construct(private readonly TransitClearing $transit) {}

    public function name(): string
    {
        return 'payments in transit';
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
             | Read as text, summed in the database.
             |
             | A money total through PHP's float is a total that disagrees
             | with itself at the fourth decimal - which on a control
             | account reconciliation is indistinguishable from the fault
             | this check exists to find.
             */
            $row = DB::connection('tenant')->selectOne(
                'SELECT coalesce(sum(functional_amount), 0)::text AS balance '.
                'FROM journal_lines WHERE entity_id = ? AND account_id = ?',
                [(string) $entity->getKey(), $account],
            );

            /*
             | Negated, because in transit is a liability.
             |
             | The release credits it, so its ledger balance is negative
             | and the derivation is positive. Comparing them without the
             | sign would report every entity as broken by twice the
             | figure, which is the shape of mistake that gets a check
             | turned off.
             */
            $ledger = Money::of((string) ($row->balance ?? '0'), $currency)->negated();
            $expected = $this->transit->expected((string) $entity->getKey(), $currency);

            if ($ledger->equals($expected)) {
                continue;
            }

            $findings[] = [
                'severity' => 'critical',
                'kind' => 'payments in transit do not match the released instructions',
                'detail' => sprintf(
                    '%s: the in-transit control account holds %s and the released instructions nothing '.
                    'has cleared add to %s, a difference of %s.',
                    (string) $entity->code,
                    (string) $ledger->toPresentation(),
                    (string) $expected->toPresentation(),
                    (string) $ledger->minus($expected)->abs()->toPresentation(),
                ),
                'consequence' => 'In transit sits in the middle of the tie between accounts payable, the '.
                    'bank and the general ledger. A difference here is money the ledger says is on its '.
                    'way that no instruction accounts for - a clearing posted twice, a rejection applied '.
                    'without reversing, or a manual journal written to make a reconciliation balance. '.
                    'Until it is resolved every bank reconciliation has a difference nobody can name, '.
                    'and the position cannot be aged or chased because it is no longer a list of '.
                    'payments.',
            ];
        }

        return $findings;
    }

    private function accountFor(string $entityId): ?string
    {
        $id = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::PaymentsInTransit->value)
            ->value('account_id');

        return $id === null ? null : (string) $id;
    }
}
