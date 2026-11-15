<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\TaxReconciliation;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the tax control accounts against the return - PF-088.
 *
 * ## What is being compared
 *
 * The movement on `vat_output` and `vat_input` for an ACCOUNTING period,
 * against the tax in the RETURN whose window overlaps it. Those two are
 * assembled from different dates on purpose - the ledger by accounting date,
 * the return by tax point - so the gap is expected and the job is to explain
 * it rather than to close it.
 *
 * ## The classes, and why each one exists
 *
 * **timing** - a line whose tax point is in one window and whose accounting
 * date is in the other. Ordinary, and the largest class in most periods.
 *
 * **manual** - a line on a VAT control account with no tax code at all. This
 * is the class that matters. A journal straight into the output account moves
 * the trial balance and cannot reach a return, because a return is assembled
 * from tax codes. It is also the easiest thing in the world to do at a month
 * end, with a plausible narration, to make a number look right.
 *
 * **other jurisdiction** - a line whose code belongs to a different authority.
 * It is in the same control account only if the chart is misconfigured, and
 * PF-084's per-jurisdiction accounts are what prevent it; the class exists so
 * that a misconfiguration is named rather than landing in `unexplained`.
 *
 * **unmapped** - a line whose code maps to no box on this form. Computed and
 * then dropped from the return, which PF-085's check reports separately; here
 * it explains part of the gap.
 */
final class TaxReconciler
{
    public function __construct(private readonly ReturnBoxes $boxes) {}

    /**
     * @param  string  $from  the first day of the accounting period
     * @param  string  $until  the first day after it
     */
    public function reconcile(
        Entity $entity,
        string $jurisdiction,
        string $periodLabel,
        string $from,
        string $until,
    ): TaxReconciliation {
        $entityId = (string) $entity->getKey();
        $currency = (string) $entity->functional_currency;
        $form = $this->boxes->formFor($jurisdiction);
        $boxes = $this->boxes->boxes($form);

        $vat = $this->vatAccounts($entityId, $jurisdiction);

        /*
         | Every line on a VAT control account in the ACCOUNTING window.
         |
         | Including the ones with no tax code, which is the point: a manual
         | journal into the output account is invisible to the return and
         | fully visible here.
         */
        $rows = DB::connection('tenant')
            ->table('journal_lines as l')
            ->join('journal_entries as e', function ($join): void {
                $join->on('e.id', '=', 'l.journal_entry_id')
                    ->on('e.accounting_date', '=', 'l.accounting_date');
            })
            ->leftJoin('tax_codes as c', 'c.id', '=', 'l.tax_code_id')
            ->where('l.entity_id', $entityId)
            ->whereIn('l.account_id', array_keys($vat))
            ->where('l.accounting_date', '>=', $from)
            ->where('l.accounting_date', '<', $until)
            ->where('e.status', 'posted')
            ->select([
                'l.id', 'l.account_id', 'l.functional_amount', 'l.tax_point',
                'c.jurisdiction as code_jurisdiction', 'c.reporting_boxes',
            ])
            ->get();

        $movement = Money::zero($currency);

        /*
         | `blocks` says whether the class EXPLAINS the difference or merely
         | names it.
         |
         | Timing explains. The other three are defects: identified, so
         | somebody can act on them, and still counted as unexplained -
         | because a reconciliation that classifies a 500 riyal manual journal
         | and then reports itself clean is worse than one that does not look
         | at manual journals at all.
         */
        $classes = [
            'manual' => $this->emptyClass($currency, true,
                'lines on a VAT control account with no tax code: they move the trial balance and '.
                'cannot reach a return, which is assembled from tax codes'),
            'other jurisdiction' => $this->emptyClass($currency, true,
                'lines whose tax code belongs to another authority, which should not share this '.
                'control account at all'),
            'unmapped' => $this->emptyClass($currency, true,
                'lines whose code maps to no box on this form: computed and then dropped from the return'),
            'timing' => $this->emptyClass($currency, false,
                'lines whose tax point falls outside this window - in the return of an earlier or later '.
                'period, which is the two dates doing their jobs rather than an error'),
        ];

        foreach ($rows as $row) {
            $signed = $this->signed($row, $vat, $currency);
            $movement = $movement->plus($signed);

            $class = $this->classify($row, $boxes, $form, $from, $until);

            if ($class === null) {
                continue;
            }

            $classes[$class]['amount'] = $classes[$class]['amount']->plus($signed);
            $classes[$class]['lines'][] = (string) $row->id;
        }

        /*
         | And the lines the RETURN counts whose accounting date is outside
         | this window - the other direction of the same timing difference.
         |
         | Without this, a supply booked in the previous month and reported in
         | this return shows up as unexplained, and a clean period looks dirty
         | every time a month end falls near a weekend.
         */
        $returnFigure = $this->returnTax($entityId, $jurisdiction, $form, $from, $until, $currency, $vat);

        foreach ($this->lateBooked($entityId, $jurisdiction, $from, $until, $currency, $vat) as $id => $amount) {
            $classes['timing']['amount'] = $classes['timing']['amount']->minus($amount);
            $classes['timing']['lines'][] = (string) $id;
        }

        return new TaxReconciliation(
            $jurisdiction,
            $periodLabel,
            $this->returnLabelFor($entityId, $jurisdiction, $from),
            $currency,
            $movement,
            $returnFigure,
            array_filter(
                $classes,
                static fn (array $class): bool => ! $class['amount']->isZero() || $class['lines'] !== [],
            ),
        );
    }

