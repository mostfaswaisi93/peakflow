<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\PreFilingFinding;
use App\Tenant\Tax\TaxReturn;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * What to look at before filing - PF-086.
 *
 * ## Every item is reported, not the first one
 *
 * A checklist that stops at the first problem makes somebody fix one thing,
 * run it again, and find the next - on the day of a deadline. All of them come
 * back at once.
 *
 * ## The checks that cannot run yet are LISTED as pending
 *
 * Unposted documents, unmatched bank lines and missing evidence all inspect
 * things that arrive in later phases. Omitting them would tell the person
 * filing that everything was looked at, which is the one thing a checklist
 * must never do. Each says what it will check and which task brings it.
 */
final class PreFilingChecklist
{
    public function __construct(private readonly ReturnBoxes $boxes) {}

    /**
     * @return list<PreFilingFinding>
     */
    public function for(Entity $entity, ReturnPeriod $period, TaxReturn $return): array
    {
        $entityId = (string) $entity->getKey();
        $currency = (string) $entity->functional_currency;

        return [
            $this->theSuspenseBalance($entityId, $currency),
            $this->draftEntriesInThePeriod($entityId, $period),
            $this->unmappedCodes($entityId),
            $this->boxesWithTaxButNoBase($return),
            $this->taxLinesOutsideAnyBox($entityId, $period, $return),

            /*
             | The three that wait on later phases.
             |
             | Written out with the task that brings each one, so the gap is a
             | sentence somebody can act on rather than an absence they have
             | to notice.
             */
            PreFilingFinding::pending(
                'unposted documents in the period',
                'Documents arrive in Phase 05 (PF-089 onwards). Until then a draft JOURNAL ENTRY is the '.
                'nearest thing, and it is checked above. A document that exists and has not posted is '.
                'tax that belongs in this return and is not in it.',
            ),
            PreFilingFinding::pending(
                'unmatched bank lines',
                'Bank statement import and matching arrive in Phase 08 (PF-137 onwards). An unmatched '.
                'receipt can be a sale nobody invoiced, which is output tax missing from this return.',
            ),
            PreFilingFinding::pending(
                'documents missing the evidence their category requires',
                'PF-080 built the gate - EvidenceGate::assertSatisfied() - and the evidence is recorded '.
                'against a document, which arrives in Phase 05. The export zero rate claimed with no '.
                'proof of exit is the most commonly assessed error there is, so this one matters more '.
                'than its position in the list suggests.',
            ),
        ];
    }

