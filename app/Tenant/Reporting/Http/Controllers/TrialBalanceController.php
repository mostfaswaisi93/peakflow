<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Http\Controllers;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Models\FxRate;
use App\Tenant\Ledger\Enums\EntryStatus;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Services\TrialBalanceReport;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The trial balance on screen - PF-183.
 *
 * ## The whole page is behind `report.view`, unlike the Overview
 *
 * The Overview splits the page from its figures because it is the screen
 * somebody lands on after signing in, and a 403 there reads as a broken
 * account. This page is nothing but figures: a trial balance with the
 * amounts removed is a list of account names, which nobody navigated here
 * for. So the permission is checked before anything is read, and the answer
 * is a 403 rather than an empty report that looks like an empty ledger.
 *
 * ## Nothing here recomputes a figure
 *
 * Every number on the page comes from {@see TrialBalanceReport}, which is
 * where opening, movement and closing are one arithmetic and where the
 * balance verdict is a sentence rather than a boolean. The controller picks
 * the period and the presentation currency and hands the report over whole:
 * a screen that re-derived any of it would be a second answer to a question
 * the ledger already answers, and the first time the two disagreed nobody
 * could say which was the error.
 *
 * ## It refuses to offer a currency it cannot translate into
 *
 * The report refuses a presentation currency with no rate on record, which
 * is correct and is a dead end if the screen offered the currency anyway.
 * So the selector lists only the currencies that have a `market` rate from
 * the functional currency on or before the period end - the same lookup
 * {@see \App\Tenant\Catalog\Services\ExchangeRates::latestOnOrBefore()} will
 * perform - and a code typed straight into the URL still gets the report's
 * own refusal rather than a translated figure.
 */
