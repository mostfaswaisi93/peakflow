<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\TranslatesMoney;
use App\Tenant\Parties\Exceptions\CreditRefused;
use App\Tenant\Parties\Models\Party;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Credit limits, exposure and holds - PF-062.
 *
 * ## Exposure is open receivables PLUS uninvoiced orders
 *
 * The second half is the part that gets left out, and leaving it out is what
 * makes a credit limit decorative: a customer at their limit places four more
 * orders, none of which is an invoice yet, and the limit is breached by the
 * time anybody looks. The orders are the exposure the business is actually
 * carrying.
 *
 * It is checked BEFORE an order is confirmed, not at invoicing. Refusing at
 * invoice time means the goods have shipped.
 *
 * ## A hold has an author, a time and a reason, or it is not a hold
 *
 * "Why was our order refused" is answered by naming who placed the hold and
 * what they wrote. A boolean cannot answer it, so the flag is maintained from
 * an event history by a database trigger - and the flag cannot be set
 * directly, because a hold with no author is one nobody can defend.
 *
 * ## An override is bounded, justified and audited
 *
 * Bounded in time and in amount, because the person granting one almost
 * always means "for this order" - and an override with no expiry is a limit
 * removed. The justification is required text rather than a checkbox: an
 * override is the moment a control is being set aside, and the only thing
 * that makes it reviewable afterwards is what the person wrote.
 *
 * ## A dispute suspends dunning and does not clear the debt
 *
 * A disputed invoice is still owed, still counts towards exposure and still
 * ages. What stops is the escalation. Excluding it from exposure would be the
 * easy implementation and would let a customer keep buying by disputing
 * everything.
 */
