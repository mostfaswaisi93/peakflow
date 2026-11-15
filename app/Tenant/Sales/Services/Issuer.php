<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Hashing\Canonicalizer;
use App\Support\Money\Money;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Sales\Exceptions\IssueRefused;
use App\Tenant\Sales\InvoiceFinding;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Posting\Rules\InvoiceIssuedRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The issuing transaction - PF-095, PF-093, PF-096.
 *
 * ## Five concerns, one transaction
 *
 * In this order, and the order is the design:
 *
 *   1. validate                 - refuse while nothing has been consumed
 *   2. freeze the snapshots     - the parties, the terms, the tax decision
 *   3. post to the ledger       - synchronously, inside the transaction
 *   4. allocate the number      - LAST, so the series lock is held briefly
 *   5. write the chain link     - over the canonical, legally significant form
 *
 * Then commit, and only then dispatch anything that touches the outside world.
 *
 * ## Why the number is allocated last - PF-093
 *
 * The series row is locked with `SELECT ... FOR UPDATE` from the moment it is
 * read until the transaction commits. Allocate first and that lock is held
 * through validation, the tax computation and the posting - so every other
 * invoice in the entity waits behind the slowest document anybody is issuing.
 *
 * Allocating last makes the critical section the last few milliseconds of the
 * transaction instead of all of it.
 *
 * ## Why a `SEQUENCE` cannot be used
 *
 * `nextval()` is deliberately non-transactional: it does not roll back. A
 * validation failure, a deadlock or a crashed worker would each leave a number
 * consumed and never used, and a gap in an invoice series is a finding in a
 * tax audit - the auditor's question is not "why is 41 missing" but "what did
 * you do with invoice 41". The counter is therefore a row.
 *
 * ## No I/O inside the lock
 *
 * Nothing between the allocation and the commit may talk to anything: no PDF
 * render, no clearance call, no mail, no queue dispatch. A tax authority that
 * takes thirty seconds to answer must not hold the invoice sequence for thirty
 * seconds, and a mail server that hangs must not stop the business invoicing.
 *
 * The post-commit work is returned to the caller rather than dispatched here,
 * so there is no `dispatch()` in this file at all - which is a property a
 * check can read.
 */