final class TrialBalanceController
{
    /**
     * Requires `report.view`, asserted below before any figure is read.
     *
     * Stated here as well as on the route: the route declaration is what
     * makes the permission visible in a route listing, and this is the half
     * that holds when somebody adds a second route to the group and forgets
     * it. There is deliberately no `@no-permission-required` escape on this
     * handler - a trial balance anybody signed in could read would be the
     * group's whole position, available to a user granted nothing.
     */
    public function __invoke(Request $request, EntityContext $context, TrialBalanceReport $report): View
    {
        Gate::authorize('report.view');

        $entity = $context->entity();

        if ($entity === null) {
            return view('app.ledger.trial-balance', [
                'entity' => null,
                'periods' => [],
                'period' => null,
                'presentable' => [],
                'present' => '',
                'report' => null,
                'refusal' => null,
                'depths' => [],
                'unknownPeriod' => false,
                'unknownCurrency' => false,
            ]);
        }

        /*
         | The adjusting period is not in the selector, and the figures still
         | include it.
         |
         | Period 13 starts and ends on the fiscal year's last day, so
         | choosing it would ask for a trial balance whose opening balance is
         | read on the thirtieth of December - a report of the adjustments
         | alone, dressed as a period. The adjustments belong in December's
         | figures, which is where `includeAdjusting` puts them, and the
         | basis sentence under the table says so in words.
         */
        $periods = Period::query()
            ->where('is_adjusting', false)
            ->orderByDesc('starts_on')
            ->get();

        /*
         | The default is the latest period that has postings, not the latest
         | period.
         |
         | A fiscal calendar is generated a year ahead, so "the latest period"
         | is a month that has not happened yet - and a trial balance of an
         | empty future month is a page of nothing that reads as a ledger with
         | nothing in it. The periods that hold entries are asked for as a
         | set and intersected with the list already loaded, rather than
         | joined per period, so the cost does not follow the calendar.
         |
         | `reversed` counts alongside `posted`: a reversal records that a
         | reversing entry exists, not that the original was withdrawn, so a
         | month whose only entries were later reversed still has figures to
         | show.
         */
        $withPostings = JournalEntry::query()
            ->whereIn('status', [EntryStatus::Posted->value, EntryStatus::Reversed->value])
            ->distinct()
            ->pluck('period_id')
            ->all();

        $requested = $request->query('period');
        $requested = \is_string($requested) ? $requested : '';

        $chosen = $requested === '' ? null : $periods->firstWhere('id', $requested);

        $period = $chosen
            ?? $periods->first(static fn (Period $candidate): bool => \in_array(
                (string) $candidate->getKey(),
                $withPostings,
                true,
            ))
            ?? $periods->first();

        $functional = (string) $entity->functional_currency;

        /*
         | Only the currencies this report can actually be presented in.
         |
         | `market` and this direction only: `ExchangeRates` refuses to invert
         | a rate, so a `USD -> SAR` row on file does not make a SAR-kept set
         | of books presentable in USD. Offering it would put a dead end in a
         | dropdown, and the reader who clicked it would read the refusal as a
         | fault in the report rather than a rate nobody has recorded.
         */
        $presentable = $period === null ? [] : FxRate::query()
            ->where('from_currency', $functional)
            ->where('rate_set', Rate::SET_MARKET)
            ->whereNull('withdrawn_at')
            ->where('as_of_date', '<=', $period->ends_on->toDateString())
            ->distinct()
            ->orderBy('to_currency')
            ->pluck('to_currency')
            ->all();

        /*
         | `?present[]=x` arrives as an array, and casting one to a string is
         | a 500 on a URL anybody can type.
         */
        $parameter = $request->query('present');
        $present = \is_string($parameter) ? mb_strtoupper(trim($parameter)) : '';

        /*
         | An unknown code is reported, never quietly dropped.
         |
         | `Money::of()` throws on a currency that is not in the ISO
         | registry, so a typed `?present=USDD` would be a 500; swallowing it
         | instead would serve figures in the functional currency under a
         | heading the URL says is something else, which is the one outcome
         | worse than an error.
         */
        $unknownCurrency = $present !== '' && ! CurrencyRegistry::exists($present);

        /*
         | Asking for the functional currency is not a translation.
         |
         | The report would hand it straight back, and `ExchangeRates`
         | refuses a same-currency rate rather than returning a 1 - so
         | passing it through would turn "show me this in the currency it is
         | already in" into a refusal.
         */
        $presentIn = $present !== '' && ! $unknownCurrency && $present !== $functional
            ? $present
            : null;

        $built = null;
        $refusal = null;

        if ($period !== null) {
            try {
                $built = $report->forPeriod(
                    entityId: (string) $entity->getKey(),
                    functionalCurrency: $functional,
                    from: $period->starts_on,
                    until: $period->ends_on,
                    presentIn: $presentIn,
                );
            } catch (ReportRefused $refused) {
                /*
                 | The refusal is carried to the page in the report's own
                 | words.
                 |
                 | It names the pair, the date and what to do about it, and a
                 | paraphrase here would be a second explanation that drifts
                 | from the first the next time either is edited. A reader who
                 | is told "this report is unavailable" goes to support; one
                 | who is told which rate is missing records it.
                 */
                $refusal = $refused->getMessage();
            }
        }

        /*
         | The depth of each account, so the table can show the hierarchy.
         |
         | The report returns codes and no structure, deliberately - it is a
         | query over the journal and the chart's shape is not its business.
         | Read here as one map rather than per row: a lookup per account on a
         | chart of five thousand is an N+1 that no figure on the page would
         | be wrong about, which is why nobody would find it.
         */
        return view('app.ledger.trial-balance', [
            'entity' => $entity,
            'periods' => $periods,
            'period' => $period,
            'presentable' => $presentable,
            'present' => $presentIn ?? '',
            'report' => $built,
            'refusal' => $refusal,
            'depths' => Account::query()->pluck('depth', 'code')->all(),
            'unknownPeriod' => $requested !== '' && $chosen === null,
            'unknownCurrency' => $unknownCurrency,
        ]);
    }
}