    /**
     * @param  list<PreFilingFinding>  $findings
     * @return list<PreFilingFinding>
     */
    public function blocking(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (PreFilingFinding $finding): bool => $finding->isBlocking(),
        ));
    }

    private function theSuspenseBalance(string $entityId, string $currency): PreFilingFinding
    {
        $accountId = AccountRole::query()
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::Suspense->value)
            ->value('account_id');

        if ($accountId === null) {
            return PreFilingFinding::clear('the suspense balance', 'no suspense account is assigned');
        }

        $balance = DB::connection('tenant')->table('journal_lines')
            ->where('entity_id', $entityId)
            ->where('account_id', $accountId)
            ->sum('functional_amount');

        $money = Money::of((string) ($balance ?? '0'), $currency);

        if ($money->isZero()) {
            return PreFilingFinding::clear('the suspense balance', 'nothing is in suspense');
        }

        return PreFilingFinding::blocking(
            'the suspense balance',
            sprintf(
                'Suspense holds %s. Whatever is in there is a transaction nobody has explained, and if '.
                'any of it is a sale the output tax is missing from this return. Filing with a non-zero '.
                'suspense balance is filing a return you cannot defend.',
                (string) $money->toPresentation(),
            ),
        );
    }

    private function draftEntriesInThePeriod(string $entityId, ReturnPeriod $period): PreFilingFinding
    {
        $from = $period->startsOn()->toDateString();
        $until = $period->endsBefore()?->toDateString() ?? $from;

        $count = DB::connection('tenant')
            ->table('journal_lines as l')
            ->join('journal_entries as e', function ($join): void {
                $join->on('e.id', '=', 'l.journal_entry_id')
                    ->on('e.accounting_date', '=', 'l.accounting_date');
            })
            ->where('l.entity_id', $entityId)
            ->whereNotNull('l.tax_point')
            ->where('l.tax_point', '>=', $from)
            ->where('l.tax_point', '<', $until)
            ->where('e.status', 'draft')
            ->distinct()
            ->count('e.id');

        if ($count === 0) {
            return PreFilingFinding::clear('draft entries carrying tax in this period');
        }

        return PreFilingFinding::blocking(
            'draft entries carrying tax in this period',
            sprintf(
                '%d draft entr%s carry a tax point in this period. A draft is not a liability, so the '.
                'return excludes it - and it will walk into the next period when somebody posts it, '.
                'after this return was filed.',
                $count, $count === 1 ? 'y' : 'ies',
            ),
            $count,
        );
    }

    private function unmappedCodes(string $entityId): PreFilingFinding
    {
        $unmapped = $this->boxes->codesWithoutABox($entityId);

        if ($unmapped === []) {
            return PreFilingFinding::clear('every tax code maps to a box');
        }

        return PreFilingFinding::blocking(
            'tax codes with no box',
            sprintf(
                '%d mapping problem%s: %s. A code with no box produces tax that is computed and then '.
                'dropped from the return.',
                \count($unmapped), \count($unmapped) === 1 ? '' : 's', $unmapped[0],
            ),
            \count($unmapped),
        );
    }

    private function boxesWithTaxButNoBase(TaxReturn $return): PreFilingFinding
    {
        $boxes = $return->boxesWithTaxButNoBase();

        if ($boxes === []) {
            return PreFilingFinding::clear('every box with tax has a taxable amount');
        }

        return PreFilingFinding::blocking(
            'boxes with tax and no taxable amount',
            sprintf(
                '%d box(es) carry tax with nothing behind it, starting with "%s". The authority\'s own '.
                'arithmetic is amount times rate, so a figure with no base is one nobody can verify - '.
                'including us. It usually means a document\'s tax legs posted and its revenue leg did '.
                'not.',
                \count($boxes), $boxes[0]->box,
            ),
            \count($boxes),
        );
    }

    /**
     * Tax-bearing lines in the period that no box claimed.
     *
     * The complement of the mapping check: that one asks whether every CODE
     * has a box, this one asks whether every LINE reached one. They fail
     * differently - a code can be mapped to a box on the wrong side, and its
     * lines then land nowhere while the code looks configured.
     */
    private function taxLinesOutsideAnyBox(
        string $entityId,
        ReturnPeriod $period,
        TaxReturn $return,
    ): PreFilingFinding {
        $from = $period->startsOn()->toDateString();
        $until = $period->endsBefore()?->toDateString() ?? $from;

        $total = DB::connection('tenant')
            ->table('journal_lines as l')
            ->join('journal_entries as e', function ($join): void {
                $join->on('e.id', '=', 'l.journal_entry_id')
                    ->on('e.accounting_date', '=', 'l.accounting_date');
            })
            ->join('tax_codes as c', 'c.id', '=', 'l.tax_code_id')
            ->where('l.entity_id', $entityId)
            ->where('c.jurisdiction', (string) $period->jurisdiction)
            ->whereNotNull('l.tax_point')
            ->where('l.tax_point', '>=', $from)
            ->where('l.tax_point', '<', $until)
            ->where('e.status', 'posted')
            ->count();

        $claimed = $return->lineCount();

        if ($total === 0) {
            return PreFilingFinding::clear(
                'every tax-bearing line reached a box', 'the period carries no tax',
            );
        }

        if ($claimed >= $total) {
            return PreFilingFinding::clear(
                'every tax-bearing line reached a box',
                sprintf('%d of %d tax-bearing lines', $claimed, $total),
            );
        }

        return PreFilingFinding::blocking(
            'tax-bearing lines that reached no box',
            sprintf(
                '%d of %d tax-bearing lines in this period are in no box of the return. They carry a '.
                'code and a tax point, they are posted, and the return does not include them - which '.
                'happens when a code is mapped to a box on the wrong side, or to a box the form does '.
                'not have. The code looks configured and its lines land nowhere.',
                $total - $claimed, $total,
            ),
            $total - $claimed,
        );
    }
}
