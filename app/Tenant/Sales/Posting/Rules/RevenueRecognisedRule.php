<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Sales\Exceptions\RecognitionRefused;

/**
 * Releasing deferred revenue into revenue - PF-107, IFRS 15.
 *
 * ## Dr/Cr
 *
 *   Dr  deferred_revenue              what has now been earned
 *   Cr  the line's revenue account     the same, per line
 *
 * ## Why one entry for the whole period
 *
 * A reporting run that posted one entry per line would put four hundred
 * entries in a month's ledger for one act, and the trial balance would be
 * unreadable above the detail. One entry per period, with a line per revenue
 * account, is what a month's recognition is: one decision, taken once, on a
 * date.
 *
 * It also makes the re-run safe to reason about: either the entry exists and
 * the plan rows point at it, or neither does.
 *
 * ## Why the tax code is NOT carried
 *
 * Releasing a deferral is not a supply. The tax was declared when the
 * document was issued - that is what the tax point means - and putting a tax
 * code on this entry would make the taxable amount appear twice in a return
 * assembled from codes: once at issue and once here.
 */
final class RevenueRecognisedRule implements PostingRule
{
    public const KEY = 'sales.revenue_recognised';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'الاعتراف بالإيراد المؤجل',
            'Releasing deferred revenue into revenue as it is earned',
            [
                ['Dr', 'deferred_revenue', 'what has now been earned'],
                ['Cr', 'the line\'s revenue account', 'the same, per account'],
            ],
        );
    }

    /**
     * @param  array{
     *     period: string,
     *     narration?: string|null,
     *     releases: list<array{account_id: string, amount: string, party_id?: string|null, dimensions?: array<int, string|null>}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $narration = $event['narration'] ?? null;
        $releases = $event['releases'] ?? [];

        if ($releases === []) {
            throw RecognitionRefused::nothingToRelease((string) ($event['period'] ?? ''));
        }

        $lines = [];
        $total = Money::zero($currency);

        foreach ($releases as $release) {
            $account = (string) ($release['account_id'] ?? '');

            if ($account === '') {
                throw RecognitionRefused::aReleaseWithNoAccount();
            }

            $amount = Money::of((string) $release['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: $amount->negated(),
                partyId: $release['party_id'] ?? null,
                narration: $narration,
                dimensions: $release['dimensions'] ?? [],
            );

            $total = $total->plus($amount);
        }

        if ($total->isZero()) {
            throw RecognitionRefused::nothingToRelease((string) ($event['period'] ?? ''));
        }

        /*
         | The liability side, as one line.
         |
         | Per party would be the alternative, and it is wrong: the deferral
         | is a control account reconciled in total, and a hundred lines
         | against one account in one entry is a trial balance nobody can
         | read. The party detail lives on the revenue lines, where a
         | revenue report wants it.
         */
        return [
            PostingLine::toRole(ControlRole::DeferredRevenue, $total, null, $narration),
            ...$lines,
        ];
    }
}
