<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The provision a write-off used up - PF-173.
 *
 * ## Dr/Cr
 *
 *   Dr  expected credit loss provision
 *   Cr  expected credit loss charge
 *
 * ## Utilization, not a reversal of the estimate
 *
 * The estimate was right about this one. A portion of the receivable was
 * provisioned for, the debt has now been concluded uncollectable, and the
 * provision has done its job - so it is released rather than left
 * standing.
 *
 * Without the release, the period of a write-off carries both the estimate
 * and the conclusion: bad debt takes the full amount while the provision
 * still claims part of it is coming. The impairment line then reads as
 * twice the loss that happened, and the figure recovers by itself next
 * period when the recompute drops the document - which looks like a credit
 * nobody can explain.
 *
 * ## Why the charge account and not bad debt
 *
 * Because what is being undone is the **estimate**. Crediting bad debt
 * would net the release against the conclusion and leave the write-off
 * reported at less than it was, which is the one figure that must stay
 * exact: it is what the matrix is calibrated against.
 */
final class EclProvisionReleasedRule implements PostingRule
{
    public const KEY = 'settlement.ecl_provision_released';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'استخدام مخصص عند إعدام دين',
            'Provision used up by a write-off',
            [
                ['Dr', ControlRole::EclProvision, 'releasing what was provisioned for this document'],
                ['Cr', ControlRole::EclCharge, 'reducing the estimate, never the conclusion'],
            ],
        );
    }

    /**
     * @param  array{amount: string, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Provision released on write-off');

        return [
            PostingLine::toRole(
                ControlRole::EclProvision,
                $amount,
                narration: $narration,
            ),
            PostingLine::toRole(
                ControlRole::EclCharge,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
