<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Verification;

use App\Support\Verification\LedgerCheck;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Tenancy\Models\Entity;

/**
 * Every riyal in suspense traces to a bank line - PF-163.
 *
 * ## The failure this catches
 *
 * Suspense holds 17,400 and the parked bank items add to 12,900. The
 * difference is money somebody posted to suspense by hand - a manual
 * journal, a correction, an import that did not know where else to put
 * something - and it is money no list accounts for.
 *
 * That is how a suspense balance stops being a list of items awaiting
 * identification and becomes a number. A number cannot be aged, cannot be
 * assigned to anybody, and cannot be cleared, because clearing requires
 * knowing what the item was. It sits there for years, and every period
 * close either steps over it or stops on it, and nobody can do anything
 * about it either way.
 *
 * ## Critical, not a warning
 *
 * A warning is advice. This is a control account that does not reconcile to
 * its own subsidiary record, which is the same class of fault as a
 * receivables control that does not agree with the customer ledger - and
 * nobody would call that a warning.
 */
final class SuspenseIsTraceable implements LedgerCheck
{
    public function __construct(private readonly Suspense $suspense) {}

    public function name(): string
    {
        return 'suspense';
    }

    /** @return list<array{severity: string, kind: string, detail: string, consequence: string}> */
    public function findings(?string $entityId): array
    {
        $entities = Entity::query()
            ->when($entityId !== null, fn ($q) => $q->where('id', $entityId))
            ->get();

        $findings = [];

        foreach ($entities as $entity) {
            $result = $this->suspense->reconciles(
                (string) $entity->getKey(),
                (string) $entity->functional_currency,
            );

            if ($result['agrees']) {
                continue;
            }

            $findings[] = [
                'severity' => 'critical',
                'kind' => sprintf('suspense in %s', (string) $entity->code),
                'detail' => $result['why'],
                'consequence' => 'a suspense balance no list of items accounts for cannot be aged, '.
                    'assigned or cleared, because clearing an item means knowing what it was - so '.
                    'it stays there, and every period close either steps over it or stops on it',
            ];
        }

        return $findings;
    }
}
