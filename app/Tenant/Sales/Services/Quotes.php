<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\QuoteState;
use App\Tenant\Sales\Exceptions\QuoteRefused;
use App\Tenant\Sales\Models\Quote;
use App\Tenant\Sales\Models\QuoteLine;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quotes: drafting, sending, revising, accepting - PF-089.
 *
 * ## The reference is not a legal number
 *
 * It comes from a series with the scope `quote`, which is gapless only
 * because the allocator is - gaps in it would mean nothing. What a tax
 * authority counts is the `sales_document` series, and a check asserts that
 * drafting, sending and revising a quote never touch it: a quote that
 * consumed an invoice number would leave a hole in a legal sequence for a
 * document that may never exist.
 *
 * ## Revising, rather than editing
 *
 * A sent quote is a thing somebody received. Revising it writes a new
 * version and marks the old one superseded, because "the price I was sent"
 * is a question about a document that existed - and a system that edited the
 * quote in place cannot answer it.
 *
 * The old version stays readable and stops being acceptable. Both halves
 * matter: deleting it loses the record, leaving it open means two prices are
 * live at once and the customer picks.
 */
final class Quotes
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * A new quote, in draft.
     *
     * @param  list<array{description: string, quantity: string, unit_price: string, tax_code_id?: string|null, tax_rate?: string, tax_category?: string, revenue_account_id?: string|null, item_id?: string|null, item_code?: string|null, unit_code?: string}>  $lines
     *
     * @throws QuoteRefused
     */
    public function draft(
        Entity $entity,
        Party $customer,
        array $lines,
        Carbon $validUntil,
        ?Carbon $date = null,
        ?string $terms = null,
        ?string $profile = null,
    ): Quote {
        $date ??= Carbon::now();

        if (! $customer->is_customer) {
            throw QuoteRefused::notACustomer((string) $customer->code);
        }

        if ($lines === []) {
            throw QuoteRefused::ofNothing();
        }

        if ($validUntil->lt($date)) {
            throw QuoteRefused::validityEndsBeforeItStarts(
                $validUntil->toDateString(),
                $date->toDateString(),
            );
        }

        $currency = (string) ($customer->default_currency ?? $entity->functional_currency);
        $profile ??= (string) config('jurisdictions.default_profile', 'generic');

        return DB::connection('tenant')->transaction(function () use (
            $entity, $customer, $lines, $validUntil, $date, $terms, $currency, $profile
        ): Quote {
            $reference = $this->numbers->next(
                (string) $entity->getKey(),
                'quote',
                'QT',
                $date,
            );

            return $this->write(
                $entity, $customer, $lines, $validUntil, $date, $terms, $currency, $profile,
                $reference, 1, null,
            );
        });
    }

    /**
     * Send it, which is the moment it becomes something the customer has.
     *
     * @throws QuoteRefused
     */
    public function send(Quote $quote, ?string $userId = null): Quote
    {
        $this->assertMayBecome($quote, QuoteState::Sent);

        return DB::connection('tenant')->transaction(function () use ($quote, $userId): Quote {
            $quote->update(['status' => QuoteState::Sent->value, 'sent_at' => now()]);

            $this->audit->record(
                category: 'sales',
                action: 'quote.sent',
                subjectType: 'quote',
                subjectId: (string) $quote->getKey(),
                subjectLabel: $quote->label(),
                after: [
                    'gross' => (string) $quote->gross_amount,
                    'valid_until' => $quote->valid_until?->toDateString(),
                ],
                entityId: (string) $quote->entity_id,
                partyId: (string) $quote->party_id,
            );

            return $quote->refresh();
        });
    }

    /**
     * A revision: a new version, with the old one superseded.
     *
     * @param  list<array<string, mixed>>|null  $lines  the new lines, or null to carry the old ones over
     *
     * @throws QuoteRefused
     */
    public function revise(
        Quote $quote,
        ?array $lines = null,
        ?Carbon $validUntil = null,
        ?Carbon $date = null,
    ): Quote {
        $this->assertMayBecome($quote, QuoteState::Superseded);

        $date ??= Carbon::now();
        $party = $quote->party;

        if ($party === null) {
            throw QuoteRefused::noCustomer();
        }

        $lines ??= $quote->lines()->get()->map(static fn (QuoteLine $line): array => [
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'tax_code_id' => $line->tax_code_id,
            'tax_rate' => (string) $line->tax_rate,
            'tax_category' => (string) $line->tax_category,
            'revenue_account_id' => $line->revenue_account_id,
            'item_id' => $line->item_id,
            'item_code' => $line->item_code,
            'unit_code' => (string) $line->unit_code,
        ])->all();

        if ($lines === []) {
            throw QuoteRefused::ofNothing();
        }

        $entity = \App\Tenant\Tenancy\Models\Entity::query()->findOrFail((string) $quote->entity_id);

        return DB::connection('tenant')->transaction(function () use (
            $quote, $lines, $validUntil, $date, $party, $entity
        ): Quote {
            $revision = $this->write(
                $entity,
                $party,
                $lines,
                $validUntil ?? $quote->valid_until ?? $date,
                $date,
                $quote->terms === null ? null : (string) $quote->terms,
                (string) $quote->currency,
                (string) ($quote->profile ?? 'generic'),

                /*
                 | The SAME reference, a higher version.
                 |
                 | Because it is the same conversation: a customer asked for
                 | a change to quote QT-2026-00007 and what they get back is
                 | QT-2026-00007 v2. A new reference would make the two look
                 | like unrelated offers, and somebody would accept the old
                 | one.
                 */
                (string) $quote->reference,
                (int) $quote->version + 1,
                (string) $quote->getKey(),
            );

            $quote->update(['status' => QuoteState::Superseded->value]);

            $this->audit->record(
                category: 'sales',
                action: 'quote.revised',
                subjectType: 'quote',
                subjectId: (string) $revision->getKey(),
                subjectLabel: $revision->label(),
                before: ['gross' => (string) $quote->gross_amount, 'version' => (int) $quote->version],
                after: ['gross' => (string) $revision->gross_amount, 'version' => (int) $revision->version],
                entityId: (string) $quote->entity_id,
                partyId: (string) $quote->party_id,
            );

            return $revision;
        });
    }

    /**
     * The customer accepts, by name and from an address.
     *
     * @throws QuoteRefused
     */
    public function accept(Quote $quote, string $name, ?string $ip = null, ?Carbon $on = null): Quote
    {
        $this->assertMayBecome($quote, QuoteState::Accepted);

        if (trim($name) === '') {
            throw QuoteRefused::anAnonymousAcceptance();
        }

        if ($quote->hasLapsed($on)) {
            throw QuoteRefused::hasLapsed($quote->label(), (string) $quote->valid_until?->toDateString());
        }

        return DB::connection('tenant')->transaction(function () use ($quote, $name, $ip): Quote {
            $quote->update([
                'status' => QuoteState::Accepted->value,
                'accepted_at' => now(),
                'accepted_by_name' => trim($name),
                'accepted_from_ip' => $ip,
            ]);

            $this->audit->record(
                category: 'sales',
                action: 'quote.accepted',
                subjectType: 'quote',
                subjectId: (string) $quote->getKey(),
                subjectLabel: $quote->label(),
                after: [
                    'gross' => (string) $quote->gross_amount,
                    'accepted_by' => trim($name),
                    'from' => $ip,
                ],
                reason: 'the customer accepted the quote through its signed link',
                entityId: (string) $quote->entity_id,
                partyId: (string) $quote->party_id,
            );

            return $quote->refresh();
        });
    }

    /** @throws QuoteRefused */
    public function decline(Quote $quote, ?string $reason = null, ?string $ip = null): Quote
    {
        $this->assertMayBecome($quote, QuoteState::Declined);

        return DB::connection('tenant')->transaction(function () use ($quote, $reason, $ip): Quote {
            $quote->update([
                'status' => QuoteState::Declined->value,
                'declined_at' => now(),
                'declined_reason' => $reason,
                'accepted_from_ip' => $ip,
            ]);

            $this->audit->record(
                category: 'sales',
                action: 'quote.declined',
                subjectType: 'quote',
                subjectId: (string) $quote->getKey(),
                subjectLabel: $quote->label(),
                after: ['reason' => $reason],
                entityId: (string) $quote->entity_id,
                partyId: (string) $quote->party_id,
            );

            return $quote->refresh();
        });
    }

    /**
     * Close every quote whose validity has run out.
     *
     * Returns what it closed, so the command can say so rather than printing
     * a count: a job that reports "17 quotes expired" is a job nobody can
     * check.
     *
     * @return list<array{reference: string, gross: string, valid_until: string}>
     */
    public function expire(?Carbon $on = null): array
    {
        $on ??= Carbon::now();
        $closed = [];

        $lapsed = Quote::query()
            ->where('status', QuoteState::Sent->value)
            ->whereDate('valid_until', '<', $on->toDateString())
            ->orderBy('valid_until')
            ->get();

        foreach ($lapsed as $quote) {
            DB::connection('tenant')->transaction(function () use ($quote, &$closed): void {
                $quote->update(['status' => QuoteState::Expired->value, 'expired_at' => now()]);

                $closed[] = [
                    'reference' => $quote->label(),
                    'gross' => (string) $quote->gross_amount,
                    'valid_until' => (string) $quote->valid_until?->toDateString(),
                ];

                $this->audit->record(
                    category: 'sales',
                    action: 'quote.expired',
                    subjectType: 'quote',
                    subjectId: (string) $quote->getKey(),
                    subjectLabel: $quote->label(),
                    after: ['valid_until' => $quote->valid_until?->toDateString()],
                    reason: 'the validity date passed',
                    entityId: (string) $quote->entity_id,
                    partyId: (string) $quote->party_id,
                );
            });
        }

        return $closed;
    }

    /**
     * The signed link a customer opens - PF-089.
     *
     * ## Why it expires, and when
     *
     * A link that never expires is a key somebody forwards. It lasts until a
     * week after the quote does, which is long enough for "we discussed this
     * last month, can you resend" to work on the original link and short
     * enough that a forwarded email from last year opens nothing.
     *
     * The tenant and the entity are in the path because the visitor has no
     * session and row-level security reads nothing without an entity - and
     * both are inside the signature, so neither can be edited.
     */
    public function linkFor(Quote $quote, ?string $tenantSlug = null): string
    {
        $tenant = $tenantSlug ?? (string) (app('currentTenant')->slug ?? app('currentTenant')->getKey());

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'public.quote.show',
            ($quote->valid_until ?? Carbon::now())->copy()->addWeek()->endOfDay(),
            [
                'tenant' => $tenant,
                'entity' => (string) $quote->entity_id,
                'quote' => (string) $quote->getKey(),
            ],
        );
    }

    /**
     * What changed between two versions, line by line.
     *
     * ## Why this is a service and not a view
     *
     * "A revised quote is comparable to the previous one" is a claim about
     * the data, and a comparison written in a template is a comparison that
     * exists once, in one language, in one place nobody can test. The
     * customer-facing page, the internal screen and the check all read this.
     *
     * Lines are matched by their content, not by their position: moving a
     * line up the page is not a change to the offer, and a diff that said it
     * was would make every revision look like a rewrite.
     *
     * @return array{
     *     added: list<array<string, string|null>>,
     *     removed: list<array<string, string|null>>,
     *     changed: list<array{before: array<string, string|null>, after: array<string, string|null>}>,
     *     totals: array{before: string, after: string, difference: string},
     * }
     */
    public function compare(Quote $before, Quote $after): array
    {
        $old = $before->lines()->get()->map(static fn (QuoteLine $l): array => $l->comparable())->all();
        $new = $after->lines()->get()->map(static fn (QuoteLine $l): array => $l->comparable())->all();

        $key = static fn (array $line): string => strtolower((string) ($line['item_code'] ?? '')).'|'.
            strtolower((string) $line['description']);

        $oldByKey = [];
        $newByKey = [];

        foreach ($old as $line) {
            $oldByKey[$key($line)][] = $line;
        }

        foreach ($new as $line) {
            $newByKey[$key($line)][] = $line;
        }

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($newByKey as $k => $lines) {
            foreach ($lines as $index => $line) {
                $match = $oldByKey[$k][$index] ?? null;

                if ($match === null) {
                    $added[] = $line;

                    continue;
                }

                if ($match !== $line) {
                    $changed[] = ['before' => $match, 'after' => $line];
                }
            }
        }

        foreach ($oldByKey as $k => $lines) {
            foreach ($lines as $index => $line) {
                if (! isset($newByKey[$k][$index])) {
                    $removed[] = $line;
                }
            }
        }

        $difference = Money::of((string) $after->gross_amount, (string) $after->currency)
            ->minus(Money::of((string) $before->gross_amount, (string) $before->currency));

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'totals' => [
                'before' => (string) $before->gross_amount,
                'after' => (string) $after->gross_amount,
                'difference' => (string) $difference->amount,
            ],
        ];
    }

    /** @throws QuoteRefused */
    private function assertMayBecome(Quote $quote, QuoteState $to): void
    {
        if (! \in_array($to, $quote->status->mayBecome(), true)) {
            throw QuoteRefused::theTransition(
                $quote->label(),
                $quote->status,
                $to,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function write(
        Entity $entity,
        Party $customer,
        array $lines,
        Carbon $validUntil,
        Carbon $date,
        ?string $terms,
        string $currency,
        string $profile,
        string $reference,
        int $version,
        ?string $supersedesId,
    ): Quote {
        $taxable = [];
        $prepared = [];

        foreach ($lines as $index => $line) {
            $quantity = (string) $line['quantity'];
            $unitPrice = (string) $line['unit_price'];

            $net = Money::of(
                (string) \Brick\Math\BigDecimal::of($quantity)
                    ->multipliedBy($unitPrice)
                    ->toScale(4, \Brick\Math\RoundingMode::HalfUp),
                $currency,
            );

            if ($net->isNegative()) {
                throw QuoteRefused::aNegativeLine($index + 1);
            }

            $category = TaxCategory::from((string) ($line['tax_category'] ?? 'S'));

            $prepared[] = $line + [
                'line_no' => $index + 1,
                'net_amount' => (string) $net->amount,
                'category' => $category,
            ];

            $taxable[] = TaxableLine::of($net, $category, (string) ($line['tax_rate'] ?? '0'));
        }

        $breakdown = $this->calculator->compute(
            $taxable,
            $this->rounding->policyFor($profile, $currency),
        );

        $quote = Quote::create([
            'entity_id' => (string) $entity->getKey(),
            'reference' => $reference,
            'version' => $version,
            'party_id' => (string) $customer->getKey(),
            'status' => QuoteState::Draft->value,
            'issue_date' => $date->toDateString(),
            'valid_until' => $validUntil->toDateString(),
            'currency' => $currency,
            'net_amount' => (string) $breakdown->net->amount,
            'tax_amount' => (string) $breakdown->tax->amount,
            'gross_amount' => (string) $breakdown->gross()->amount,
            'presentation_exponent' => 2,
            'profile' => $profile,
            'terms' => $terms,
            'supersedes_id' => $supersedesId,
        ]);

        foreach ($prepared as $line) {
            QuoteLine::create([
                'entity_id' => (string) $entity->getKey(),
                'quote_id' => (string) $quote->getKey(),
                'line_no' => $line['line_no'],
                'item_id' => $line['item_id'] ?? null,
                'item_code' => $line['item_code'] ?? null,
                'description' => (string) $line['description'],
                'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'net_amount' => (string) $line['net_amount'],
                'revenue_account_id' => $line['revenue_account_id'] ?? null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'tax_category' => $line['category']->value,
                'tax_rate' => (string) ($line['tax_rate'] ?? '0'),
            ]);
        }

        return $quote->refresh();
    }
}
