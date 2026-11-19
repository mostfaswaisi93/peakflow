<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Services\BankBalances;
use App\Tenant\Banking\Services\Reconciliations;
use App\Tenant\Banking\Services\Splits;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Export\ReportExport;
use App\Tenant\Reporting\Registers\ReportParameters;
use App\Tenant\Settlement\Services\TransitClearing;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reporting on what Banking already recorded - PF-191.
 *
 * ## Nothing here decides whether a line is reconciled
 *
 * That is the one rule this file is written around. `Banking` owns
 * reconciliation, suspense, statement lines and matching, and a report
 * that re-derived any of them would be a second answer to a question
 * that already has one - which is the failure mode PF-191 exists inside
 * of, because the two answers are both used to sign off a bank account.
 *
 * So:
 *
 * - The reconciliation statement is {@see Reconciliations::statementFor()}'s
 *   frozen document, read back and never recomputed.
 * - Suspense aging is {@see Suspense::ageing()}'s buckets, per entity.
 * - In-transit aging is {@see TransitClearing::aged()}'s items, bucketed
 *   the same way. This report is that method's first production caller.
 * - Whether a statement line is explained is
 *   {@see Splits::remainder()} - the one definition of what is left of a
 *   line after every live match on it. A partly applied line is partly
 *   unexplained, which no boolean "matched" flag can express.
 *
 * What is added here is what a report adds and a service does not: the
 * parameters on the output, declared columns, both languages, the
 * cross-entity view, and a declaration that states the one thing a
 * reader must not miss.
 *
 * ## The frozen document is the whole of the first criterion
 *
 * "Reproducible as it stood" is not "runs again". A reconciliation
 * re-derived from today's ledger silently restates itself the moment a
 * prior-period entry is posted, and then it is evidence of nothing. The
 * figures on this statement are the stored ones, and a check posts into
 * a reconciled period afterwards and asserts they do not move - while
 * the live balance does.
 *
 * ## Aged from the bank's own date
 *
 * Every age on every table here is measured from `booking_date` or from
 * the run's `pay_on`: the day the money moved, not the day we touched the
 * record. Measuring from our own timestamps resets an item's age every
 * time somebody makes partial progress on it, so the oldest and worst
 * items report as the newest - which {@see Suspense} already says, and
 * this report follows rather than re-deciding.
 *
 * ## One currency per total, named, with what was left out counted
 *
 * The cross-entity suspense table adds entities together, and two
 * entities need not keep their books in the same currency. A total that
 * added SAR to USD would print to four decimal places and mean nothing,
 * so the report takes one currency, includes the entities whose
 * functional currency is that one, and says how many it left out and
 * which. Converting them at today's rate would be inventing a figure
 * nobody could reproduce.
 */
final class BankReconciliationReport
{
    /**
     * The buckets, fixed rather than configurable - as {@see Suspense} is.
     *
     * `aging.buckets` is a receivables setting and it is right that it is
     * one: where the buckets divide is a function of the terms a business
     * sells on. None of the three tables here is about terms. An
     * unexplained bank line, a payment the bank has not shown and a
     * suspense item are all "how long has this been like this", and the
     * answer has to read the same on all three pages and across every
     * entity in the tenant - a suspense table whose buckets differed
     * between two entities could not be added up at all, which is the
     * reason `Suspense` fixed its own.
     */
    private const BUCKETS = [30, 60, 90];

    public function __construct(
        private readonly Reconciliations $reconciliations,
        private readonly Suspense $suspense,
        private readonly Splits $splits,
        private readonly TransitClearing $transit,
        private readonly BankBalances $balances,
    ) {}

