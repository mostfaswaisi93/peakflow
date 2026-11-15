<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Sales\Enums\RecognitionBasis;
use App\Tenant\Sales\Exceptions\RecognitionRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\RecognitionPlanRow;
use App\Tenant\Sales\Posting\Rules\RevenueRecognisedRule;
use App\Tenant\Tenancy\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deferring revenue, and releasing it as it is earned - PF-107, IFRS 15.
 *
 * ## The plan is written at issue and read by the run
 *
 * Not computed at every run. A formula re-evaluated monthly disagrees with
 * itself the first time anything moves - a credit note, a reopened period, a
 * line edited - and "show me how you arrived at this month's revenue" is a
 * question about a list.
 *
 * ## The case that breaks naive implementations
 *
 * A credit note against a partially recognised invoice. Three months of a
 * twelve-month support contract have been recognised when the customer
 * cancels: 250 of 1,000 is revenue and 750 is still a liability. A credit
 * note that debits revenue for the whole 1,000 leaves the deferral at -750
 * and overstates the reversal by three months of income; one that debits the
 * deferral for the whole 1,000 leaves revenue overstated by the same.
 *
 * So `unwind()` splits it: the recognised part comes out of revenue, the
 * unrecognised part out of the deferral, and the remaining plan rows are
 * cancelled so no later run releases revenue for a document that has been
 * credited.
 */
