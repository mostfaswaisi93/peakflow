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
             | The three that waited on later phases, and no longer do.
             |
             | Each was written out as `pending` with the task that would
             | bring it - documents with Phase 05, bank matching with Phase
             | 08, evidence with PF-080's gate against a real document.
             | All three arrived, so all three are now asked.
             */
            $this->unpostedDocumentsInThePeriod($entityId, $period),
            $this->unmatchedBankLines($entityId, $period),
            $this->documentsMissingTheirEvidence($entityId, $period),
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

        /*
         | Summed in SQL and read as a string.
         |
         | A query-builder aggregate comes back through a numeric cast on
         | some drivers, and a suspense balance that has been through a
         | float is a figure somebody is about to be told to defend.
         */
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(functional_amount), 0)::text AS balance
            FROM journal_lines
            WHERE entity_id = ?
              AND account_id = ?
        SQL, [$entityId, (string) $accountId]);

        $money = Money::of((string) ($row->balance ?? '0'), $currency);

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

    /**
     * Documents that exist, carry tax in this period, and have not posted.
     *
     * ## Different from the draft-entry check above, and both are needed
     *
     * That one asks the LEDGER: a journal entry somebody left in draft.
     * This asks the DOCUMENTS: an invoice or a supplier bill that was
     * entered, carries a tax point in this period, and never reached the
     * ledger at all. A document with no entry is invisible to the first
     * check by construction - there is nothing to be draft.
     *
     * Either way the tax belongs in this return and is not in it, and
     * either way it walks into the next period when somebody posts it,
     * after this return was filed.
     */
    private function unpostedDocumentsInThePeriod(string $entityId, ReturnPeriod $period): PreFilingFinding
    {
        $from = $period->startsOn()->toDateString();
        $until = $period->endsBefore()?->toDateString() ?? $from;

        $invoices = DB::connection('tenant')->table('invoices')
            ->where('entity_id', $entityId)
            ->whereBetween('tax_point', [$from, $until])
            ->whereNotIn('status', ['issued', 'cancelled'])
            ->count();

        $bills = DB::connection('tenant')->table('bills')
            ->where('entity_id', $entityId)
            ->whereBetween('tax_point', [$from, $until])
            ->whereNotIn('status', ['posted', 'cancelled'])
            ->count();

        $total = $invoices + $bills;

        if ($total === 0) {
            return PreFilingFinding::clear('unposted documents in the period');
        }

        return PreFilingFinding::blocking(
            'unposted documents in the period',
            sprintf(
                '%d document%s carr%s a tax point in this period and %s not posted (%d invoice%s, %d '.
                'supplier bill%s). The return is assembled from posted entries, so this tax is not in '.
                'it - and posting them after filing puts a period\'s tax into the next period\'s return.',
                $total,
                $total === 1 ? '' : 's',
                $total === 1 ? 'ies' : 'y',
                $total === 1 ? 'has' : 'have',
                $invoices,
                $invoices === 1 ? '' : 's',
                $bills,
                $bills === 1 ? '' : 's',
            ),
            $total,
        );
    }

    /**
     * Bank lines in the period that are matched to nothing.
     *
     * ## Why this is a TAX question and not only a banking one
     *
     * An unmatched receipt can be a sale nobody invoiced, and a sale
     * nobody invoiced is output tax missing from this return. The money
     * arrived, the authority's share of it did not, and the bank
     * statement is the only record that it happened at all.
     *
     * It is a warning on the banking screens and a BLOCKING item here,
     * because the two questions have different costs: an unmatched line
     * on a Tuesday is work to do, and an unmatched line at filing is a
     * figure somebody is about to swear to.
     */
    private function unmatchedBankLines(string $entityId, ReturnPeriod $period): PreFilingFinding
    {
        $from = $period->startsOn()->toDateString();
        $until = $period->endsBefore()?->toDateString() ?? $from;

        $count = DB::connection('tenant')->table('bank_transactions as t')
            ->where('t.entity_id', $entityId)
            ->whereBetween('t.booking_date', [$from, $until])
            ->whereNull('t.superseded_by_id')
            ->whereNotExists(static function ($query) {
                $query->selectRaw('1')
                    ->from('matches as m')
                    ->whereColumn('m.bank_transaction_id', 't.id');
            })
            ->count();

        if ($count === 0) {
            return PreFilingFinding::clear('unmatched bank lines');
        }

        return PreFilingFinding::blocking(
            'unmatched bank lines',
            sprintf(
                '%d bank line%s in this period %s matched to nothing. An unmatched receipt can be a '.
                'sale nobody invoiced - the money arrived, the authority\'s share of it did not, and '.
                'the statement is the only record that it happened.',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 'is' : 'are',
            ),
            $count,
        );
    }

    /**
     * Documents claiming a category whose evidence is not recorded.
     *
     * ## Why this one matters more than its position suggests
     *
     * **The export zero rate claimed with no proof of exit is the most
     * commonly assessed error there is.** An authority does not need to
     * prove the goods stayed - it asks the business to prove they left,
     * and a business that cannot pays the tax it did not charge, plus
     * interest, on a sale whose margin was set on the assumption it
     * would not.
     *
     * `EvidenceGate` refuses at issue time, so a document that reaches
     * this check without evidence got here another way - an import, a
     * migration, a category changed afterwards. Which is exactly why the
     * question is asked again at filing rather than trusted to the gate.
     */
    private function documentsMissingTheirEvidence(string $entityId, ReturnPeriod $period): PreFilingFinding
    {
        $from = $period->startsOn()->toDateString();
        $until = $period->endsBefore()?->toDateString() ?? $from;

        /*
         | Only the categories that need proof.
         |
         | Standard-rated tax needs no evidence beyond the invoice: the
         | business charged it and owes it. Zero-rated and exempt are
         | claims about why tax was NOT charged, and a claim is what needs
         | evidence.
         */
        $rows = DB::connection('tenant')->table('invoices as i')
            ->join('invoice_tax_breakdown as b', 'b.invoice_id', '=', 'i.id')
            ->where('i.entity_id', $entityId)
            ->whereBetween('i.tax_point', [$from, $until])
            ->where('i.status', 'issued')
            ->whereIn('b.category', ['Z', 'E', 'G', 'O'])
            ->whereNull('i.tax_decision_snapshot')
            ->distinct()
            ->count('i.id');

        if ($rows === 0) {
            return PreFilingFinding::clear('documents missing the evidence their category requires');
        }

        return PreFilingFinding::blocking(
            'documents missing the evidence their category requires',
            sprintf(
                '%d issued document%s in this period claim%s a zero-rated, exempt or out-of-scope '.
                'category with no recorded decision behind it. The export zero rate claimed with no '.
                'proof of exit is the most commonly assessed error there is: the authority asks the '.
                'business to prove the goods left, and one that cannot pays the tax it did not charge.',
                $rows,
                $rows === 1 ? '' : 's',
                $rows === 1 ? 's' : '',
            ),
            $rows,
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