    /**
     * A past reconciliation, exactly as it was authorised - PF-191.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function statement(string $reconciliationId, ?Carbon $ranAt = null): array
    {
        try {
            /*
             | Banking's own accessor, which reads the frozen row.
             |
             | It also refuses to present a document whose displayed items
             | do not bridge its displayed balances. That refusal is worth
             | inheriting rather than catching: a reconciliation whose
             | lines do not add to the balance beside them is the one
             | error a reader cannot catch, because the page looks right.
             */
            $document = $this->reconciliations->statementFor($reconciliationId);
        } catch (MatchRefused $e) {
            throw ReportRefused::because($e->getMessage());
        }

        $currency = (string) $document['currency'];
        $account = BankAccount::query()->find((string) $document['bank_account_id']);

        $parameters = ReportParameters::for(
            report: 'bank_reconciliation_statement',
            entityId: (string) $document['entity_id'],
            from: Carbon::parse((string) $document['period']['from']),
            until: Carbon::parse((string) $document['period']['to']),
            currency: $currency,
            filters: [
                'reconciliation' => $reconciliationId,
                'bank account' => $account === null
                    ? (string) $document['bank_account_id']
                    : (string) $account->label,
            ],
            runAt: $ranAt,
        );

        /** @var array<string, Money> $items */
        $items = $document['items'];

        $rows = [
            $this->bridgeRow(
                'statement_balance',
                'رصيد كشف البنك',
                'Balance per the bank statement',
                $document['statement_balance'],
            ),
        ];

        foreach ($items as $name => $amount) {
            $rows[] = $this->bridgeRow(
                str_replace(' ', '_', (string) $name),
                $this->itemLabelAr((string) $name),
                ucfirst((string) $name),
                $amount,
            );
        }

        $rows[] = $this->bridgeRow(
            'ledger_balance',
            'رصيد الدفتر',
            'Balance per the general ledger',
            $document['ledger_balance'],
        );

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->bridgeColumns(),
            'rows' => $rows,
            'reconciliation_id' => $reconciliationId,
            'entity_id' => (string) $document['entity_id'],
            'bank_account_id' => (string) $document['bank_account_id'],
            'bank_account' => $account === null ? null : (string) $account->label,
            'bank_statement_id' => (string) $document['bank_statement_id'],
            'period' => $document['period'],
            'currency' => $currency,
            /*
             | As of the moment it was AUTHORISED, not as of now.
             |
             | The basis of every figure on this page is the day somebody
             | signed it, and a page that printed today's date beside
             | frozen figures would be read as today's position.
             */
            'as_of' => (string) $document['authorised_at'],
            'statement_balance' => (string) $document['statement_balance']->amount,
            'ledger_balance' => (string) $document['ledger_balance']->amount,
            'matched_lines' => (int) $document['matched_lines'],
            'unexplained_lines' => (int) $document['unexplained_lines'],
            'authorised' => $this->authoriser(
                (string) $document['authorised_by'],
                (string) $document['authorised_at'],
            ),
            'standing' => $document['undone_at'] === null,
            'undone_at' => $document['undone_at'],
            'why' => (string) $document['why'],
            'declaration' => $this->declareTheStatement($document, $currency),
        ];
    }

    /**
     * Unexplained money across the tenant, by bucket and by entity - PF-191.
     *
     * Two positions, never added together. Suspense is money the bank has
     * that nobody can explain; in transit is money we have told the bank
     * to send that it has not shown yet. Both are "not reconciled", they
     * sit in different control accounts, and one number covering both
     * would be a figure with no account behind it.
     *
     * @param  list<string>|null  $entityIds  null reads every active entity
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function suspenseAging(
        string $currency,
        ?array $entityIds = null,
        ?Carbon $asOf = null,
        ?Carbon $ranAt = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        $entities = Entity::query()
            ->when($entityIds !== null, static fn ($query) => $query->whereIn('id', $entityIds ?? []))
            ->orderBy('code')
            ->get();

        if ($entities->isEmpty()) {
            throw ReportRefused::because(
                'No entity to report on. An empty suspense report reads as "nothing is unexplained", '.
                'which is the answer somebody acts on - so naming nobody is refused rather than '.
                'returned as a page of zeros.',
            );
        }

        $included = [];
        $excluded = [];

        foreach ($entities as $entity) {
            if ((string) $entity->functional_currency === $currency) {
                $included[] = $entity;

                continue;
            }

            /*
             | Counted out and named, never converted.
             |
             | An entity keeping its books in USD has a suspense balance
             | in USD, and translating it at today's rate to add it into a
             | SAR total invents a figure that cannot be reproduced from a
             | rate and a date. Naming it sends somebody to that entity's
             | own report, which is the only place the figure is true.
             */
            $excluded[] = [
                'entity_id' => (string) $entity->getKey(),
                'entity' => (string) $entity->code,
                'currency' => (string) $entity->functional_currency,
            ];
        }

        $rows = [];
        $byEntity = [];
        $buckets = $this->emptyBuckets($currency);
        $counts = $this->emptyCounts();
        $total = Money::zero($currency);
        $signed = Money::zero($currency);
        $oldest = 0;
        $disagreements = [];

        foreach ($included as $entity) {
            $entityId = (string) $entity->getKey();
            $ageing = $this->suspense->ageing($entityId, $currency, $day->toDateString());

            /** @var Money $entityTotal */
            $entityTotal = $ageing['total'];
            /** @var Money $entitySigned */
            $entitySigned = $ageing['signed'];

            $total = $total->plus($entityTotal);
            $signed = $signed->plus($entitySigned);
            $oldest = max($oldest, (int) $ageing['oldest_days']);

            /*
             | Each entity's own list against its own control account.
             |
             | `Suspense::reconciles()` is the assertion `ledger:verify`
             | makes, and it is asked here per entity rather than once
             | over a total: a tenant whose two entities are wrong by
             | equal and opposite amounts reconciles perfectly in
             | aggregate and in neither set of books.
             */
            $reconciles = $this->suspense->reconciles($entityId, $currency);

            if (($reconciles['agrees'] ?? false) !== true) {
                $disagreements[] = sprintf('%s (%s)', (string) $entity->code, (string) $reconciles['why']);
            }

            /*
             | Re-bucketed from the ITEMS, not from PF-163's own bucket
             | set - the same decision, for the same reason, as the
             | receivables report makes with unapplied cash.
             |
             | That set begins at "0-30" and an aging report's first
             | bucket is "current": a line the bank booked this morning
             | and somebody parked this afternoon is not thirty days of
             | anything, and merging the two grids would put today's
             | unexplained money in the same column as last month's. What
             | is NOT recomputed is anything that could disagree with the
             | service - the age of each item, the total, the signed
             | balance and whether the control account accounts for them
             | are all `Suspense`'s own figures, and only the mapping from
             | an age to a column is this report's.
             */
            /** @var list<array<string, mixed>> $items */
            $items = $ageing['items'];

            $entityBuckets = $this->emptyBuckets($currency);
            $entityCounts = $this->emptyCounts();

            foreach ($items as $item) {
                $bucket = $this->bucketFor((int) $item['age_days']);
                $amount = Money::of((string) $item['amount'], $currency)->abs();

                $entityBuckets[$bucket] = $entityBuckets[$bucket]->plus($amount);
                $entityCounts[$bucket]++;
                $buckets[$bucket] = $buckets[$bucket]->plus($amount);
                $counts[$bucket]++;

                $rows[] = [
                    'entity_id' => $entityId,
                    'entity' => (string) $entity->code,
                    'position' => 'suspense',
                    'reference' => (string) ($item['bank_reference'] ?? $item['match_id']),
                    'counterparty' => $item['counterparty_name'] === null
                        ? null
                        : (string) $item['counterparty_name'],
                    'dated' => (string) $item['booking_date'],
                    'age_days' => (int) $item['age_days'],
                    'bucket' => $bucket,
                    'currency' => $currency,
                    'amount' => (string) $item['amount'],
                    'note' => (string) $item['reason'],
                ];
            }

            $byEntity[] = [
                'entity_id' => $entityId,
                'entity' => (string) $entity->code,
                'currency' => $currency,
                'buckets' => $this->flatten($entityBuckets),
                'counts' => $entityCounts,
                'total' => (string) $entityTotal->amount,
                'signed' => (string) $entitySigned->amount,
                'oldest_days' => (int) $ageing['oldest_days'],
                'control_account_agrees' => (bool) ($reconciles['agrees'] ?? false),
                'why' => (string) $ageing['why'],
            ];
        }

        $inTransit = $this->inTransitAging($currency, $included, $day);

        foreach ($inTransit['rows'] as $row) {
            $rows[] = $row;
        }

        $parameters = ReportParameters::for(
            report: 'suspense_and_transit_aging',
            entityId: $included === [] ? '' : (string) $included[0]->getKey(),
            from: $day,
            until: $day,
            currency: $currency,
            filters: [
                'entities' => implode(', ', array_map(
                    static fn (Entity $entity): string => (string) $entity->code,
                    $included,
                )),
                'buckets' => implode('/', self::BUCKETS),
            ],
            runAt: $ranAt,
        );

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->agingColumns(),
            'rows' => $rows,
            'as_of' => $day->toDateString(),
            'currency' => $currency,
            'bucket_names' => array_keys($this->emptyBuckets($currency)),
            'suspense' => [
                'buckets' => $this->flatten($buckets),
                'counts' => $counts,
                'total' => (string) $total->amount,
                'signed' => (string) $signed->amount,
                'oldest_days' => $oldest,
                'by_entity' => $byEntity,
                'why' => 'The magnitude of unexplained money and the balance the control account '.
                    'should hold are two figures: 450 in and 120 out is 570 somebody has to look at '.
                    'and 330 in the account. Both are here because both get asked for.',
            ],
            'in_transit' => $inTransit['stated'],
            'entities_excluded' => $excluded,
            'declaration' => $this->declareTheAging(
                $total,
                $oldest,
                $inTransit['stated'],
                $disagreements,
                $excluded,
                $currency,
            ),
        ];
    }

    /**
     * Statement lines nobody has explained, by age, per bank account - PF-191.
     *
     * The leading indicator the criterion names. A reconciliation that is
     * drifting does not announce itself with an error: the difference is
     * explained every month by one more unexplained line than last month,
     * and the oldest of them is the figure that says so.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function unreconciledAging(
        string $entityId,
        ?string $bankAccountId = null,
        ?Carbon $asOf = null,
        ?Carbon $ranAt = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        $accounts = BankAccount::query()
            ->where('entity_id', $entityId)
            ->when($bankAccountId !== null, static fn ($query) => $query->whereKey($bankAccountId))
            ->orderBy('label')
            ->get();

        if ($bankAccountId !== null && $accounts->isEmpty()) {
            throw ReportRefused::because(
                'This entity has no bank account with that id. An unknown account returns an empty '.
                'table, which reads as an account with nothing outstanding on it rather than as a '.
                'filter that found nobody.',
            );
        }

        $rows = [];
        $perAccount = [];
        $oldestOverall = 0;

        foreach ($accounts as $account) {
            $currency = (string) $account->currency;
            $buckets = $this->emptyBuckets($currency);
            $counts = $this->emptyCounts();
            $total = Money::zero($currency);
            $oldest = 0;
            $lines = 0;

            /*
             | The candidate lines, and nothing about whether they match.
             |
             | Provisional lines are out because an intraday report is the
             | same movement the booked statement will carry, and a
             | superseded one has been replaced - both are `BankBalances`'
             | own exclusions, and counting either would report every
             | intraday line as an unexplained item.
             */
            $transactions = BankTransaction::query()
                ->where('bank_account_id', (string) $account->getKey())
                ->where('booking_date', '<=', $day->toDateString())
                ->where('is_provisional', false)
                ->whereNull('superseded_by_id')
                ->orderBy('booking_date')
                ->orderBy('statement_sequence')
                ->get();

            foreach ($transactions as $transaction) {
                /*
                 | Banking's definition of what is left to explain.
                 |
                 | Not a `NOT EXISTS (SELECT 1 FROM matches)`: a line of
                 | 1,000 with 400 applied to an invoice is 600 of
                 | unexplained money, and a boolean cannot say so. The
                 | subtraction also already knows that a reversed match
                 | explains nothing.
                 */
                $remainder = $this->splits->remainder($transaction);

                if ($remainder->isZero()) {
                    continue;
                }

                $age = (int) Carbon::parse($transaction->booking_date->toDateString())
                    ->diffInDays($day, absolute: false);
                $bucket = $this->bucketFor($age);

                $buckets[$bucket] = $buckets[$bucket]->plus($remainder);
                $counts[$bucket]++;
                $total = $total->plus($remainder);
                $oldest = max($oldest, $age);
                $lines++;

                $rows[] = [
                    'bank_account_id' => (string) $account->getKey(),
                    'bank_account' => (string) $account->label,
                    'reference' => (string) ($transaction->bank_reference ?? $transaction->getKey()),
                    'counterparty' => $transaction->counterparty_name === null
                        ? null
                        : (string) $transaction->counterparty_name,
                    'dated' => $transaction->booking_date->toDateString(),
                    'age_days' => $age,
                    'bucket' => $bucket,
                    'currency' => $currency,
                    'line_amount' => (string) $transaction->money()->amount,
                    'unexplained' => (string) $remainder->amount,
                ];
            }

            $oldestOverall = max($oldestOverall, $oldest);

            /*
             | The baseline statement, so the oldest age can be read.
             |
             | An unexplained line INSIDE the last booked statement's
             | period is one the reconciliation has to account for; one
             | after it is an ordinary timing item that the next statement
             | will carry. Printing the baseline is what lets a reader
             | tell the two apart without doing the subtraction.
             |
             | `BankBalances::baseline()` and not the model's own
             | `lastStatement()`: the model returns the newest statement
             | of any kind, and an intraday CAMT.052 arriving this
             | afternoon would move this line forward without the bank
             | having closed anything.
             */
            $baseline = $this->balances->baseline($account, $day);

            $perAccount[] = [
                'bank_account_id' => (string) $account->getKey(),
                'bank_account' => (string) $account->label,
                'currency' => $currency,
                'buckets' => $this->flatten($buckets),
                'counts' => $counts,
                'total' => (string) $total->amount,
                'lines' => $lines,
                'oldest_days' => $oldest,
                'statement_period_to' => $baseline === null
                    ? null
                    : $baseline->period_to->toDateString(),
                'why' => $lines === 0
                    ? 'Every line on this account up to this date is fully applied to something.'
                    : sprintf(
                        '%d line%s with %s unexplained between them, the oldest %d days old. A line '.
                        'the bank booked that nothing here explains is an unrecorded bank item, so '.
                        'this account cannot reconcile until each of them is matched, split, or '.
                        'parked in suspense.',
                        $lines,
                        $lines === 1 ? '' : 's',
                        (string) $total->amount,
                        $oldest,
                    ),
            ];
        }

        $parameters = ReportParameters::for(
            report: 'unreconciled_item_aging',
            entityId: $entityId,
            from: $day,
            until: $day,
            currency: $accounts->isEmpty() ? '' : (string) $accounts->first()?->currency,
            filters: [
                'bank account' => $bankAccountId === null
                    ? null
                    : (string) $accounts->first()?->label,
                'buckets' => implode('/', self::BUCKETS),
            ],
            runAt: $ranAt,
        );

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->unreconciledColumns(),
            'rows' => $rows,
            'as_of' => $day->toDateString(),
            'bucket_names' => array_keys($this->emptyBuckets('SAR')),
            'accounts' => $perAccount,
            'oldest_days' => $oldestOverall,
            'declaration' => $this->declareTheUnreconciled($perAccount, $oldestOverall),
        ];
    }

    /**
     * Every reconciliation of one account, with who authorised it - PF-191.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function history(
        string $entityId,
        string $bankAccountId,
        ?Carbon $asOf = null,
        ?Carbon $ranAt = null,
    ): array {
        $day = ($asOf ?? Carbon::now())->copy()->endOfDay();

        $account = BankAccount::query()
            ->where('entity_id', $entityId)
            ->whereKey($bankAccountId)
            ->first();

        if ($account === null) {
            throw ReportRefused::because(
                'This entity has no bank account with that id. An empty history reads as an account '.
                'nobody has ever reconciled, which is a finding - so it is refused rather than '.
                'returned.',
            );
        }

        /*
         | The listing is the report's; every FIGURE is Banking's.
         |
         | Which reconciliations exist and in what order is a question
         | about rows, and listing them is what a report does. What each
         | one SAYS comes back through `statementFor()`, so the balances
         | on this page are the frozen ones and each row has been held to
         | the identity on the way out. A loop that read the columns here
         | would be a second presentation of a document that already has
         | one, and the two would disagree the first time a sign changed.
         */
        $ids = DB::connection('tenant')->table('reconciliations')
            ->where('entity_id', $entityId)
            ->where('bank_account_id', $bankAccountId)
            ->where('authorised_at', '<=', $day->toDateTimeString())
            ->orderBy('period_to')
            ->orderBy('authorised_at')
            ->pluck('id');

        $rows = [];
        $unpresentable = [];
        $standing = 0;
        $undone = 0;
        $selfAuthorised = [];
        $latest = null;

        foreach ($ids as $id) {
            try {
                $document = $this->reconciliations->statementFor((string) $id);
            } catch (MatchRefused $e) {
                /*
                 | A document that cannot be presented is named, and the
                 | declaration says so - the rest of the history stays
                 | readable.
                 |
                 | Letting the refusal out would make one broken row hide
                 | eleven good ones, and a history nobody can open is a
                 | history nobody checks. The row is not quietly dropped
                 | either: a missing reconciliation in a list of them
                 | reads as a month nobody reconciled, which is a
                 | different and worse claim.
                 */
                $unpresentable[] = sprintf('%s: %s', (string) $id, $e->getMessage());

                $rows[] = [
                    'reconciliation_id' => (string) $id,
                    'period_from' => null,
                    'period_to' => null,
                    'statement_balance' => null,
                    'ledger_balance' => null,
                    'matched_lines' => null,
                    'unexplained_lines' => null,
                    'authorised_by' => null,
                    'authorised_at' => null,
                    'standing' => null,
                    'note' => 'This reconciliation cannot be presented: '.$e->getMessage(),
                ];

                continue;
            }

            $isStanding = $document['undone_at'] === null;
            $isStanding ? $standing++ : $undone++;

            $authoriser = $this->authoriser(
                (string) $document['authorised_by'],
                (string) $document['authorised_at'],
            );

            if ($isStanding) {
                $latest = $document;
            }

            /*
             | Who authorised it, and whether anybody else was involved.
             |
             | A bank reconciliation signed by the person who imported the
             | statement and applied the matches is a control that has not
             | operated. The history is the only place that is visible,
             | because each document on its own looks perfectly ordinary.
             */
            $applier = $this->whoAppliedMatchesIn(
                $bankAccountId,
                (string) $document['period']['from'],
                (string) $document['period']['to'],
            );

            if ($applier !== [] && $applier === [(string) $document['authorised_by']]) {
                $selfAuthorised[] = sprintf(
                    '%s to %s',
                    (string) $document['period']['from'],
                    (string) $document['period']['to'],
                );
            }

            $rows[] = [
                'reconciliation_id' => (string) $id,
                'period_from' => (string) $document['period']['from'],
                'period_to' => (string) $document['period']['to'],
                'statement_balance' => (string) $document['statement_balance']->amount,
                'ledger_balance' => (string) $document['ledger_balance']->amount,
                'matched_lines' => (int) $document['matched_lines'],
                'unexplained_lines' => (int) $document['unexplained_lines'],
                'authorised_by' => $authoriser['name'] ?? $authoriser['id'],
                'authorised_at' => (string) $document['authorised_at'],
                'standing' => $isStanding,
                'note' => $isStanding
                    ? 'Standing.'
                    : 'Withdrawn on '.(string) $document['undone_at'].'.',
            ];
        }

        /*
         | The window opens at the FIRST reconciliation, not at today.
         |
         | A history's parameters have to say what it covers, and
         | "from today to today" on a page listing eleven months would
         | be read as a page that lists one.
         */
        $earliest = null;

        foreach ($rows as $row) {
            if ($row['period_from'] !== null) {
                $earliest = (string) $row['period_from'];

                break;
            }
        }

        $parameters = ReportParameters::for(
            report: 'bank_reconciliation_history',
            entityId: $entityId,
            from: $earliest === null ? $day : Carbon::parse($earliest),
            until: $day,
            currency: (string) $account->currency,
            filters: ['bank account' => (string) $account->label],
            runAt: $ranAt,
        );

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->historyColumns(),
            'rows' => $rows,
            'bank_account_id' => $bankAccountId,
            'bank_account' => (string) $account->label,
            'currency' => (string) $account->currency,
            'standing' => $standing,
            'withdrawn' => $undone,
            'last_reconciled_to' => $latest === null ? null : (string) $latest['period']['to'],
            'days_since' => $latest === null
                ? null
                : (int) Carbon::parse((string) $latest['period']['to'])->diffInDays($day, absolute: false),
            'unpresentable' => $unpresentable,
            'self_authorised' => $selfAuthorised,
            'declaration' => $this->declareTheHistory(
                $standing,
                $undone,
                $latest,
                $unpresentable,
                $selfAuthorised,
                $day,
            ),
        ];
    }

    /**
     * Each of the four as a CSV, through the one export path - PF-191.
     *
     * Thin on purpose. The export takes the parameter block, the declared
     * columns and the rows keyed by those columns, which is the shape
     * every method above already returns - so there is nothing to
     * transform here and nothing that can disagree with the page. A
     * second renderer per report is how an export comes to show different
     * figures from the screen it was taken from.
     *
     * `$ranAt` is threaded rather than read from the clock, so the same
     * question asked twice over a closed period produces the same bytes -
     * which is PF-198's guarantee and is the whole reason a printed
     * report can be answered six months later.
     *
     * @throws ReportRefused
     */
    public function csvStatement(
        string $reconciliationId,
        string $locale = 'ar',
        ?Carbon $ranAt = null,
    ): string {
        return $this->export($this->statement($reconciliationId, $ranAt), $locale);
    }

    /**
     * @param  list<string>|null  $entityIds
     *
     * @throws ReportRefused
     */
    public function csvSuspenseAging(
        string $currency,
        ?array $entityIds = null,
        ?Carbon $asOf = null,
        string $locale = 'ar',
        ?Carbon $ranAt = null,
    ): string {
        return $this->export($this->suspenseAging($currency, $entityIds, $asOf, $ranAt), $locale);
    }

    /** @throws ReportRefused */
    public function csvUnreconciledAging(
        string $entityId,
        ?string $bankAccountId = null,
        ?Carbon $asOf = null,
        string $locale = 'ar',
        ?Carbon $ranAt = null,
    ): string {
        return $this->export(
            $this->unreconciledAging($entityId, $bankAccountId, $asOf, $ranAt),
            $locale,
        );
    }

    /** @throws ReportRefused */
    public function csvHistory(
        string $entityId,
        string $bankAccountId,
        ?Carbon $asOf = null,
        string $locale = 'ar',
        ?Carbon $ranAt = null,
    ): string {
        return $this->export($this->history($entityId, $bankAccountId, $asOf, $ranAt), $locale);
    }

    /** @param array<string, mixed> $report */
    private function export(array $report, string $locale): string
    {
        return app(ReportExport::class)->csv(
            parameterLines: $report['parameter_lines'],
            columns: $report['columns'],
            rows: $report['rows'],
            locale: $locale,
        );
    }

    /**
     * In-transit items, bucketed the same way as suspense.
     *
     * @param  list<Entity>  $entities
     * @return array{rows: list<array<string, mixed>>, stated: array<string, mixed>}
     */
    private function inTransitAging(string $currency, array $entities, Carbon $day): array
    {
        $buckets = $this->emptyBuckets($currency);
        $counts = $this->emptyCounts();
        $total = Money::zero($currency);
        $oldest = 0;
        $rows = [];
        $items = [];
        $otherCurrency = 0;

        foreach ($entities as $entity) {
            $entityId = (string) $entity->getKey();

            /*
             | Every open in-transit item, not only the aged ones.
             |
             | PF-179's own tolerance is five days, which is the right
             | default for "what should somebody chase". An aging table
             | needs the current bucket too, or it shows a position whose
             | newest items are missing - so the floor is dropped here and
             | the five-day line is reported as the bucket boundary it is.
             */
            foreach ($this->transit->aged($entityId, null, $day) as $item) {
                /** @var Money $amount */
                $amount = $item['amount'];

                if ((string) $amount->currency !== $currency) {
                    $otherCurrency++;

                    continue;
                }

                $age = (int) $item['age_in_days'];
                $bucket = $this->bucketFor($age);

                $buckets[$bucket] = $buckets[$bucket]->plus($amount);
                $counts[$bucket]++;
                $total = $total->plus($amount);
                $oldest = max($oldest, $age);

                $items[] = [
                    'entity' => (string) $entity->code,
                    'instruction_id' => (string) $item['instruction_id'],
                    'run' => (string) $item['run'],
                    'party' => (string) $item['party'],
                    'pay_on' => (string) $item['pay_on'],
                    'age_days' => $age,
                    'bucket' => $bucket,
                    'amount' => (string) $amount->amount,
                    'bank_status' => $item['bank_status'],
                    'why' => (string) $item['why'],
                ];

                $rows[] = [
                    'entity_id' => $entityId,
                    'entity' => (string) $entity->code,
                    'position' => 'in_transit',
                    'reference' => (string) $item['run'],
                    'counterparty' => (string) $item['party'],
                    'dated' => (string) $item['pay_on'],
                    'age_days' => $age,
                    'bucket' => $bucket,
                    'currency' => $currency,
                    'amount' => (string) $amount->amount,
                    'note' => (string) $item['why'],
                ];
            }
        }

        $stated = [
            'currency' => $currency,
            'buckets' => $this->flatten($buckets),
            'counts' => $counts,
            'total' => (string) $total->amount,
            'oldest_days' => $oldest,
            'items' => $items,
            'why' => $items === []
                ? 'Nothing is in transit: every released instruction has either been confirmed by a '.
                    'statement or is not yet payable.'
                : sprintf(
                    '%s released and not yet shown by the bank, the oldest %d days old. This is not '.
                    'added to suspense above: in transit is money we have told the bank to send and '.
                    'suspense is money the bank has that nobody can explain, and they sit in '.
                    'different control accounts.',
                    (string) $total->amount,
                    $oldest,
                ),
        ];

        if ($otherCurrency > 0) {
            /*
             | Stated only when it is not zero, like the DSO window.
             |
             | A reader who sees no such line can take the total as
             | covering everything in transit. A silent exclusion is the
             | one that gets quoted.
             */
            $stated['excluded_other_currency'] = $otherCurrency;
        }

        return ['rows' => $rows, 'stated' => $stated];
    }

    /**
     * Who applied the matches in a period, if it was one person.
     *
     * @return list<string>
     */
    private function whoAppliedMatchesIn(string $bankAccountId, string $from, string $until): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT DISTINCT m.applied_by
            FROM matches m
            JOIN bank_transactions t
              ON t.id = m.bank_transaction_id
             AND t.booking_date = m.booking_date
            WHERE t.bank_account_id = ?
              AND m.reversed_at IS NULL
              AND m.applied_by IS NOT NULL
              AND m.booking_date BETWEEN cast(? AS date) AND cast(? AS date)
        SQL, [$bankAccountId, $from, $until]);

        return array_map(static fn (object $row): string => (string) $row->applied_by, $rows);
    }

    /**
     * The person who signed it, named, or the id when no row answers to it.
     *
     * @return array<string, mixed>
     */
    private function authoriser(string $userId, string $at): array
    {
        $user = DB::connection('tenant')->table('users')
            ->where('id', $userId)
            ->first(['id', 'name', 'active']);

        if ($user === null) {
            /*
             | A user id with no user is reported, never blanked.
             |
             | The signature is still theirs, and a blank would present an
             | authorised reconciliation as one nobody signed - which is
             | the opposite of what the row says. The id is kept because
             | it is what an investigation follows.
             */
            return [
                'id' => $userId,
                'name' => null,
                'at' => $at,
                'why' => 'The reconciliation names this user id and no user row answers to it - '.
                    'usually somebody since removed. The authorisation is still theirs.',
            ];
        }

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'active' => (bool) $user->active,
            'at' => $at,
            'why' => null,
        ];
    }

    /** @return array<string, Money> */
    private function emptyBuckets(string $currency): array
    {
        $buckets = ['current' => Money::zero($currency)];
        $previous = 0;

        foreach (self::BUCKETS as $boundary) {
            $buckets[sprintf('%d-%d', $previous + 1, $boundary)] = Money::zero($currency);
            $previous = $boundary;
        }

        $buckets[sprintf('%d+', $previous)] = Money::zero($currency);

        return $buckets;
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return array_map(static fn (): int => 0, $this->emptyBuckets('SAR'));
    }

    private function bucketFor(int $age): string
    {
        if ($age <= 0) {
            return 'current';
        }

        $previous = 0;

        foreach (self::BUCKETS as $boundary) {
            if ($age <= $boundary) {
                return sprintf('%d-%d', $previous + 1, $boundary);
            }

            $previous = $boundary;
        }

        return sprintf('%d+', $previous);
    }

    /**
     * @param  array<string, Money>  $buckets
     * @return array<string, string>
     */
    private function flatten(array $buckets): array
    {
        return array_map(static fn (Money $amount): string => (string) $amount->amount, $buckets);
    }

    /**
     * One line of the bridge, in both languages.
     *
     * The item's NAME is data here rather than a column header, which is
     * the one place this report differs in shape from a register: the
     * rows of a reconciliation statement are its named items, so the
     * label travels in the row and the two languages are two columns.
     *
     * @return array{key: string, item_ar: string, item_en: string, value: string}
     */
    private function bridgeRow(string $key, string $ar, string $en, Money $amount): array
    {
        return [
            'key' => $key,
            'item_ar' => $ar,
            'item_en' => $en,
            'value' => (string) $amount->amount,
        ];
    }

    private function itemLabelAr(string $name): string
    {
        return match ($name) {
            'unpresented payments' => 'مدفوعات لم تُقدَّم للبنك',
            'unsettled receipts' => 'مقبوضات لم تُقيَّد بعد',
            'unrecorded bank items' => 'حركات بنكية غير مسجلة',
            'unposted opening balance' => 'رصيد افتتاحي غير مُرحَّل',
            default => $name,
        };
    }

    /**
     * The columns, declared rather than inferred from the first row.
     *
     * A report whose columns come from its data has no columns at all
     * when it has no data, and the export of a quiet month arrives as a
     * file with no header - which reads as a broken export rather than as
     * a month in which nothing went wrong.
     *
     * @return list<array{key: string, label_ar: string, label_en: string}>
     */
    private function bridgeColumns(): array
    {
        return [
            ['key' => 'item_ar', 'label_ar' => 'البند', 'label_en' => 'Item in Arabic'],
            ['key' => 'item_en', 'label_ar' => 'البند بالإنجليزية', 'label_en' => 'Item'],
            ['key' => 'value', 'label_ar' => 'المبلغ', 'label_en' => 'Amount'],
        ];
    }

    /** @return list<array{key: string, label_ar: string, label_en: string}> */
    private function agingColumns(): array
    {
        return [
            ['key' => 'entity', 'label_ar' => 'الشركة', 'label_en' => 'Entity'],
            ['key' => 'position', 'label_ar' => 'الموضع', 'label_en' => 'Position'],
            ['key' => 'reference', 'label_ar' => 'المرجع', 'label_en' => 'Reference'],
            ['key' => 'counterparty', 'label_ar' => 'الطرف المقابل', 'label_en' => 'Counterparty'],
            ['key' => 'dated', 'label_ar' => 'التاريخ', 'label_en' => 'Dated'],
            ['key' => 'age_days', 'label_ar' => 'العمر بالأيام', 'label_en' => 'Age in days'],
            ['key' => 'bucket', 'label_ar' => 'الفئة', 'label_en' => 'Bucket'],
            ['key' => 'currency', 'label_ar' => 'العملة', 'label_en' => 'Currency'],
            ['key' => 'amount', 'label_ar' => 'المبلغ', 'label_en' => 'Amount'],
            ['key' => 'note', 'label_ar' => 'ملاحظة', 'label_en' => 'Note'],
        ];
    }

    /** @return list<array{key: string, label_ar: string, label_en: string}> */
    private function unreconciledColumns(): array
    {
        return [
            ['key' => 'bank_account', 'label_ar' => 'الحساب البنكي', 'label_en' => 'Bank account'],
            ['key' => 'reference', 'label_ar' => 'المرجع', 'label_en' => 'Reference'],
            ['key' => 'counterparty', 'label_ar' => 'الطرف المقابل', 'label_en' => 'Counterparty'],
            ['key' => 'dated', 'label_ar' => 'تاريخ البنك', 'label_en' => 'Bank date'],
            ['key' => 'age_days', 'label_ar' => 'العمر بالأيام', 'label_en' => 'Age in days'],
            ['key' => 'bucket', 'label_ar' => 'الفئة', 'label_en' => 'Bucket'],
            ['key' => 'currency', 'label_ar' => 'العملة', 'label_en' => 'Currency'],
            ['key' => 'line_amount', 'label_ar' => 'مبلغ الحركة', 'label_en' => 'Line amount'],
            ['key' => 'unexplained', 'label_ar' => 'غير مُفسَّر', 'label_en' => 'Unexplained'],
        ];
    }

    /** @return list<array{key: string, label_ar: string, label_en: string}> */
    private function historyColumns(): array
    {
        return [
            ['key' => 'period_from', 'label_ar' => 'من', 'label_en' => 'From'],
            ['key' => 'period_to', 'label_ar' => 'إلى', 'label_en' => 'To'],
            ['key' => 'statement_balance', 'label_ar' => 'رصيد الكشف', 'label_en' => 'Statement balance'],
            ['key' => 'ledger_balance', 'label_ar' => 'رصيد الدفتر', 'label_en' => 'Ledger balance'],
            ['key' => 'matched_lines', 'label_ar' => 'سطور مطابَقة', 'label_en' => 'Matched lines'],
            ['key' => 'unexplained_lines', 'label_ar' => 'سطور غير مُفسَّرة', 'label_en' => 'Unexplained lines'],
            ['key' => 'authorised_by', 'label_ar' => 'اعتمدها', 'label_en' => 'Authorised by'],
            ['key' => 'authorised_at', 'label_ar' => 'وقت الاعتماد', 'label_en' => 'Authorised at'],
            ['key' => 'standing', 'label_ar' => 'قائمة', 'label_en' => 'Standing'],
            ['key' => 'note', 'label_ar' => 'ملاحظة', 'label_en' => 'Note'],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, string>
     */
    private function declareTheStatement(array $document, string $currency): array
    {
        $standing = $document['undone_at'] === null;

        return [
            'severity' => $standing ? 'ok' : 'attention',
            'en' => sprintf(
                'This is the reconciliation as it was authorised on %s, not as it would be computed '.
                'today. Every figure is the stored one: posting into %s after the event does not '.
                'change this page, which is what makes it something an auditor can re-perform. '.
                '%d line%s matched and %d unexplained at the time.%s',
                (string) $document['authorised_at'],
                (string) $document['period']['to'],
                (int) $document['matched_lines'],
                (int) $document['matched_lines'] === 1 ? '' : 's',
                (int) $document['unexplained_lines'],
                $standing
                    ? ''
                    : ' IT HAS SINCE BEEN WITHDRAWN, on '.(string) $document['undone_at'].
                        ', so it is evidence of what was signed and not of the account\'s state now.',
            ),
            'ar' => sprintf(
                'هذه المطابقة كما اعتُمدت في %s بالأرقام المحفوظة، لا كما تُحسب اليوم. العملة %s.%s',
                (string) $document['authorised_at'],
                $currency,
                $standing ? '' : ' وقد سُحبت بعد ذلك.',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $inTransit
     * @param  list<string>  $disagreements
     * @param  list<array<string, string>>  $excluded
     * @return array<string, string>
     */
    private function declareTheAging(
        Money $total,
        int $oldest,
        array $inTransit,
        array $disagreements,
        array $excluded,
        string $currency,
    ): array {
        $left = $excluded === []
            ? ''
            : sprintf(
                ' %d entit%s left out because they keep their books in another currency (%s): their '.
                'suspense is real and is not in this total, because translating it at today\'s rate '.
                'would invent a figure nobody could reproduce.',
                \count($excluded),
                \count($excluded) === 1 ? 'y is' : 'ies are',
                implode(', ', array_map(
                    static fn (array $entity): string => $entity['entity'].' in '.$entity['currency'],
                    $excluded,
                )),
            );

        if ($disagreements !== []) {
            /*
             | A control account no list accounts for is the one thing on
             | this page that means somebody must stop.
             |
             | In the declaration rather than in a nested key, because the
             | figures above it look complete and nobody goes looking.
             */
            return [
                'severity' => 'broken',
                'en' => sprintf(
                    'THE SUSPENSE ACCOUNT DOES NOT AGREE WITH THE ITEMS IN IT: %s. Money in a control '.
                    'account that no list accounts for is money nobody can take apart, and it is how '.
                    'an item stays in suspense for three years. Nothing below reconciles until that '.
                    'is explained.%s',
                    implode('; ', $disagreements),
                    $left,
                ),
                'ar' => 'حساب المعلَّقات لا يطابق البنود المسجَّلة فيه، فالرصيد لا يمكن تفكيكه إلى '.
                    'حركات بنكية.',
            ];
        }

        $severity = $oldest > 90 || (int) ($inTransit['oldest_days'] ?? 0) > 5 ? 'attention' : 'ok';

        return [
            'severity' => $severity,
            'en' => sprintf(
                '%s of unexplained bank money in %s, the oldest %d days old, and %s released and not '.
                'yet shown by the bank. The two are never added: suspense sits in its own control '.
                'account and in transit in another, and one figure covering both would belong to '.
                'neither.%s%s',
                (string) $total->amount,
                $currency,
                $oldest,
                (string) $inTransit['total'],
                $oldest > 90
                    ? ' An item over 90 days old is not one anybody will identify by thinking harder: '.
                        'it needs somebody to ask the bank or the customer.'
                    : '',
                $left,
            ),
            'ar' => sprintf(
                '%s %s معلَّقة بلا تفسير، أقدمها %d يومًا، و%s في الطريق لم يُظهرها البنك بعد. '.
                'الرقمان لا يُجمعان لأن لكل منهما حساب مراقبة خاصًا به.',
                (string) $total->amount,
                $currency,
                $oldest,
                (string) $inTransit['total'],
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, string>
     */
    private function declareTheUnreconciled(array $accounts, int $oldest): array
    {
        $drifting = [];
        $lines = 0;

        foreach ($accounts as $account) {
            $lines += (int) $account['lines'];

            /*
             | Drifting is an unexplained line INSIDE a statement period
             | that has already closed.
             |
             | A line after the last booked statement is an ordinary
             | timing item - the next statement will carry it. One dated
             | before a period the bank has already closed is a difference
             | that will be in every reconciliation from now on, and it is
             | the leading indicator the criterion asks for.
             */
            if ((int) $account['lines'] === 0 || $account['statement_period_to'] === null) {
                continue;
            }

            if ((int) $account['oldest_days'] > 0) {
                $drifting[] = sprintf(
                    '%s has %d unexplained line%s, the oldest %d days old, against a statement closed '.
                    'at %s',
                    (string) $account['bank_account'],
                    (int) $account['lines'],
                    (int) $account['lines'] === 1 ? '' : 's',
                    (int) $account['oldest_days'],
                    (string) $account['statement_period_to'],
                );
            }
        }

        if ($lines === 0) {
            return [
                'severity' => 'ok',
                'en' => 'Every statement line on every account here is fully applied to something. '.
                    'There is nothing to age, which is the only state in which a bank reconciliation '.
                    'is not drifting.',
                'ar' => 'كل سطور الكشوف مطبَّقة بالكامل، فلا يوجد ما يُقادَم.',
            ];
        }

        return [
            'severity' => $oldest > 30 ? 'attention' : 'ok',
            'en' => sprintf(
                '%d unexplained line%s across %d account%s, the oldest %d days old. Each is the bank '.
                'asserting a movement that nothing here accounts for, so each will appear in every '.
                'reconciliation until it is matched, split or parked in suspense - which is what '.
                'makes the age of the oldest one the leading indicator rather than the count.%s',
                $lines,
                $lines === 1 ? '' : 's',
                \count($accounts),
                \count($accounts) === 1 ? '' : 's',
                $oldest,
                $drifting === [] ? '' : ' '.implode('; ', $drifting).'.',
            ),
            'ar' => sprintf(
                '%d سطرًا غير مُفسَّر، أقدمها %d يومًا. كل سطر سيظهر في كل مطابقة حتى يُطابَق أو '.
                'يُعلَّق، ولذلك عمر الأقدم هو المؤشر المتقدم وليس العدد.',
                $lines,
                $oldest,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $latest
     * @param  list<string>  $unpresentable
     * @param  list<string>  $selfAuthorised
     * @return array<string, string>
     */
    private function declareTheHistory(
        int $standing,
        int $undone,
        ?array $latest,
        array $unpresentable,
        array $selfAuthorised,
        Carbon $day,
    ): array {
        if ($unpresentable !== []) {
            return [
                'severity' => 'broken',
                'en' => sprintf(
                    'A RECONCILIATION IN THIS HISTORY CANNOT BE PRESENTED: %s. Its stored figures '.
                    'satisfy the identity the table enforces, so the fault is in how they are shown - '.
                    'which is the one error a reader cannot catch, because the page looks right. The '.
                    'rest of the history is listed and this one is not quietly dropped, because a '.
                    'missing row in a list of reconciliations reads as a month nobody reconciled.',
                    implode('; ', $unpresentable),
                ),
                'ar' => 'توجد مطابقة في هذا السجل لا يمكن عرضها، وأرقامها المحفوظة تحقق المعادلة، '.
                    'فالخلل في طريقة العرض.',
            ];
        }

        if ($standing === 0) {
            return [
                'severity' => 'attention',
                'en' => sprintf(
                    'This account has no reconciliation standing%s. An account nobody has reconciled '.
                    'has a ledger balance nobody has compared to the bank, and the period close '.
                    'refuses to walk past it.',
                    $undone === 0 ? ' and never has had one' : sprintf(', and %d has been withdrawn', $undone),
                ),
                'ar' => 'لا توجد مطابقة قائمة لهذا الحساب، ورصيد الدفتر لم يُقارَن بالبنك.',
            ];
        }

        $days = $latest === null
            ? null
            : (int) Carbon::parse((string) $latest['period']['to'])->diffInDays($day, absolute: false);

        return [
            'severity' => $selfAuthorised === [] ? 'ok' : 'attention',
            'en' => sprintf(
                '%d reconciliation%s standing and %d withdrawn, each with the person who authorised it '.
                'and the balances as they stood. Reconciled to %s, which is %d day%s ago.%s',
                $standing,
                $standing === 1 ? '' : 's',
                $undone,
                $latest === null ? 'nothing' : (string) $latest['period']['to'],
                (int) $days,
                (int) $days === 1 ? '' : 's',
                $selfAuthorised === []
                    ? ''
                    : sprintf(
                        ' ONE PERSON BOTH APPLIED THE MATCHES AND AUTHORISED THE RECONCILIATION for %s, '.
                        'so nobody reviewed it: the document looks ordinary on its own and this is the '.
                        'only page the fact is visible on.',
                        implode(', ', $selfAuthorised),
                    ),
            ),
            'ar' => sprintf(
                '%d مطابقة قائمة و%d مسحوبة، ولكل منها من اعتمدها والأرصدة كما كانت.%s',
                $standing,
                $undone,
                $selfAuthorised === [] ? '' : ' وفي بعضها اعتمد المطابقة نفس من طبَّق القيود.',
            ),
        ];
    }
}