final class RevenueRecognition
{
    public function __construct(
        private readonly Poster $poster,
        private readonly FiscalCalendarGenerator $calendar,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Write the plan for an issued document's deferred lines - PF-107.
     *
     * Called by the issuer after the posting, because a plan for a document
     * that failed to post is a plan for a document that does not exist.
     *
     * @return list<RecognitionPlanRow>
     */
    public function plan(Invoice $invoice): array
    {
        if (! $invoice->isIssued()) {
            throw RecognitionRefused::aDocumentThatIsNotIssued();
        }

        $rows = [];

        foreach ($invoice->lines()->get() as $line) {
            $basis = $line->recognitionBasis();

            if (! $basis->defersRevenue()) {
                continue;
            }

            $rows = [...$rows, ...$this->planFor($invoice, $line, $basis)];
        }

        return $rows;
    }

    /**
     * What is due for recognition on or before a date, and what it would post.
     *
     * @return array{
     *     due: list<array{line: string, invoice: string, date: string, amount: string, note: string}>,
     *     total: string,
     *     deferred_after: string,
     * }
     */
    public function preview(string $entityId, CarbonImmutable $upTo, string $currency): array
    {
        $due = [];
        $total = Money::zero($currency);

        foreach ($this->plannedUpTo($entityId, $upTo) as $row) {
            $due[] = [
                'line' => (string) $row->invoice_line_id,
                'invoice' => (string) $row->invoice_id,
                'date' => (string) $row->recognise_on?->toDateString(),
                'amount' => (string) $row->amount,
                'note' => (string) $row->note,
            ];

            $total = $total->plus($row->money());
        }

        return [
            'due' => $due,
            'total' => (string) $total->amount,
            'deferred_after' => (string) $this->deferredBalance($entityId, $currency)
                ->minus($total)->amount,
        ];
    }

    /**
     * Recognise everything due up to a date, one entry per period - PF-107.
     *
     * ## Why per period and not one entry for the run
     *
     * Because revenue belongs to the month it was earned in. A run at the end
     * of June that found May unrecognised would otherwise post both months
     * into June, and June's revenue would be overstated by May's while May's
     * accounts - possibly already reported - would be understated by the
     * same. The run is a convenience; the period is the fact.
     *
     * ## Re-runnable
     *
     * The plan rows already recognised are skipped and the report says how
     * many. A reporting command that double-posts is worse than one that
     * fails, because the figures look plausible.
     *
     * @return array{
     *     period: string,
     *     recognised: string,
     *     entry: string|null,
     *     entries: list<array{period: string, date: string, amount: string, entry: string}>,
     *     rows: int,
     *     skipped: int,
     *     deferred_after: string,
     * }
     */
    public function recognise(Entity $entity, CarbonImmutable $upTo, ?string $userId = null): array
    {
        $entityId = (string) $entity->getKey();
        $currency = (string) $entity->functional_currency;

        $rows = $this->plannedUpTo($entityId, $upTo);

        $skipped = RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->where('status', 'recognised')
            ->whereDate('recognise_on', '<=', $upTo->toDateString())
            ->count();

        if ($rows === []) {
            return [
                'period' => $upTo->format('Y-m'),
                'recognised' => '0.0000',
                'entry' => null,
                'entries' => [],
                'rows' => 0,
                'skipped' => $skipped,
                'deferred_after' => (string) $this->deferredBalance($entityId, $currency)->amount,
            ];
        }

        /*
         | Grouped by the period each row is earned in.
         |
         | Every period involved has to accept postings before anything is
         | written: a run that posted May and then found June locked would
         | leave half a release in the books, and the half it wrote is the
         | half nobody is looking for.
         */
        $byPeriod = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse((string) $row->recognise_on?->toDateString());
            $period = $this->calendar->periodFor($entityId, Carbon::parse($date->toDateString()));

            if (! $period->state->acceptsPostings()) {
                throw RecognitionRefused::thePeriodIsNotOpen(
                    (string) $period->label,
                    $period->state->value,
                );
            }

            $key = (string) $period->getKey();
            $byPeriod[$key] ??= ['period' => $period, 'rows' => [], 'date' => $date];
            $byPeriod[$key]['rows'][] = $row;

            // The entry is dated at the latest row in the period, which is
            // the day the last of that month's service was performed.
            if ($date->gt($byPeriod[$key]['date'])) {
                $byPeriod[$key]['date'] = $date;
            }
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity, $entityId, $currency, $upTo, $byPeriod, $rows, $skipped
        ): array {
            $entries = [];
            $total = Money::zero($currency);

            foreach ($byPeriod as $group) {
                /** @var Period $period */
                $period = $group['period'];
                $byAccount = [];
                $periodTotal = Money::zero($currency);

                foreach ($group['rows'] as $row) {
                    $account = (string) ($row->revenue_account_id ?? '');

                    if ($account === '') {
                        throw RecognitionRefused::aReleaseWithNoAccount();
                    }

                    $byAccount[$account] = isset($byAccount[$account])
                        ? $byAccount[$account]->plus($row->money())
                        : $row->money();

                    $periodTotal = $periodTotal->plus($row->money());
                }

                $releases = [];

                foreach ($byAccount as $account => $amount) {
                    $releases[] = ['account_id' => (string) $account, 'amount' => (string) $amount->amount];
                }

                $entry = $this->poster->post(
                    $entity,
                    RevenueRecognisedRule::KEY,
                    [
                        'period' => (string) $period->label,
                        'narration' => sprintf('Revenue recognised for %s', (string) $period->label),
                        'releases' => $releases,
                    ],
                    Carbon::parse($group['date']->toDateString()),
                    'GEN',
                    'revenue_recognition',
                    (string) $period->getKey(),
                );

                foreach ($group['rows'] as $row) {
                    $row->update([
                        'status' => 'recognised',
                        'journal_entry_id' => (string) $entry->getKey(),
                        'recognised_at' => now(),
                    ]);
                }

                $entries[] = [
                    'period' => (string) $period->label,
                    'date' => $group['date']->toDateString(),
                    'amount' => (string) $periodTotal->amount,
                    'entry' => (string) $entry->getKey(),
                ];

                $total = $total->plus($periodTotal);

                $this->audit->record(
                    category: 'ledger',
                    action: 'revenue.recognised',
                    subjectType: 'period',
                    subjectId: (string) $period->getKey(),
                    subjectLabel: (string) $period->label,
                    after: [
                        'recognised' => (string) $periodTotal->amount,
                        'rows' => \count($group['rows']),
                        'entry' => (string) $entry->getKey(),
                    ],
                    entityId: $entityId,
                    periodId: (string) $period->getKey(),
                );
            }

            return [
                'period' => $entries === [] ? $upTo->format('Y-m') : (string) $entries[\count($entries) - 1]['period'],
                'recognised' => (string) $total->amount,
                'entry' => $entries === [] ? null : (string) $entries[0]['entry'],
                'entries' => $entries,
                'rows' => \count($rows),
                'skipped' => $skipped,
                'deferred_after' => (string) $this->deferredBalance($entityId, $currency)->amount,
            ];
        });
    }

    /**
     * What a credit note has to take back, split by what was earned - PF-107.
     *
     * ## The case that breaks naive implementations
     *
     * Three months of a twelve-month contract are revenue and nine are still
     * a liability. Debiting revenue for the whole amount overstates the
     * reversal by three months of income; debiting the deferral for the whole
     * amount overstates revenue by the same. Both leave the deferred revenue
     * control account unreconcilable, in opposite directions.
     *
     * @return array{recognised: string, deferred: string, cancelled: int}
     */
    public function unwind(Invoice $credited, Money $creditedNet, ?InvoiceLine $line = null): array
    {
        $currency = (string) $creditedNet->currency;

        $planned = RecognitionPlanRow::query()
            ->where('invoice_id', (string) $credited->getKey())
            ->when($line !== null, fn ($q) => $q->where('invoice_line_id', (string) $line?->getKey()))
            ->get();

        if ($planned->isEmpty()) {
            // Nothing was deferred: the whole credit comes out of revenue,
            // which is what the ordinary credit note rule already does.
            return ['recognised' => (string) $creditedNet->amount, 'deferred' => '0.0000', 'cancelled' => 0];
        }

        $stillDeferred = Money::zero($currency);
        $alreadyRecognised = Money::zero($currency);

        foreach ($planned as $row) {
            if ($row->isPlanned()) {
                $stillDeferred = $stillDeferred->plus($row->money());

                continue;
            }

            if ((string) $row->status === 'recognised') {
                $alreadyRecognised = $alreadyRecognised->plus($row->money());
            }
        }

        $total = $stillDeferred->plus($alreadyRecognised);

        if ($total->isZero()) {
            return ['recognised' => (string) $creditedNet->amount, 'deferred' => '0.0000', 'cancelled' => 0];
        }

        /*
         | A partial credit takes back its share of each side.
         |
         | Allocated rather than divided, so the two parts add back to the
         | credit exactly: a cent left over would sit in the deferral
         | forever, and a control account that never quite reaches zero is
         | one people stop reconciling.
         */
        $split = $creditedNet->allocate(
            [(string) $alreadyRecognised->amount, (string) $stillDeferred->amount],
            RoundingPolicy::halfUp(Scale::Amount),
        );

        $cancelled = 0;

        if (! $split[1]->isZero()) {
            /*
             | The remaining plan rows are cancelled, from the last one back.
             |
             | Otherwise a later run releases revenue for a document that has
             | been credited - the figures would be plausible, the deferral
             | would go negative in eight months' time, and the person who
             | finds it will be reconciling a control account against a
             | credit note from last year.
             */
            $remaining = $split[1];

            foreach ($planned->where('status', 'planned')->sortByDesc('recognise_on') as $row) {
                if ($remaining->isZero()) {
                    break;
                }

                $amount = $row->money();

                if ($amount->isGreaterThan($remaining)) {
                    $row->update(['amount' => (string) $amount->minus($remaining)->amount]);
                    $remaining = Money::zero($currency);

                    continue;
                }

                $row->update(['status' => 'cancelled']);
                $remaining = $remaining->minus($amount);
                $cancelled++;
            }
        }

        return [
            'recognised' => (string) $split[0]->amount,
            'deferred' => (string) $split[1]->amount,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * The roll-forward: opening, additions, recognised, closing - PF-107.
     *
     * ## Classified by when the revenue is EARNED, not when the job ran
     *
     * A run on the 3rd of July that releases June's revenue is June's
     * recognition. Classifying by the run's own timestamp would move a
     * month's revenue into the month somebody happened to run a command,
     * which is the kind of figure that reconciles to nothing.
     *
     * ## Why it reconciles to the control account
     *
     * Because a deferred revenue schedule that does not tie to the ledger is
     * a spreadsheet. The closing figure is the **roll** - opening plus
     * additions less recognised less cancelled - and the control account is
     * read from the journal lines, independently. Deriving one from the other
     * is the shape of a reconciliation that is always clean, and the
     * difference is the only figure worth looking at.
     *
     * @return array{
     *     from: string, to: string, currency: string,
     *     opening: string, additions: string, recognised: string, cancelled: string,
     *     closing: string, still_planned: string, control_account: string, difference: string,
     * }
     */
    public function rollForward(
        string $entityId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $currency,
    ): array {
        $money = static fn (mixed $amount): Money => Money::of((string) ($amount ?: '0'), $currency);

        /*
         | Opening: everything that was still deferred the day before the
         | window began.
         |
         | A row counts as deferred at a date when it is earned on or after
         | it - whether or not it has been recognised since - and the
         | document was issued before it. The second half is what makes a
         | contract invoiced inside the window an addition rather than an
         | opening balance.
         */
        $opening = $money(RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', ['planned', 'recognised'])
            ->whereDate('recognise_on', '>=', $from->toDateString())
            ->whereHas('invoice', fn ($q) => $q->whereDate('issue_date', '<', $from->toDateString()))
            ->sum('amount'));

        $additions = $money(RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', ['planned', 'recognised'])
            ->whereHas('invoice', fn ($q) => $q
                ->whereDate('issue_date', '>=', $from->toDateString())
                ->whereDate('issue_date', '<=', $to->toDateString()))
            ->sum('amount'));

        $recognised = $money(RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->where('status', 'recognised')
            ->whereDate('recognise_on', '>=', $from->toDateString())
            ->whereDate('recognise_on', '<=', $to->toDateString())
            ->sum('amount'));

        $cancelled = $money(RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->where('status', 'cancelled')
            ->whereDate('recognise_on', '>=', $from->toDateString())
            ->whereDate('recognise_on', '<=', $to->toDateString())
            ->sum('amount'));

        $closing = $opening->plus($additions)->minus($recognised)->minus($cancelled);

        // What the plan itself still says is deferred, for the one case the
        // roll cannot see: a row cancelled or edited outside the window.
        $stillPlanned = $money(RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->where('status', 'planned')
            ->sum('amount'));

        $control = $this->deferredBalance($entityId, $currency);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $currency,
            'opening' => (string) $opening->amount,
            'additions' => (string) $additions->amount,
            'recognised' => (string) $recognised->amount,
            'cancelled' => (string) $cancelled->amount,
            'closing' => (string) $closing->amount,
            'still_planned' => (string) $stillPlanned->amount,
            'control_account' => (string) $control->amount,
            'difference' => (string) $closing->minus($control)->amount,
        ];
    }

    /** What the deferred revenue control account holds, as a positive figure. */
    public function deferredBalance(string $entityId, string $currency): Money
    {
        $account = AccountRole::query()
            ->where('entity_id', $entityId)
            ->where('role', ControlRole::DeferredRevenue->value)
            ->value('account_id');

        if ($account === null) {
            return Money::zero($currency);
        }

        $sum = DB::connection('tenant')->table('journal_lines')
            ->where('entity_id', $entityId)
            ->where('account_id', (string) $account)
            ->sum('amount');

        // A liability is a credit balance, so it comes back negative; the
        // report wants the figure people say out loud.
        return Money::of((string) ($sum ?: '0'), $currency)->negated();
    }

    /**
     * @return list<RecognitionPlanRow>
     */
    private function plannedUpTo(string $entityId, CarbonImmutable $upTo): array
    {
        return RecognitionPlanRow::query()
            ->where('entity_id', $entityId)
            ->where('status', 'planned')
            ->whereDate('recognise_on', '<=', $upTo->toDateString())
            ->orderBy('recognise_on')
            ->get()
            ->all();
    }

    /**
     * The rows one deferred line produces.
     *
     * @return list<RecognitionPlanRow>
     */
    private function planFor(Invoice $invoice, InvoiceLine $line, RecognitionBasis $basis): array
    {
        $currency = (string) $invoice->currency;
        $net = Money::of((string) $line->net_amount, $currency);

        if ($basis->waitsForAnEvent()) {
            /*
             | One row, dated at the service start or the issue date.
             |
             | A milestone or a delivery is confirmed by somebody later, and
             | that confirmation moves the row's date. Writing it now with a
             | placeholder date is what makes the deferral visible in the
             | roll-forward from the day it is created - a liability that
             | appears on a report only once somebody confirms something is a
             | liability nobody is watching.
             */
            return [RecognitionPlanRow::create([
                'entity_id' => (string) $invoice->entity_id,
                'invoice_id' => (string) $invoice->getKey(),
                'invoice_line_id' => (string) $line->getKey(),
                'recognise_on' => ($line->service_from ?? $invoice->issue_date)?->toDateString(),
                'currency' => $currency,
                'amount' => (string) $net->amount,
                'status' => 'planned',
                'revenue_account_id' => $line->revenue_account_id,
                'note' => sprintf('awaiting %s', $basis->label('en')),
            ])];
        }

        $from = $line->service_from;
        $until = $line->service_until;

        if ($from === null || $until === null) {
            throw RecognitionRefused::aServicePeriodIsMissing((int) $line->line_no);
        }

        $start = CarbonImmutable::parse($from->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($until->toDateString())->startOfDay();

        if ($end->lt($start)) {
            throw RecognitionRefused::aServicePeriodRunsBackwards(
                (int) $line->line_no,
                $start->toDateString(),
                $end->toDateString(),
            );
        }

        /*
         | One row per calendar month the service touches, dated at the end of
         | the month - because that is when a month's service has been
         | performed, and recognising on the 1st recognises work not yet done.
         |
         | The last row is dated at the service end rather than its month end,
         | so a contract ending on the 15th does not recognise its last
         | tranche in a period the contract does not reach.
         */
        $months = [];
        $cursor = $start->startOfMonth();

        while ($cursor->lte($end)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $months[] = $monthEnd->gt($end) ? $end : $monthEnd;
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        if ($months === []) {
            $months = [$end];
        }

        /*
         | Allocated by the days each month actually carries.
         |
         | A contract from the 20th of March to the 19th of March next year
         | has eleven days in its first month and nineteen in its last, and
         | twelve equal twelfths would recognise a month of revenue in those
         | eleven days.
         */
        $weights = [];
        $previousEnd = $start->subDay();

        foreach ($months as $monthEnd) {
            $weights[] = (string) ($previousEnd->diffInDays($monthEnd));
            $previousEnd = $monthEnd;
        }

        $amounts = $net->allocate($weights, RoundingPolicy::halfUp(Scale::Amount));
        $rows = [];

        foreach ($months as $index => $monthEnd) {
            if ($amounts[$index]->isZero()) {
                continue;
            }

            $rows[] = RecognitionPlanRow::create([
                'entity_id' => (string) $invoice->entity_id,
                'invoice_id' => (string) $invoice->getKey(),
                'invoice_line_id' => (string) $line->getKey(),
                'recognise_on' => $monthEnd->toDateString(),
                'currency' => $currency,
                'amount' => (string) $amounts[$index]->amount,
                'status' => 'planned',
                'revenue_account_id' => $line->revenue_account_id,
                'note' => sprintf('month %d of %d, %s', $index + 1, \count($months), $monthEnd->format('Y-m')),
            ]);
        }

        return $rows;
    }
}
