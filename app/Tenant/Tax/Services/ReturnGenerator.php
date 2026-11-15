<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\TaxReturn;
use App\Tenant\Tax\TaxReturnBox;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * Builds a period's return from the ledger - PF-086.
 *
 * ## From the LEDGER, not from the documents
 *
 * Which is the decision that makes PF-088's reconciliation possible at all. A
 * return assembled from documents and a ledger posted from the same documents
 * are two derivations of one truth, and when they disagree nothing says which
 * is wrong. A return assembled from the ledger agrees with the trial balance
 * by construction, and the remaining question - does the ledger reflect the
 * documents - is one the subledger totals answer.
 *
 * ## Selected by tax point, and only from POSTED entries
 *
 * A draft entry is not a liability. Including one reports tax on a document
 * nobody has committed, and the figure changes when somebody edits the draft
 * after the return was reviewed.
 *
 * ## Which side a line is on comes from the ACCOUNT, not from the code
 *
 * A standard-rate code appears on a sale and on a purchase. What separates
 * them is which control account the tax landed in: the output account or the
 * input one. Deriving the side from the code would put a supplier's VAT in the
 * sales box of whoever used the same code for both.
 *
 * The taxable amounts come from the lines that carry the code and are NOT on a
 * VAT or receivable/payable control account - which is the revenue or expense
 * leg. A tax-only posting therefore contributes no taxable amount, and
 * {@see TaxReturn::boxesWithTaxButNoBase()} reports that rather than hiding
 * it: it means a document was half-posted, and the return would be
 * arithmetically indefensible while looking complete.
 */
final class ReturnGenerator
{
    public function __construct(
        private readonly ReturnBoxes $boxes,
        private readonly ReturnAdjustments $adjustments,
    ) {}

    public function generate(Entity $entity, ReturnPeriod $period): TaxReturn
    {
        $entityId = (string) $entity->getKey();
        $currency = (string) $entity->functional_currency;
        $form = $this->boxes->formFor((string) $period->jurisdiction);
        $definitions = $this->boxes->boxes($form);

        $from = $period->startsOn()->toDateString();
        $until = $period->endsBefore()?->toDateString() ?? $from;

        $vatAccounts = $this->accountsFor($entityId, [ControlRole::VatOutput, ControlRole::VatInput]);
        $counterparty = $this->accountsFor($entityId, [
            ControlRole::AccountsReceivable, ControlRole::AccountsPayable,
        ]);

        $rows = DB::connection('tenant')
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
            ->select([
                'l.id', 'l.account_id', 'l.amount', 'l.tax_point',
                'c.reporting_boxes', 'c.code as tax_code',
            ])
            ->orderBy('l.tax_point')
            ->orderBy('l.id')
            ->get();

        /** @var array<string, array{net: Money, tax: Money, lines: list<array{id: string, tax_point: string, amount: string}>}> $figures */
        $figures = [];

        foreach ($definitions as $name => $definition) {
            $figures[$name] = [
                'net' => Money::zero($currency),
                'tax' => Money::zero($currency),
                'lines' => [],
            ];
        }

        foreach ($rows as $row) {
            $mapping = json_decode((string) $row->reporting_boxes, true);
            $mapping = \is_array($mapping) ? ($mapping[$form] ?? null) : null;

            if (! \is_array($mapping)) {
                continue;
            }

            $accountId = (string) $row->account_id;
            $side = match (true) {
                ($vatAccounts[$accountId] ?? null) === ControlRole::VatOutput->value => 'output',
                ($vatAccounts[$accountId] ?? null) === ControlRole::VatInput->value => 'input',
                default => null,
            };

            /*
             | A line on a VAT control account is the TAX.
             |
             | A line carrying the code on a receivable or payable is the
             | gross the counterparty owes, which is not a box figure - it is
             | the net plus the tax, and adding it would double-count both.
             */
            if ($side !== null) {
                $box = $mapping[$side] ?? null;

                if (! \is_string($box) || ! isset($figures[$box])) {
                    continue;
                }

                $amount = Money::of((string) $row->amount, $currency);

                /*
                 | Signed to read like the form.
                 |
                 | Output tax is a credit in the ledger and a positive figure
                 | on a return; input tax is a debit and also positive. So the
                 | output side is negated and the input side is not, and a
                 | return with a negative output box means credit notes
                 | exceeded sales - which is a real answer rather than a sign
                 | error.
                 */
                $figures[$box]['tax'] = $figures[$box]['tax']->plus(
                    $side === 'output' ? $amount->negated() : $amount,
                );

                $figures[$box]['lines'][] = [
                    'id' => (string) $row->id,
                    'tax_point' => (string) $row->tax_point,
                    'amount' => (string) $row->amount,
                ];

                continue;
            }

            if (isset($counterparty[$accountId])) {
                continue;
            }

            // The revenue or expense leg: the taxable amount.
            foreach (['output', 'input'] as $candidate) {
                $box = $mapping[$candidate] ?? null;

                if (! \is_string($box) || ! isset($figures[$box])) {
                    continue;
                }

                if (($definitions[$box]['side'] ?? null) !== $candidate) {
                    continue;
                }

                $amount = Money::of((string) $row->amount, $currency);

                $figures[$box]['net'] = $figures[$box]['net']->plus(
                    $candidate === 'output' ? $amount->negated() : $amount,
                );

                $figures[$box]['lines'][] = [
                    'id' => (string) $row->id,
                    'tax_point' => (string) $row->tax_point,
                    'amount' => (string) $row->amount,
                ];

                break;
            }
        }

        $boxes = [];

        foreach ($definitions as $name => $definition) {
            $boxes[] = new TaxReturnBox(
                (string) $name,
                $definition['side'],
                $definition['en'],
                $figures[$name]['net'],
                $figures[$name]['tax'],
                $figures[$name]['lines'],
            );
        }

        return new TaxReturn(
            (string) $period->jurisdiction,
            $form,
            $period->label,
            $from,
            $until,
            $currency,
            $boxes,
            /*
             | Corrections to EARLIER filed periods, reported in this one.
             |
             | Read here rather than left to a caller, because a return
             | assembled without them has a net due that is wrong by exactly
             | the correction - and it would be filed before anybody noticed
             | the schedule was missing.
             */
            $this->adjustments->scheduleFor($entityId, $period),
        );
    }

    /**
     * @param  list<ControlRole>  $roles
     * @return array<string, string> account id => role
     */
    private function accountsFor(string $entityId, array $roles): array
    {
        $accounts = [];

        $rows = AccountRole::query()
            ->where('entity_id', $entityId)
            ->whereIn('role', array_map(static fn (ControlRole $r): string => $r->value, $roles))
            ->get(['account_id', 'role']);

        foreach ($rows as $row) {
            $role = $row->role;
            $accounts[(string) $row->account_id] = $role instanceof ControlRole ? $role->value : (string) $role;
        }

        return $accounts;
    }
}
