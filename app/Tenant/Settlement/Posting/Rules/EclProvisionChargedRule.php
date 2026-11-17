<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The provision for what probably will not arrive - PF-173.
 *
 * ## Dr/Cr
 *
 *   Dr  expected credit loss charge
 *   Cr  expected credit loss provision
 *
 * ## Both halves are the estimate, and neither is a write-off
 *
 * The provision is a contra-asset: it sits against receivables and says a
 * portion of them probably will not arrive. The charge is the cost of
 * saying so. Neither names a document, because the estimate is about a
 * population rather than about any one customer - which is exactly what
 * makes it an estimate.
 *
 * A write-off names a document, posts to `bad_debt`, and is final. The two
 * are kept in different accounts so "how much of last year's estimate
 * turned out to be real" stays answerable, and that comparison is the only
 * thing that calibrates the matrix.
 *
 * ## No party on either line
 *
 * Deliberately. A provision attributed to a customer reads as a statement
 * about that customer, and the subledger would then disagree with the
 * control account by the provision - which is a divergence with no
 * document behind it. The decomposition lives in `ecl_provision_lines`,
 * where it is evidence rather than a balance.
 */
final class EclProvisionChargedRule implements PostingRule
{
    public const KEY = 'settlement.ecl_provision_charged';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تكوين مخصص خسائر ائتمانية متوقعة',
            'The expected credit loss provision',
            [
                ['Dr', ControlRole::EclCharge, 'the cost of expecting part of the ledger not to arrive'],
                ['Cr', ControlRole::EclProvision, 'the provision, against receivables, naming no document'],
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
        $narration = (string) ($event['narration'] ?? 'Expected credit loss provision');

        return [
            PostingLine::toRole(
                ControlRole::EclCharge,
                $amount,
                narration: $narration,
            ),
            PostingLine::toRole(
                ControlRole::EclProvision,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