final class Issuer
{
    public function __construct(
        private readonly InvoiceValidator $validator,
        private readonly Snapshots $snapshots,
        private readonly NumberAllocator $numbers,
        private readonly Poster $poster,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Issue a draft.
     *
     * @param  array<string, mixed>  $taxDecision  the place-of-supply decision as resolved at draft time
     *
     * @throws IssueRefused
     */
    public function issue(
        Invoice $draft,
        Entity $entity,
        string $userId,
        array $taxDecision,
        ?\App\Tenant\Catalog\Models\PaymentTerm $terms = null,
    ): Invoice {
        if ($draft->isIssued()) {
            throw IssueRefused::alreadyIssued((string) $draft->number);
        }

        return DB::connection('tenant')->transaction(function () use (
            $draft, $entity, $userId, $taxDecision, $terms,
        ): Invoice {
            /*
             | Read the row before hashing anything over it.
             |
             | A model built by `create()` does not know the column defaults:
             | `prepaid_amount` is `0` in the database and absent in memory,
             | so the chain payload hashed a null where the verifier - which
             | reads the stored row - sees "0.0000". Measured on the first
             | chain walk: three genuine documents reported as diverging at
             | `totals.prepaid`, "it was (absent) and is now 0.0000".
             |
             | The frozen bytes have to describe the stored row, because the
             | stored row is what anybody will ever re-read.
             */
            $draft->refresh();

            /*
             | 1. Validate, while nothing has been consumed.
             |
             | Inside the transaction rather than before it, because the
             | period could close and a registration could lapse between a
             | check and a write - and the check that passed is the one
             | somebody would quote afterwards.
             */
            $findings = $this->validator->blocking($this->validator->validate($draft, $entity));

            if ($findings !== []) {
                throw IssueRefused::theDraftIsNotValid(array_map(
                    static fn (InvoiceFinding $finding): string => $finding->field.': '.$finding->messageEn,
                    $findings,
                ));
            }

            $jurisdiction = (string) ($taxDecision['country'] ?? $entity->country);
            $taxPoint = $draft->tax_point ?? $draft->issue_date;

            // 2. Freeze.
            $snapshot = [
                'supplier_snapshot' => $this->snapshots->supplier($entity, $jurisdiction, $taxPoint),
                'customer_snapshot' => $this->snapshots->customer(
                    $draft->party ?? throw IssueRefused::theDraftIsNotValid(['party_id: no customer']),
                    $jurisdiction,
                    $taxPoint,
                ),
                'tax_decision_snapshot' => $taxDecision,
                'terms_snapshot' => $terms === null
                    ? $draft->terms_snapshot
                    : $this->snapshots->terms($terms, $entity, $draft->issue_date, $draft->gross()),
            ];

            // 3. Post, synchronously.
            $entry = $this->post($draft, $entity, $userId);

            /*
             | 4. The number. Last.
             |
             | Everything that can fail has already failed by here, so the
             | series lock is held for the remainder of this transaction -
             | which is the chain write and the commit.
             */
            $number = $this->numbers->next(
                (string) $entity->getKey(),
                'sales_document',
                $draft->series_key,
                Carbon::parse($draft->issue_date->toDateString()),
            );

            // 5. The chain link, over the canonical legally significant form.
            $chain = $this->chainLink($draft, $entity, $number, $snapshot);

            $draft->update(array_merge($snapshot, [
                'status' => 'issued',
                'number' => $number,
                'tax_point' => $taxPoint->toDateString(),
                'issued_at' => now(),
                'issued_by' => $userId,
                'canonical' => $chain['canonical'],
                'canonical_hash' => $chain['hash'],
                'previous_hash' => $chain['previous'],
                'chain_index' => $chain['index'],
            ]));

            $this->audit->record(
                category: 'sales',
                action: 'invoice.issued',
                subjectType: 'invoice',
                subjectId: (string) $draft->getKey(),
                subjectLabel: $number,
                after: [
                    'number' => $number,
                    'gross' => (string) $draft->gross(),
                    'hash' => $chain['hash'],
                    'chain_index' => $chain['index'],
                    'journal_entry' => (string) $entry->getKey(),
                ],
                entityId: (string) $entity->getKey(),
            );

            return $draft->refresh();
        });
    }

    /**
     * What has to happen after the commit, for the caller to dispatch.
     *
     * Returned rather than dispatched, so this class never queues anything
     * inside the transaction - a job that ran before the commit would read a
     * document that does not exist yet, and one dispatched inside a
     * transaction that then rolls back is a job about a document that never
     * existed.
     *
     * @return list<array{job: string, invoice_id: string}>
     */
    public function afterCommit(Invoice $invoice): array
    {
        return [
            ['job' => 'render.pdf', 'invoice_id' => (string) $invoice->getKey()],
            ['job' => 'deliver', 'invoice_id' => (string) $invoice->getKey()],
            ['job' => 'clearance.submit', 'invoice_id' => (string) $invoice->getKey()],
        ];
    }

    /**
     * The legally significant fields, canonicalized - PF-096.
     *
     * NOT the whole row. A cosmetic change must not invalidate a chain and a
     * material one must not avoid it, so the hash covers the parties, the
     * dates, the currency, the totals, the tax breakdown and each line's
     * substance - and not the note, the timestamps or anything about
     * presentation.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{canonical: string, hash: string, previous: string, index: int}
     */
    private function chainLink(Invoice $draft, Entity $entity, string $number, array $snapshot): array
    {
        $previous = Invoice::query()
            ->where('entity_id', (string) $entity->getKey())
            ->where('series_key', $draft->series_key)
            ->whereNotNull('chain_index')
            ->orderByDesc('chain_index')
            ->first();

        $genesis = str_repeat('0', 64);

        $payload = [
            'number' => $number,
            'series' => $draft->series_key,
            'type' => $draft->document_type->value,
            'issue_date' => $draft->issue_date->toDateString(),
            'tax_point' => ($draft->tax_point ?? $draft->issue_date)->toDateString(),
            'currency' => $draft->currency,
            'totals' => [
                'net' => $draft->net_amount,
                'tax' => $draft->tax_amount,
                'gross' => $draft->gross_amount,
                'prepaid' => $draft->prepaid_amount,
                'payable' => $draft->payable_amount,
            ],
            'supplier' => $snapshot['supplier_snapshot'],
            'customer' => $snapshot['customer_snapshot'],
            'tax_decision' => $snapshot['tax_decision_snapshot'],
            'lines' => $draft->lines()->get()->map(static fn (InvoiceLine $line): array => [
                'line_no' => $line->line_no,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'net' => $line->net_amount,
                'category' => $line->tax_category->value,
                'rate' => $line->tax_rate,
            ])->all(),
            'breakdown' => $draft->taxBreakdown()->get()
                ->map(static fn (object $row): array => [
                    'category' => $row->category->value,
                    'rate' => $row->rate,
                    'taxable' => $row->taxable_amount,
                    'tax' => $row->tax_amount,
                ])->all(),
            'previous' => $previous?->canonical_hash ?? $genesis,
        ];

        $canonical = Canonicalizer::encode($payload);

        return [
            'canonical' => $canonical,
            'hash' => hash('sha256', $canonical),
            'previous' => $previous?->canonical_hash ?? $genesis,
            'index' => ($previous?->chain_index ?? 0) + 1,
        ];
    }

    private function post(Invoice $draft, Entity $entity, string $userId): JournalEntry
    {
        $revenue = [];

        foreach ($draft->lines()->get() as $line) {
            $revenue[] = [
                'account_id' => (string) $line->revenue_account_id,
                'amount' => $line->net_amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $line->tax_point?->toDateString(),
                'dimensions' => array_values(array_filter([
                    $line->dimension_1, $line->dimension_2, $line->dimension_3, $line->dimension_4,
                ])),
            ];
        }

        $tax = [];

        foreach ($draft->taxBreakdown()->get() as $row) {
            if (Money::of($row->tax_amount, $draft->currency)->isZero()) {
                continue;
            }

            $tax[] = [
                'jurisdiction' => (string) ($draft->tax_decision_snapshot['country']
                    ?? $draft->lines()->value('place_of_supply')
                    ?? $entity->country),
                'amount' => $row->tax_amount,
                'tax_code_id' => (string) $draft->lines()
                    ->where('tax_category', $row->category->value)
                    ->value('tax_code_id'),
                'tax_point' => ($draft->tax_point ?? $draft->issue_date)->toDateString(),
            ];
        }

        return $this->poster->post(
            $entity,
            InvoiceIssuedRule::KEY,
            [
                'invoice_id' => (string) $draft->getKey(),
                'party_id' => (string) $draft->party_id,
                'gross' => $draft->gross_amount,
                'narration' => sprintf('%s %s', $draft->document_type->label('en'), $draft->series_key),
                'revenue' => $revenue,
                'tax' => $tax,
            ],
            Carbon::parse($draft->issue_date->toDateString()),
            'SAL',
            'invoice',
            (string) $draft->getKey(),
        );
    }
}
