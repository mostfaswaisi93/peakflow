<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceAllowanceCharge;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Duplicating a document into a new draft - PF-092.
 *
 * ## Why this is a service and not `replicate()`
 *
 * Eloquent's `replicate()` copies every attribute it does not know to leave
 * out, and this row carries eight it must leave out: the number, the status,
 * the issue timestamp and attribution, and the four chain and canonical
 * columns. A duplicate that copied the number would consume one from a
 * gapless series without issuing anything, and a duplicate that copied the
 * chain columns would be a second document claiming another's position in it.
 *
 * `replicate(['number', ...])` would do the same job and would be wrong in a
 * year's time, when a column is added and nobody adds it to the list. This
 * names what it copies instead, so a new column is absent until somebody
 * decides it should be there.
 *
 * ## The date moves to today
 *
 * Because the usual reason to duplicate is "the same thing again this month".
 * Keeping the source's date produces a document dated in a period that may be
 * closed, and the person finds out at issue time rather than while editing.
 */
final class Drafts
{
    /**
     * A new draft carrying the source's substance and none of its identity.
     *
     * @param  Carbon|null  $date  the new draft's issue date; today when omitted
     */
    public function duplicate(Invoice $source, ?Carbon $date = null): Invoice
    {
        $date ??= Carbon::now();

        return DB::connection('tenant')->transaction(function () use ($source, $date): Invoice {
            $draft = Invoice::create([
                'entity_id' => $source->entity_id,
                'series_key' => $source->series_key,
                'document_type' => $source->document_type->value,
                'status' => 'draft',
                'party_id' => $source->party_id,
                'issue_date' => $date->toDateString(),
                'tax_point' => $date->toDateString(),
                'currency' => $source->currency,
                'net_amount' => $source->net_amount,
                'tax_amount' => $source->tax_amount,
                'gross_amount' => $source->gross_amount,
                'prepaid_amount' => '0.0000',
                'payable_amount' => $source->gross_amount,
                'presentation_exponent' => $source->presentation_exponent,
                'profile' => $source->profile,
                'note' => $source->note,
            ]);

            foreach ($source->lines()->orderBy('line_no')->get() as $line) {
                $copy = $line->replicate(['invoice_id', 'id', 'created_at', 'updated_at']);
                $copy->invoice_id = (string) $draft->getKey();
                $copy->tax_point = $date->toDateString();
                $copy->save();
            }

            foreach ($source->taxBreakdown()->get() as $row) {
                $copy = $row->replicate(['invoice_id', 'id', 'created_at', 'updated_at']);
                $copy->invoice_id = (string) $draft->getKey();
                $copy->save();
            }

            foreach ($source->allowancesAndCharges()->get() as $row) {
                $copy = $row->replicate(['invoice_id', 'id', 'created_at', 'updated_at']);
                $copy->invoice_id = (string) $draft->getKey();
                $copy->save();
            }

            /*
             | References are deliberately NOT copied.
             |
             | A credit note's reference to the invoice it credits is the one
             | thing that must not be duplicated: two credit notes pointing at
             | one invoice, each claiming to be the credit, is a reference an
             | auditor cannot resolve. The new document's references are the
             | new document's business.
             */
            return $draft->refresh();
        });
    }
}
