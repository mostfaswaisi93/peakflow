<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Http\Controllers;

use App\Tenant\Ledger\Enums\EntryStatus;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Services\StatementOfProfitOrLoss;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The statement of profit or loss and other comprehensive income on screen - PF-185.
 *
 * ## The permission is checked before a period is even read
 *
 * This page is the entity's whole result for a period: what it earned, what
 * that cost, and what is left after tax. There is no version of it worth
 * serving with the figures removed - the headings alone are IAS 1's line
 * items, which nobody navigated here for - so the answer to a user without
 * `report.view` is a 403 rather than a statement with empty cells, which
 * reads as an entity that traded nothing.
 *
 * ## Nothing here adds up a figure
 *
 * {@see StatementOfProfitOrLoss} returns the bands, their lines, the running
 * subtotal at each band boundary, both analyses, profit for the period, total
 * comprehensive income and the declaration. The controller chooses two
 * periods and hands the result over whole. A screen that re-derived a
 * subtotal would be a second definition of the statement's shape, and the
 * first line put on the wrong side of it would print a subtotal that is wrong
 * under a total that is right - which is the hardest arithmetic error to see,
 * because the bottom of the page still agrees with the ledger.
 *
 * ## "Earlier" means something stricter here than on the sibling page
 *
 * The statement of financial position is a position at a date, so its
 * comparative only has to be an earlier date. Profit or loss is a MOVEMENT
 * over a window, so a comparative that merely ends earlier can still overlap
 * the reported period - and an overlap counts the same revenue in both
 * columns, which reads as growth that did not happen. So the selector here
 * offers only periods that ended before the reported one BEGAN, which is the
 * same boundary the statement refuses on.
 *
 * ## The comparative column is narrowed here, not refused there
 *
 * The statement refuses an overlapping comparative, and that refusal is
 * correct and is the wrong thing for a reader to meet: `?comparative=` is in
 * a URL anybody can edit or forward, and an off-by-one there would replace
 * the whole statement with an explanation of why one column could not be
 * drawn. So an id outside the offered set falls back to the default, and the
 * refusal is still caught - it is the half that holds if the two definitions
 * of "earlier" ever drift apart.
 */
final class ProfitOrLossController
{
    /**
     * @permission report.view - the statement is the entity's result in full,
     *     which is reporting information rather than something the navigation
     *     offers everybody. It is the permission `Navigation` hides this item
     *     behind, and a page readable where its own menu entry is hidden is
     *     the arrangement where the menu looks like the control. The route
     *     carries it too; this is the half that still holds when somebody
     *     adds a second route to the group and forgets the middleware.
     */
    public function __invoke(
        Request $request,
        EntityContext $context,
        StatementOfProfitOrLoss $statement,
    ): View {
        Gate::authorize('report.view');

        $entity = $context->entity();

        if ($entity === null) {
            return view('app.ledger.profit-or-loss', [
                'entity' => null,
                'periods' => [],
                'period' => null,
                'comparatives' => [],
                'comparative' => null,
                'statement' => null,
                'refusal' => null,
                'unknownPeriod' => false,
                'unknownComparative' => false,
            ]);
        }

        /*
         | The adjusting period is not in the selector, and the figures still
         | include it.
         |
         | Period 13 starts and ends on the fiscal year's last day, so
         | choosing it would ask for the result of a window one day long -
         | which on a statement of profit or loss is a page of zeros beside
         | one closing adjustment, read as a year in which nothing was sold.
         | The adjustments belong in December's result, which is where the
         | statement's own `includeAdjusting` default puts them, and the basis
         | sentence under the figures says so in words.
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
         | is a month that has not happened yet - and a movement statement
         | over it reports a result of exactly zero, which is also what a
         | broken report returns. The periods that hold entries are asked for
         | as a set and intersected with the list already loaded, rather than
         | joined per period, so the cost does not follow the calendar.
         |
         | `reversed` counts alongside `posted`: a reversal records that a
         | reversing entry exists, not that the original was withdrawn, so a
         | month whose only entries were later reversed still has a result to
         | show - and it is the one a reader is most likely to be asking
         | about.
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

        /*
         | Only the periods that ENDED before the reported one BEGAN.
         |
         | Not "ends earlier", which is the test the statement of financial
         | position uses: a period ending inside this one would hand the
         | service two overlapping windows, and the same invoice would be
         | counted in both columns. Built from the list already loaded rather
         | than queried again, so the set the selector offers and the set this
         | handler validates against are one list and cannot disagree about
         | which periods exist.
         */
        $comparatives = $periods->filter(
            static fn (Period $candidate): bool => $period !== null && $candidate->ends_on->lt($period->starts_on),
        )->values();

        /*
         | "No comparative" is a word, not an empty value.
         |
         | A GET form submits the empty option as `comparative=`, which is
         | indistinguishable from a parameter nobody set - so if empty meant
         | "no column", the default below would be unreachable, and if it
         | meant "the default", choosing "no column" would be undone the
         | moment the reader pressed the button. One of the two states needs
         | a name of its own.
         */
        $wanted = $request->query('comparative');
        $wanted = \is_string($wanted) ? $wanted : '';

        /*
         | `subYearNoOverflow`, because February exists.
         |
         | A plain subtraction of a year from the 29th of February 2024 lands
         | on the 1st of March 2023, which is no period's last day: the
         | comparative column would then be silently absent for exactly one
         | month in every four years, on a page where its absence is supposed
         | to mean there is no earlier period on file.
         */
        $sameMonthLastYear = $period?->ends_on->copy()->subYearNoOverflow()->toDateString();

        $default = $comparatives->first(
            static fn (Period $candidate): bool => $candidate->ends_on->toDateString() === $sameMonthLastYear,
        );

        $named = $wanted === '' || $wanted === 'none'
            ? null
            : $comparatives->firstWhere('id', $wanted);

        $comparative = match (true) {
            $wanted === 'none' => null,
            $named !== null => $named,
            default => $default,
        };

        $built = null;
        $refusal = null;

        if ($period !== null) {
            try {
                /*
                 | The comparative's own start date, never the service's
                 | default.
                 |
                 | Left out, `comparativeFrom` falls back to the start of the
                 | comparative's YEAR - so one month would be compared against
                 | eleven months plus itself, and the current column would
                 | read as a collapse in revenue against a prior period that
                 | was never a month. The selector offered a period; the
                 | period's own window is what gets reported.
                 */
                $built = $statement->forPeriod(
                    entityId: (string) $entity->getKey(),
                    functionalCurrency: (string) $entity->functional_currency,
                    from: $period->starts_on,
                    until: $period->ends_on,
                    comparativeFrom: $comparative?->starts_on,
                    comparativeUntil: $comparative?->ends_on,
                );
            } catch (ReportRefused $refused) {
                /*
                 | The refusal is carried to the page in the statement's own
                 | words.
                 |
                 | It names what is wrong and what a reader would wrongly
                 | conclude from it, and a paraphrase here would be a second
                 | explanation that drifts from the first the next time either
                 | is edited. A reader who is told "this report is
                 | unavailable" goes to support; one who is told that the
                 | comparative period overlaps this one changes the column.
                 */
                $refusal = $refused->getMessage();
            }
        }

        return view('app.ledger.profit-or-loss', [
            'entity' => $entity,
            'periods' => $periods,
            'period' => $period,
            'comparatives' => $comparatives,
            'comparative' => $comparative,
            'statement' => $built,
            'refusal' => $refusal,
            'unknownPeriod' => $requested !== '' && $chosen === null,
            'unknownComparative' => $wanted !== '' && $wanted !== 'none' && $named === null,
        ]);
    }
}
