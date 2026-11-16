<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is still owed, computed - PF-156.
 *
 * ## Why one query rather than a model with an accessor
 *
 * The engine asks "what is open" once per transaction and then compares
 * every candidate against it. An accessor on the model would be an N+1 that
 * grows with the number of open documents, which is exactly the account
 * where the engine matters most.
 *
 * ## And why it is computed at all
 *
 * `invoices` has no `paid_amount` column and `bills` has one, which makes
 * the inconsistency visible rather than hidden - but neither is used here.
 * The outstanding figure is gross, less credit notes, less every applied
 * match. A stored total is a second place the truth lives: it drifts the
 * first time a match is reversed or a credit note cancelled, and the
 * symptom is an invoice that reports itself settled while the customer is
 * still being chased.
 *
 * PF-156 asks for this by name, and this is where it is true.
 */
final class OpenDocuments
{
    /**
     * Open receivables, newest first within oldest-due.
     *
     * @return list<OpenDocument>
     */
    public function receivables(string $entityId, string $currency, ?string $partyId = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.number,
                   i.party_id,
                   p.legal_name AS party_name,
                   i.currency,
                   i.gross_amount,
                   i.issue_date,
                   /*
                    | Credit notes against it, and every match applied to it.
                    |
                    | Summed here rather than read from a column, because a
                    | column is a second place the truth lives and this is
                    | the figure the engine decides on.
                    */
                   coalesce((
                       SELECT sum(c.gross_amount)
                       FROM invoices c
                       JOIN invoice_references r ON r.invoice_id = c.id
                       WHERE r.referenced_invoice_id = i.id
                         AND c.document_type = '381'
                         AND c.status = 'issued'
                   ), 0) AS credited,
                   coalesce((
                       SELECT sum(abs(m.amount))
                       FROM matches m
                       WHERE m.matched_type = 'invoice'
                         AND m.matched_id = i.id
                         AND m.reversed_at IS NULL
                   ), 0) AS matched
            FROM invoices i
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE i.entity_id = ?
              AND i.currency = ?
              AND i.status = 'issued'
              AND i.document_type IN ('380', '383', '386')
              /*
               | Cast, because PostgreSQL cannot infer a bare parameter
               | compared against NULL - it reports "could not determine
               | data type of parameter" and the query never runs.
               */
              AND (?::char(26) IS NULL OR i.party_id = ?::char(26))
            ORDER BY i.issue_date, i.number
        SQL, [$entityId, $currency, $partyId, $partyId]);

        return $this->toDocuments($rows, 'invoice', $currency);
    }

    /**
     * Open payables.
     *
     * @return list<OpenDocument>
     */
    public function payables(string $entityId, string $currency, ?string $partyId = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT b.id,
                   b.supplier_document_number AS number,
                   b.party_id,
                   p.legal_name AS party_name,
                   b.currency,
                   b.gross_amount,
                   b.supplier_document_date AS issue_date,
                   coalesce((
                       SELECT sum(c.gross_amount)
                       FROM bills c
                       WHERE c.credits_bill_id = b.id
                         AND c.status IN ('approved', 'posted')
                   ), 0) AS credited,
                   coalesce((
                       SELECT sum(abs(m.amount))
                       FROM matches m
                       WHERE m.matched_type = 'bill'
                         AND m.matched_id = b.id
                         AND m.reversed_at IS NULL
                   ), 0) AS matched
            FROM bills b
            LEFT JOIN parties p ON p.id = b.party_id
            WHERE b.entity_id = ?
              AND b.currency = ?
              AND b.status = 'posted'
              AND b.document_type = '380'
              AND (?::char(26) IS NULL OR b.party_id = ?::char(26))
            ORDER BY b.supplier_document_date, b.supplier_document_number
        SQL, [$entityId, $currency, $partyId, $partyId]);

        return $this->toDocuments($rows, 'bill', $currency);
    }

    /**
     * Open documents on whichever side a transaction can settle.
     *
     * A credit settles receivables and a debit settles payables. Offering
     * both would let an amount-only pass match a receipt against a bill -
     * arithmetically possible, always wrong, and the mistake it makes on
     * the day a customer and a supplier are owed the same figure.
     *
     * @return list<OpenDocument>
     */
    public function forATransaction(
        string $entityId,
        string $currency,
        Money $amount,
        ?string $partyId = null,
    ): array {
        return $amount->amount->isPositive()
            ? $this->receivables($entityId, $currency, $partyId)
            : $this->payables($entityId, $currency, $partyId);
    }

    /**
     * @param  list<object>  $rows
     * @return list<OpenDocument>
     */
    private function toDocuments(array $rows, string $type, string $currency): array
    {
        $documents = [];

        foreach ($rows as $row) {
            $gross = Money::of((string) $row->gross_amount, $currency);

            $outstanding = $gross
                ->minus(Money::of((string) $row->credited, $currency))
                ->minus(Money::of((string) $row->matched, $currency));

            /*
             | A document with nothing left on it is not a candidate.
             |
             | Including it would let a second payment match an invoice that
             | is already settled - which is a duplicate receipt applied to
             | a document that cannot absorb it, and the kind of match that
             | looks right on the screen that made it.
             */
            if (! $outstanding->amount->isPositive()) {
                continue;
            }

            $documents[] = new OpenDocument(
                type: $type,
                id: (string) $row->id,
                number: (string) $row->number,
                partyId: $row->party_id === null ? null : (string) $row->party_id,
                partyName: $row->party_name === null ? null : (string) $row->party_name,
                currency: $currency,
                gross: $gross,
                outstanding: $outstanding,
                documentDate: Carbon::parse((string) $row->issue_date),
                dueDate: null,
            );
        }

        return $documents;
    }
}
