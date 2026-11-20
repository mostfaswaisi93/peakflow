<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Verification;

use App\Support\Verification\LedgerCheck;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\Services\TaxReconciler;
use App\Tenant\Tenancy\Models\Entity;

/**
 * VAT input and output tie to the returns filed from them - PF-054.
 *
 * ## Why this belongs in the global proof rather than in a report
 *
 * `TaxReconciler` has compared the control accounts to the return since
 * PF-088, and somebody had to open a screen to find out. The movement on
 * `vat_output` is what the business owes the authority; the return is
 * what it TOLD the authority it owes. A difference is money the
 * authority will eventually ask about, and the gap between "a report
 * exists" and "the difference is reported without being asked for" is
 * the difference between a control and a feature.
 *
 * ## Only filed periods
 *
 * An open period is expected to differ: documents are still arriving and
 * the control account moves before the return is drawn. Reporting that
 * would be reporting the normal state of the current month, which is how
 * a check becomes a line people scroll past.
 *
 * A FILED period is a statement made to an authority. After it, a
 * difference means either the ledger moved underneath a filed return or
 * the return was drawn from something other than the ledger - and both
 * are worth being woken for.
 *
 * ## Unexplained, not the raw difference
 *
 * The two sides are assembled from different dates on purpose - the
 * ledger by accounting date, the return by tax point - so a raw
 * difference is expected and `TaxReconciliation` classifies it. What is
 * reported is what remains after every class that CAN be explained has
 * been: a figure on a VAT control account that no document, no tax point
 * and no timing difference accounts for.
 */
final class TaxControlsTieToTheirReturns implements LedgerCheck
{
    public function __construct(private readonly TaxReconciler $reconciler) {}

    public function name(): string
    {
        return 'tax controls';
    }

    /**
     * @return list<array{severity: string, kind: string, detail: string, consequence: string}>
     */
    public function findings(?string $entityId): array
    {
        $findings = [];

        $entities = Entity::query()
            ->when($entityId !== null, fn ($q) => $q->where('id', $entityId))
            ->get();

        foreach ($entities as $entity) {
            /*
             | The last twelve filed periods, not every one ever filed.
             |
             | A difference on a return filed four years ago is history
             | somebody restated or did not; re-reporting it on every run
             | for ever makes the check's output grow until nobody reads
             | the new lines at the bottom.
             */
            $periods = ReturnPeriod::query()
                ->where('entity_id', $entity->getKey())
                ->where('state', 'filed')
                ->orderByDesc('label')
                ->limit(12)
                ->get();

            foreach ($periods as $period) {
                $findings = [...$findings, ...$this->period($entity, $period)];
            }
        }

        return $findings;
    }

    /**
     * @return list<array{severity: string, kind: string, detail: string, consequence: string}>
     */
    private function period(Entity $entity, ReturnPeriod $period): array
    {
        $until = $period->lastDay();

        if ($until === null) {
            /*
             | A filed period with no end is a data fault for the tax
             | module's own checks. Reporting it here would be a second
             | and worse description of it.
             */
            return [];
        }

        $reconciliation = $this->reconciler->reconcile(
            entity: $entity,
            jurisdiction: (string) $period->jurisdiction,
            periodLabel: (string) $period->label,
            from: $period->startsOn()->toDateString(),
            until: $until->toDateString(),
        );

        $unexplained = $reconciliation->unexplained();

        if ($unexplained->amount->isZero()) {
            return [];
        }

        return [[
            'severity' => 'critical',
            'kind' => sprintf('tax controls in %s', (string) $entity->code),
            'detail' => sprintf(
                'The VAT control accounts and the %s return for %s differ by %s %s that nothing '.
                'explains. The return has been filed, so this is either a posting that reached the '.
                'control account after the return was drawn, or a return drawn from something other '.
                'than the ledger.',
                (string) $period->jurisdiction,
                (string) $period->label,
                (string) $unexplained->amount,
                $reconciliation->currency,
            ),
            'consequence' => 'what the business told the authority it owes is not what its own accounts '.
                'say it owes, and the authority has the first of those two figures',
        ]];
    }
}
