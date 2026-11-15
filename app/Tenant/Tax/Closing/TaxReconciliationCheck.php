<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Closing;

use App\Support\Closing\ClosingCheck;
use App\Support\Money\Money;
use App\Tenant\Tax\Models\TaxReconciliationSignoff;
use App\Tenant\Tax\Services\TaxReconciler;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;

/**
 * An unexplained tax difference stops a period closing - PF-088.
 *
 * ## Why at the close rather than at the filing
 *
 * Because the close is the last moment the period can still be posted into.
 * After it, every correction is a restatement: the entry has to go into a
 * later period with a reference to this one, somebody has to decide whether
 * the comparatives move, and a difference of a few hundred has become a
 * disclosure question.
 *
 * ## Checked per jurisdiction the entity is registered in
 *
 * An entity registered in two files two returns and holds two pairs of control
 * accounts. A check that reconciled "the VAT accounts" would reconcile one
 * jurisdiction's return against both authorities' movements and report a
 * difference every month.
 *
 * ## The sign-off is bounded by its amount
 *
 * A controller can accept a difference, and the acceptance covers an AMOUNT. A
 * larger one blocks again, so a figure that drifts comes back rather than
 * hiding behind a decision made when it was small. The alternative - a check
 * that can only block - gets disabled over four riyals, and then the real
 * difference goes through unnoticed next quarter.
 */
final class TaxReconciliationCheck implements ClosingCheck
{
    public function __construct(private readonly TaxReconciler $reconciler) {}

    public function name(): string
    {
        return 'tax reconciliation';
    }

    /** @return list<string> */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
    {
        $entity = Entity::query()->find($entityId);

        if ($entity === null) {
            return [];
        }

        $reasons = [];

        foreach ($this->jurisdictionsOf($entityId) as $jurisdiction) {
            $report = $this->reconciler->reconcile($entity, $jurisdiction, $label, $from, $until);

            if ($report->isClean()) {
                continue;
            }

            $unexplained = $report->unexplained();
            $accepted = $this->signedOff($entityId, $jurisdiction, $label, $report->currency);

            if ($accepted !== null && ! $unexplained->abs()->isGreaterThan($accepted->abs())) {
                continue;
            }

            $named = implode(', ', array_map(
                static fn (string $name, array $class): string => sprintf(
                    '%s %s', $name, (string) $class['amount']->toPresentation(),
                ),
                array_keys($report->blockingClasses()),
                array_values($report->blockingClasses()),
            ));

            $reasons[] = $accepted === null
                ? sprintf(
                    '%s tax: %s of the movement on the control accounts is not explained (the movement '.
                    'is %s, the return %s)%s. Closing freezes it, and after the close every correction '.
                    'is a restatement rather than an entry. A controller can sign it off with a reason.',
                    $jurisdiction,
                    (string) $unexplained->toPresentation(),
                    (string) $report->controlMovement->toPresentation(),
                    (string) $report->returnFigure->toPresentation(),
                    $named === '' ? '' : sprintf(' - of which %s', $named),
                )
                : sprintf(
                    '%s tax: the unexplained difference is %s and the sign-off covers %s. A sign-off is '.
                    'bounded by its amount so that a figure which drifts comes back rather than hiding '.
                    'behind a decision made when it was smaller.',
                    $jurisdiction,
                    (string) $unexplained->toPresentation(),
                    (string) $accepted->toPresentation(),
                );
        }

        return $reasons;
    }

    /**
     * Every jurisdiction this entity is registered in.
     *
     * From the registrations rather than from the control accounts: an entity
     * that holds an account for a jurisdiction it is no longer registered in
     * still has to reconcile it, and one registered without an account yet is
     * a configuration problem PF-084 reports rather than a silent skip.
     *
     * @return list<string>
     */
    private function jurisdictionsOf(string $entityId): array
    {
        return TaxRegistration::query()
            ->where('entity_id', $entityId)
            ->distinct()
            ->orderBy('jurisdiction')
            ->pluck('jurisdiction')
            ->map(static fn (mixed $j): string => (string) $j)
            ->all();
    }

    private function signedOff(
        string $entityId,
        string $jurisdiction,
        string $label,
        string $currency,
    ): ?Money {
        /*
         | The LARGEST acceptance, not the latest.
         |
         | The question is whether anybody has accepted a difference at least
         | this big, and a sign-off is an immutable fact rather than a setting
         | - so a second one widening the first is an addition, not a
         | replacement.
         |
         | Ordering by `signed_at` answered the wrong question and answered it
         | unreliably: two sign-offs recorded in the same second are
         | indistinguishable by timestamp, so a controller who signed off 100
         | and then 500 could get either answer. Found exactly that way.
         */
        $signoffs = TaxReconciliationSignoff::query()
            ->where('entity_id', $entityId)
            ->where('jurisdiction', $jurisdiction)
            ->where('period_label', $label)
            ->get();

        $largest = null;

        foreach ($signoffs as $signoff) {
            $amount = Money::of($signoff->accepted_amount, $currency);

            if ($largest === null || $amount->abs()->isGreaterThan($largest->abs())) {
                $largest = $amount;
            }
        }

        return $largest;
    }
}
