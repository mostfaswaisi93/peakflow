<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Closing;

use App\Support\Closing\ClosingCheck;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Tenancy\Models\Entity;

/**
 * A period does not close over money nobody can explain - PF-163.
 *
 * ## Why this is a blocking check rather than a warning
 *
 * Because of what is in suspense. Any of it could be a sale, and a sale
 * sitting in suspense is revenue that is not in the accounts and output tax
 * that is not on the return. Closing the period freezes that, and the
 * correction is a prior-period adjustment - which is a different and much
 * more expensive conversation than looking at four bank lines in the week
 * they arrived.
 *
 * A warning would be read, understood and dismissed by the person closing,
 * which is what a warning is for. This is not advice.
 *
 * ## And why it can be signed off
 *
 * Because sometimes it genuinely cannot be resolved by the close. A
 * customer's bank will not say who sent a transfer, and the alternative to
 * signing off is leaving the period open - which stops reporting, invites
 * backdated postings, and is worse than closing with a recorded decision.
 *
 * So the escape is a controller's name against a figure and a sentence, kept
 * as a record. The point is not to prevent the close; it is that nobody
 * closes over unexplained money **silently**.
 *
 * ## The sign-off is for that balance, not for the period
 *
 * It records what was in suspense when it was given. If more lands in
 * suspense after the sign-off and before the close, the figures differ and
 * the check refuses again - because what was accepted is not what is there
 * now, and somebody should look at the difference rather than inherit a
 * decision made about a smaller number.
 */
final class SuspenseCheck implements ClosingCheck
{
    public function __construct(private readonly Suspense $suspense) {}

    public function name(): string
    {
        return 'suspense';
    }

    /** @return list<string> */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
    {
        $entity = Entity::query()->find($entityId);

        if ($entity === null) {
            return [];
        }

        $currency = (string) $entity->functional_currency;
        $ageing = $this->suspense->ageing($entityId, $currency, $until);

        if ($ageing['total']->isZero()) {
            return [];
        }

        $period = Period::query()
            ->where('entity_id', $entityId)
            ->where('label', $label)
            ->first();

        $signoff = $period === null ? null : \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('suspense_signoffs')
            ->where('entity_id', $entityId)
            ->where('period_id', (string) $period->getKey())
            ->first();

        if ($signoff !== null) {
            /*
             | A sign-off is for the figure it was given against.
             |
             | Comparing as strings at the stored scale, not as numbers
             | through a float: both come out of `numeric` columns and
             | `Money` compares them exactly.
             */
            $accepted = \App\Support\Money\Money::of((string) $signoff->balance, (string) $signoff->currency);

            if ($accepted->abs()->amount->isEqualTo($ageing['total']->amount)) {
                return [];
            }

            return [sprintf(
                'suspense holds %s across %d item(s) and the sign-off for %s was given against %s. '.
                'More has landed in suspense since, so what was accepted is not what is there - '.
                'look at the difference rather than inherit a decision made about a smaller figure',
                (string) $ageing['total']->toPresentation(),
                \count($ageing['items']),
                $label,
                (string) $accepted->abs()->toPresentation(),
            )];
        }

        return [sprintf(
            'suspense holds %s across %d item(s), the oldest %d days old, and anything in there '.
            'could be a sale - which would mean revenue missing from these accounts and output tax '.
            'missing from the return. Clear them, or have a controller sign the balance off with a '.
            'reason',
            (string) $ageing['total']->toPresentation(),
            \count($ageing['items']),
            $ageing['oldest_days'],
        )];
    }
}