    /**
     * @param  array<string, array{side: string, en: string, ar: string}>  $boxes
     */
    private function classify(object $row, array $boxes, string $form, string $from, string $until): ?string
    {
        if ($row->tax_point === null) {
            return 'manual';
        }

        if ((string) $row->code_jurisdiction !== '' && ! $this->isForThisForm($row, $form)) {
            return 'other jurisdiction';
        }

        $mapping = json_decode((string) ($row->reporting_boxes ?? '{}'), true);
        $mapping = \is_array($mapping) ? ($mapping[$form] ?? null) : null;

        if (! \is_array($mapping) || $mapping === []) {
            return 'unmapped';
        }

        $box = $mapping['output'] ?? $mapping['input'] ?? null;

        if (! \is_string($box) || ! isset($boxes[$box])) {
            return 'unmapped';
        }

        $taxPoint = (string) $row->tax_point;

        if ($taxPoint < $from || $taxPoint >= $until) {
            return 'timing';
        }

        return null;
    }

    private function isForThisForm(object $row, string $form): bool
    {
        return $this->boxes->formFor((string) $row->code_jurisdiction) === $form;
    }

    /**
     * The tax the return counts for the window, from the same lines the
     * generator would use.
     *
     * Computed here rather than taken from a generated return so that the two
     * sides of the reconciliation are independent: comparing a figure against
     * itself is the shape of a reconciliation that is always clean.
     *
     * @param  array<string, string>  $vat
     */
    private function returnTax(
        string $entityId,
        string $jurisdiction,
        string $form,
        string $from,
        string $until,
        string $currency,
        array $vat,
    ): Money {
        $rows = DB::connection('tenant')
            ->table('journal_lines as l')
            ->join('journal_entries as e', function ($join): void {
                $join->on('e.id', '=', 'l.journal_entry_id')
                    ->on('e.accounting_date', '=', 'l.accounting_date');
            })
            ->join('tax_codes as c', 'c.id', '=', 'l.tax_code_id')
            ->where('l.entity_id', $entityId)
            ->where('c.jurisdiction', $jurisdiction)
            ->whereIn('l.account_id', array_keys($vat))
            ->whereNotNull('l.tax_point')
            ->where('l.tax_point', '>=', $from)
            ->where('l.tax_point', '<', $until)
            ->where('e.status', 'posted')
            ->select(['l.id', 'l.account_id', 'l.functional_amount'])
            ->get();

        $sum = Money::zero($currency);

        foreach ($rows as $row) {
            $sum = $sum->plus($this->signed($row, $vat, $currency));
        }

        return $sum;
    }

    /**
     * Lines in this return window that were BOOKED outside it.
     *
     * @param  array<string, string>  $vat
     * @return array<string, Money>
     */
    private function lateBooked(
        string $entityId,
        string $jurisdiction,
        string $from,
        string $until,
        string $currency,
        array $vat,
    ): array {
        $rows = DB::connection('tenant')
            ->table('journal_lines as l')
            ->join('journal_entries as e', function ($join): void {
                $join->on('e.id', '=', 'l.journal_entry_id')
                    ->on('e.accounting_date', '=', 'l.accounting_date');
            })
            ->join('tax_codes as c', 'c.id', '=', 'l.tax_code_id')
            ->where('l.entity_id', $entityId)
            ->where('c.jurisdiction', $jurisdiction)
            ->whereIn('l.account_id', array_keys($vat))
            ->whereNotNull('l.tax_point')
            ->where('l.tax_point', '>=', $from)
            ->where('l.tax_point', '<', $until)
            ->where(fn ($q) => $q->where('l.accounting_date', '<', $from)
                ->orWhere('l.accounting_date', '>=', $until))
            ->where('e.status', 'posted')
            ->select(['l.id', 'l.account_id', 'l.functional_amount'])
            ->get();

        $late = [];

        foreach ($rows as $row) {
            $late[(string) $row->id] = $this->signed($row, $vat, $currency);
        }

        return $late;
    }

    /** @param  array<string, string>  $vat */
    private function signed(object $row, array $vat, string $currency): Money
    {
        $amount = Money::of((string) $row->functional_amount, $currency);

        /*
         | Output tax is a credit in the ledger and a positive figure on a
         | return, so the output side is negated - the same convention as the
         | generator, because two conventions would make a clean period look
         | like a difference of twice the output tax.
         */
        return ($vat[(string) $row->account_id] ?? null) === ControlRole::VatOutput->value
            ? $amount->negated()
            : $amount;
    }

    private function returnLabelFor(string $entityId, string $jurisdiction, string $from): string
    {
        $period = ReturnPeriod::query()
            ->where('entity_id', $entityId)
            ->where('jurisdiction', $jurisdiction)
            ->whereRaw('period @> ?::date', [$from])
            ->first();

        return $period?->label ?? '(no return period)';
    }

    /** @return array<string, string> account id => role */
    private function vatAccounts(string $entityId, string $jurisdiction): array
    {
        $accounts = [];

        $rows = AccountRole::query()
            ->where('entity_id', $entityId)
            ->whereIn('role', [ControlRole::VatOutput->value, ControlRole::VatInput->value])
            ->where('jurisdiction', $jurisdiction)
            ->get(['account_id', 'role']);

        foreach ($rows as $row) {
            $role = $row->role;
            $accounts[(string) $row->account_id] = $role instanceof ControlRole ? $role->value : (string) $role;
        }

        return $accounts;
    }

    /** @return array{amount: Money, lines: list<string>, detail: string, blocks: bool} */
    private function emptyClass(string $currency, bool $blocks, string $detail): array
    {
        return [
            'amount' => Money::zero($currency),
            'lines' => [],
            'detail' => $detail,
            'blocks' => $blocks,
        ];
    }
}