final class CreditControl
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly PartyMerger $merges,

        /*
         | Through the Support seam, not `Catalog`'s service.
         |
         | `Parties` depends on `Support` and `Tenancy` and nothing else, and
         | an exposure figure that includes orders in three currencies is
         | still a figure in one - so the need is real and the dependency is
         | not allowed. Same decision as the audit trail.
         */
        private readonly TranslatesMoney $rates,
    ) {}

    /**
     * What this customer is carrying, against what they are allowed.
     *
     * @return array{
     *     currency: string,
     *     receivables: string,
     *     uninvoiced_orders: string,
     *     disputed: string,
     *     exposure: string,
     *     limit: ?string,
     *     headroom: ?string,
     *     over_limit: bool,
     *     on_hold: bool,
     *     hold_reason: ?string,
     *     override: ?array{expires_at: string, up_to_amount: ?string, justification: string},
     *     derivation: string,
     * }
     */
    public function exposure(string $entityId, string $partyId, string $functionalCurrency): array
    {
        $receivables = $this->openReceivables($entityId, $partyId);
        $orders = $this->uninvoicedOrders($entityId, $partyId);
        $disputed = $this->disputedAmount($entityId, $partyId);

        $exposure = Money::of($receivables, $functionalCurrency)
            ->plus(Money::of($orders, $functionalCurrency));

        $settings = DB::connection('tenant')->table('party_entity_settings')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->first();

        $limit = $settings?->credit_limit === null ? null : (string) $settings->credit_limit;
        $override = $this->activeOverride($entityId, $partyId);

        $headroom = $limit === null
            ? null
            : (string) Money::of($limit, $functionalCurrency)->minus($exposure)->amount;

        return [
            'currency' => $functionalCurrency,
            'receivables' => $receivables,
            'uninvoiced_orders' => $orders,
            /*
             | Reported separately and INCLUDED in the exposure.
             |
             | A disputed invoice is still owed. Netting it off would let a
             | customer keep buying by disputing everything, which is not a
             | theoretical concern - it is the standard response to a credit
             | block.
             */
            'disputed' => $disputed,
            'exposure' => (string) $exposure->amount,
            'limit' => $limit,
            'headroom' => $headroom,
            'over_limit' => $limit !== null
                && $exposure->isGreaterThan(Money::of($limit, $functionalCurrency)),
            'on_hold' => (bool) ($settings?->credit_hold ?? false),
            'hold_reason' => $settings?->credit_hold_reason === null
                ? null
                : (string) $settings->credit_hold_reason,
            'override' => $override,
            'derivation' => sprintf(
                '%s open receivables plus %s uninvoiced orders is %s against a limit of %s%s',
                $receivables,
                $orders,
                (string) $exposure->amount,
                $limit ?? 'none',
                $disputed === '0.0000' ? '' : sprintf(' (%s of it disputed and still counted)', $disputed),
            ),
        ];
    }

    /**
     * Refuse a new commitment that would breach the limit or a hold.
     *
     * Called before an order is CONFIRMED, not at invoicing: refusing at
     * invoice time means the goods have already shipped.
     *
     * @throws CreditRefused
     */
    public function assertCanCommit(
        string $entityId,
        Party $party,
        string $functionalCurrency,
        ?Money $additional = null,
    ): array {
        $state = $this->exposure($entityId, (string) $party->getKey(), $functionalCurrency);

        $proposed = $additional === null
            ? Money::of($state['exposure'], $functionalCurrency)
            : Money::of($state['exposure'], $functionalCurrency)->plus($additional);

        $override = $state['override'];

        /*
         | A hold outranks a limit, and an override can lift either.
         |
         | Checked in that order because the messages differ and the reader
         | needs the binding reason: "you are on hold" sends them to whoever
         | placed it, and "you are 4,000 over" sends them to accounts.
         */
        if ($state['on_hold'] && $override === null) {
            $this->refuse($entityId, $party, 'credit.hold', sprintf(
                '%s is on credit hold: %s',
                (string) $party->legal_name,
                $state['hold_reason'] ?? 'no reason was recorded',
            ));
        }

        if ($state['limit'] !== null) {
            $limit = Money::of($state['limit'], $functionalCurrency);
            $ceiling = $this->effectiveCeiling($limit, $override, $functionalCurrency);

            if ($proposed->isGreaterThan($ceiling)) {
                $this->refuse($entityId, $party, 'credit.limit', sprintf(
                    '%s would be %s %s over their %s limit of %s. %s',
                    (string) $party->legal_name,
                    (string) $proposed->minus($ceiling)->amount,
                    $functionalCurrency,
                    $override === null ? 'credit' : 'overridden credit',
                    (string) $ceiling->amount,
                    $state['derivation'],
                ));
            }
        }

        return $state + ['proposed_exposure' => (string) $proposed->amount];
    }

    /**
     * Place or lift a hold.
     *
     * The flag on `party_entity_settings` is maintained by a database
     * trigger from the event this writes, so the two cannot disagree - and
     * the flag cannot be written directly, so there is no hold without an
     * author.
     */
    public function hold(
        string $entityId,
        Party $party,
        string $reason,
        string $functionalCurrency,
        bool $lift = false,
        string $source = 'manual',
    ): void {
        $state = $this->exposure($entityId, (string) $party->getKey(), $functionalCurrency);

        DB::connection('tenant')->transaction(function () use (
            $entityId,
            $party,
            $reason,
            $functionalCurrency,
            $lift,
            $source,
            $state,
        ): void {
            DB::connection('tenant')->table('credit_hold_events')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $entityId,
                'party_id' => $party->getKey(),
                'direction' => $lift ? 'lifted' : 'placed',
                /*
                 | Who decided - PF-109.
                 |
                 | The automatic release lifts only what the policy placed,
                 | and `manual` is the default because a hold a person put
                 | on by hand must not be liftable by a nightly job.
                 */
                'source' => $source,
                'reason' => $reason,
                'by_user_id' => auth()->guard('web')->id(),
                'at' => now(),
                /*
                 | The figures AS THEY WERE.
                 |
                 | Recomputing them later gives today's numbers, and "why
                 | was this customer put on hold in March" is answered by
                 | the March figures. They are also what makes the decision
                 | defensible when the customer disputes it.
                 */
                'exposure_at_decision' => $state['exposure'],
                'limit_at_decision' => $state['limit'],
                'currency' => $functionalCurrency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'credit',
                action: $lift ? 'credit.hold.lifted' : 'credit.hold.placed',
                subjectType: Party::class,
                subjectId: (string) $party->getKey(),
                subjectLabel: (string) $party->legal_name,
                after: [
                    'exposure' => $state['exposure'],
                    'limit' => $state['limit'],
                    'currency' => $functionalCurrency,
                ],
                reason: $reason,
                entityId: $entityId,
                partyId: (string) $party->getKey(),
            );
        });
    }

    /**
     * The hold a party is under now, if any - PF-109.
     *
     * The latest event, and only if it placed one: the flag on
     * `party_entity_settings` says whether there is a hold, and this says
     * whose it is. The automatic release needs the second question, and a
     * nightly job that answered it by matching words in a free-text reason
     * would lift a hold the credit manager placed by hand the first time
     * somebody reworded a template.
     *
     * @return array{id: string, source: string, reason: string, at: string}|null
     */
    public function currentHold(string $entityId, string $partyId): ?array
    {
        $event = DB::connection('tenant')->table('credit_hold_events')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->first();

        if ($event === null || (string) $event->direction !== 'placed') {
            return null;
        }

        return [
            'id' => (string) $event->id,
            'source' => (string) $event->source,
            'reason' => (string) $event->reason,
            'at' => (string) $event->at,
        ];
    }

    /**
     * Grant a bounded, justified override.
     *
     * @throws CreditRefused if the window or the justification is unusable
     */
    public function override(
        string $entityId,
        Party $party,
        string $justification,
        Carbon $until,
        ?Money $upTo = null,
    ): string {
        /*
         | A justification of "ok" is not one.
         |
         | Refused here rather than accepted and reviewed later, because the
         | review happens when something has gone wrong and the only
         | artefact is what somebody typed in a hurry. Twenty characters is
         | not a high bar; it is enough to stop a single word.
         */
        if (mb_strlen(trim($justification)) < 20) {
            throw CreditRefused::justificationTooThin();
        }

        if ($until->isPast()) {
            throw CreditRefused::overrideExpiresInThePast($until->toDateTimeString());
        }

        $id = (string) Str::ulid();
        $userId = auth()->guard('web')->id();

        if ($userId === null) {
            throw CreditRefused::overrideNeedsAnAuthor();
        }

        DB::connection('tenant')->transaction(function () use (
            $id,
            $entityId,
            $party,
            $justification,
            $until,
            $upTo,
            $userId,
        ): void {
            DB::connection('tenant')->table('credit_overrides')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'party_id' => $party->getKey(),
                'justification' => $justification,
                'granted_by_user_id' => $userId,
                'granted_at' => now(),
                'expires_at' => $until,
                'up_to_amount' => $upTo === null ? null : (string) $upTo->amount,
                'currency' => $upTo?->currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'credit',
                action: 'credit.override.granted',
                subjectType: Party::class,
                subjectId: (string) $party->getKey(),
                subjectLabel: (string) $party->legal_name,
                after: [
                    'expires_at' => $until->toDateTimeString(),
                    'up_to_amount' => $upTo === null ? null : (string) $upTo->amount,
                ],
                reason: $justification,
                entityId: $entityId,
                partyId: (string) $party->getKey(),
            );
        });

        return $id;
    }

    /**
     * Raise a dispute, which suspends dunning for that document.
     */
    public function dispute(
        string $entityId,
        string $documentType,
        string $documentId,
        ?Party $party,
        string $reasonCode,
        string $detail,
        ?Money $amount = null,
    ): string {
        $id = (string) Str::ulid();

        DB::connection('tenant')->transaction(function () use (
            $id,
            $entityId,
            $documentType,
            $documentId,
            $party,
            $reasonCode,
            $detail,
            $amount,
        ): void {
            DB::connection('tenant')->table('document_disputes')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'party_id' => $party?->getKey(),
                'reason_code' => $reasonCode,
                'detail' => $detail,
                'disputed_amount' => $amount === null ? null : (string) $amount->amount,
                'currency' => $amount?->currency,
                'raised_by_user_id' => auth()->guard('web')->id(),
                'raised_at' => now(),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'credit',
                action: 'document.disputed',
                subjectType: $documentType,
                subjectId: $documentId,
                subjectLabel: sprintf('%s dispute: %s', $reasonCode, Str::limit($detail, 80)),
                after: ['reason_code' => $reasonCode, 'amount' => $amount === null ? null : (string) $amount->amount],
                reason: 'dunning is suspended for this document while the dispute is open; the amount is '.
                    'still owed and still counts towards exposure',
                entityId: $entityId,
                partyId: $party === null ? null : (string) $party->getKey(),
            );
        });

        return $id;
    }

    /** Whether dunning may escalate a document. */
    public function dunningSuspended(string $entityId, string $documentType, string $documentId): bool
    {
        return DB::connection('tenant')->table('document_disputes')
            ->where('entity_id', $entityId)
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'open')
            ->exists();
    }

    /**
     * Open receivables, from the ledger.
     *
     * From the control account rather than from a subledger of invoices,
     * because the ledger is the one source every other figure reconciles to
     * - and a credit limit measured against a number that disagrees with the
     * trial balance is a limit nobody will trust after the first argument.
     */
    private function openReceivables(string $entityId, string $partyId): string
    {
        /*
         | Across the MERGE CHAIN, not just this party.
         |
         | Posted journal lines are immutable, so a merge cannot repoint
         | them - a duplicate customer's invoices still name the party they
         | were issued to, and always will. Summing only the survivor's own
         | lines reports a customer who owes 46,000 as owing nothing, which
         | is the exposure check failing in the one direction that matters.
         */
        $identities = $this->merges->identities($partyId);
        $placeholders = implode(', ', array_fill(0, count($identities), 'cast(? AS char(26))'));

        $total = DB::connection('tenant')->selectOne(<<<SQL
            SELECT coalesce(sum(l.functional_amount), 0) AS total
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN account_roles ar ON ar.account_id = l.account_id AND ar.entity_id = l.entity_id
            WHERE l.entity_id = cast(? AS char(26))
              AND l.party_id IN ({$placeholders})
              AND ar.role = 'accounts_receivable'
              AND e.status IN ('posted', 'reversed')
        SQL, [$entityId, ...$identities]);

        return (string) ($total->total ?? '0');
    }

    /**
     * Orders placed and not yet invoiced - PF-090.
     *
     * ## Why it is summed from the LINES
     *
     * The balance left to invoice is tracked per order line, because "have
     * we invoiced the installation yet?" is the question people ask. The
     * order header has no open-amount column on purpose: a second total
     * there would be a second answer to the same question, and the one that
     * drifts is always the denormalised one.
     *
     * ## Why the merge chain is here too
     *
     * An order placed by a party that has since been merged into this one is
     * still this customer's commitment, for the same reason the receivable
     * query walks the chain: the obligation followed the customer, not the
     * record.
     *
     * ## Foreign-currency orders are translated, and a missing rate is NOT
     * silently dropped
     *
     * Exposure is a figure in the entity's functional currency. An order in
     * another one is translated at its own order date, and where no rate is
     * on file the order's nominal amount is counted instead - which
     * overstates or understates by the drift since, and is the right way to
     * be wrong: dropping it reports a customer with 400,000 of orders as
     * having none, and the credit check then approves work the business
     * cannot afford to deliver.
     */
    private function uninvoicedOrders(string $entityId, string $partyId): string
    {
        $schema = DB::connection('tenant')->getSchemaBuilder();

        if (! $schema->hasTable('sales_orders') || ! $schema->hasTable('sales_order_lines')) {
            return '0.0000';
        }

        $identities = $this->merges->identities($partyId);
        $placeholders = implode(', ', array_fill(0, count($identities), 'cast(? AS char(26))'));

        /** @var list<object{currency: string, total: string, order_date: string}> $rows */
        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT o.currency, max(o.order_date) AS order_date,
                   coalesce(sum(l.net_amount - l.invoiced_net), 0) AS total
            FROM sales_orders o
            JOIN sales_order_lines l ON l.sales_order_id = o.id
            WHERE o.entity_id = cast(? AS char(26))
              AND o.party_id IN ({$placeholders})
              AND o.status = 'confirmed'
            GROUP BY o.currency
        SQL, [$entityId, ...$identities]);

        $functional = (string) DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->value('functional_currency');

        $total = Money::zero($functional === '' ? 'XXX' : $functional);

        foreach ($rows as $row) {
            $amount = Money::of((string) $row->total, (string) $row->currency);

            if ($amount->isZero()) {
                continue;
            }

            if ((string) $row->currency === $functional) {
                $total = $total->plus($amount);

                continue;
            }

            try {
                $translated = $this->rates->to(
                    $amount,
                    $functional,
                    Carbon::parse((string) $row->order_date),
                );

                $total = $total->plus($translated ?? $amount);
            } catch (Throwable) {
                // No rate on file. Counted at its nominal amount rather than
                // dropped - see the note above.
                $total = $total->plus(Money::of((string) $row->total, $functional));
            }
        }

        return (string) $total->amount;
    }

    private function disputedAmount(string $entityId, string $partyId): string
    {
        // The chain again: a dispute raised against the merged party is
        // still a dispute about money this customer owes.
        $total = DB::connection('tenant')->table('document_disputes')
            ->where('entity_id', $entityId)
            ->whereIn('party_id', $this->merges->identities($partyId))
            ->where('status', 'open')
            ->sum('disputed_amount');

        return (string) Money::of((string) ($total ?: '0'), 'XXX')->amount;
    }

    /**
     * @return array{expires_at: string, up_to_amount: ?string, justification: string}|null
     */
    private function activeOverride(string $entityId, string $partyId): ?array
    {
        $row = DB::connection('tenant')->table('credit_overrides')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('granted_at')
            ->first();

        return $row === null ? null : [
            'expires_at' => (string) $row->expires_at,
            'up_to_amount' => $row->up_to_amount === null ? null : (string) $row->up_to_amount,
            'justification' => (string) $row->justification,
        ];
    }

    /**
     * The limit an override raises the ceiling to.
     *
     * An override with no amount lifts the limit entirely for its window;
     * one with an amount raises the ceiling TO that amount rather than BY
     * it. Raising by it was the first reading and it is wrong in the
     * dangerous direction: "approve up to 50,000" would become "approve
     * 50,000 on top of whatever they already owe".
     *
     * @param  array{expires_at: string, up_to_amount: ?string, justification: string}|null  $override
     */
    private function effectiveCeiling(Money $limit, ?array $override, string $currency): Money
    {
        if ($override === null) {
            return $limit;
        }

        if ($override['up_to_amount'] === null) {
            // No amount: the limit does not bind for this window. Expressed
            // as an absurdly high ceiling rather than by skipping the check,
            // so the comparison and its message stay in one place.
            return Money::of('999999999999.0000', $currency);
        }

        $raised = Money::of($override['up_to_amount'], $currency);

        return $raised->isGreaterThan($limit) ? $raised : $limit;
    }

    /**
     * @throws CreditRefused
     */
    private function refuse(string $entityId, Party $party, string $action, string $message): never
    {
        /*
         | Recorded out of band, because refusing rolls back.
         |
         | A refusal that disappears with the transaction it caused is the
         | one record a dispute actually wants: "we were never told" is
         | answered by the refusal, not by its absence.
         */
        $this->audit->refusal(
            category: 'credit',
            action: $action.'.refused',
            subjectType: Party::class,
            subjectId: (string) $party->getKey(),
            subjectLabel: (string) $party->legal_name,
            reason: $message,
            entityId: $entityId,
            partyId: (string) $party->getKey(),
        );

        throw CreditRefused::because($message);
    }
}
