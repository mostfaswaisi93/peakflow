<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Http\Controllers;

use App\Tenant\Ledger\Manual\DraftLine;
use App\Tenant\Ledger\Manual\ManualEntryPoster;
use App\Tenant\Ledger\Manual\ManualEntryRefused;
use App\Tenant\Ledger\Manual\ManualJournal;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The manual journal screen - PF-055.
 *
 * ## Why this screen did not exist until the grid did
 *
 * PF-055's last criterion is a keyboard contract: type to edit, `Enter`
 * commits and moves down, `Tab` commits and moves right. That is a claim
 * about a screen, and building the grid before the design system existed
 * would have meant building it twice. The grid is PF-205 to PF-207's, the
 * keyboard model is in `enhancements.js`, and this page is the first write
 * path to use them.
 *
 * ## The arithmetic is the server's, and that is the whole design
 *
 * PF-055 asks for "a running balance that shows the imbalance as you type",
 * and the obvious way to build it is to add up the amount column in
 * JavaScript. That is a second implementation of the thing this product is
 * for.
 *
 * It would also be a different one. `ManualJournal::validate()` resolves
 * each account code, refuses a control account with an explanation, reads
 * amounts strictly - Arabic-Indic digits normalised, thousands separators
 * accepted, "about 12k" refused - translates foreign lines at a rate with a
 * provenance, and rounds to the policy's scale. A browser summing
 * `parseFloat` would agree with it on the easy rows and disagree on exactly
 * the rows somebody is about to post.
 *
 * So every keystroke asks the server, through `check()`, and what comes
 * back is the verdict - the same object the POST path refuses with. There is
 * one answer to "is this entry balanced", and the screen is a view of it.
 *
 * ## And it works with no script at all
 *
 * The grid is a form. With scripting off, `Check` submits the same rows to
 * the same validation and the page re-renders with the verdict and every
 * problem beside its line; `Post` posts. A finance screen that needs
 * JavaScript to tell you an entry does not balance is a screen that will
 * one day post an unbalanced entry on a machine with a content blocker.
 *
 * ## `journal.post`, asserted here as well as on the route
 *
 * The route carries the permission and so does this, which is the half that
 * holds when somebody adds a second route to the group. Posting a manual
 * journal is the one write path that can reach any account in the chart, so
 * it is the last place to rely on a middleware list being complete.
 */
final class ManualJournalController
{
    public function __construct(
        private readonly ManualJournal $validator,
        private readonly ManualEntryPoster $poster,
    ) {}

    /** The empty form, with as many blank rows as a person needs to start. */
    public function create(Request $request, EntityContext $context): View
    {
        Gate::authorize('journal.post');

        return view('app.ledger.journals.create', $this->screen($context, $request, null));
    }

    /**
     * Validate the rows and return the verdict as JSON - PF-055.
     *
     * The endpoint the keystroke asks. Deliberately a POST: the rows are a
     * draft entry, which can carry a customer's narration and an amount,
     * and a GET would put both in a URL and then in an access log.
     */
    public function check(Request $request, EntityContext $context): JsonResponse
    {
        Gate::authorize('journal.post');

        $entity = $this->entityOf($context);
        $verdict = $this->validator->validate($entity, $this->linesFrom($request));

        return new JsonResponse([
            ...$verdict->toArray(),
            /*
             | The sentence, built here rather than in the browser.
             |
             | "Short 90.00 on the credit side" is the figure a person
             | reads, and PF-055's own criterion says why it matters: 90 is
             | a transposition, twice the amount is a sign, a round figure
             | is a missing line. Composing it in the browser would put the
             | wording in two places and the Arabic in neither.
             */
            'sentence' => $this->sentence($verdict),
        ]);
    }

    /** Post it, or come back with the verdict and every problem beside its line. */
    public function store(Request $request, EntityContext $context): RedirectResponse|View
    {
        Gate::authorize('journal.post');

        $entity = $this->entityOf($context);
        $lines = $this->linesFrom($request);

        $date = $this->dateFrom($request);
        $narration = $this->narrationFrom($request);

        /*
         | `check` is the no-script path, and it is a separate button.
         |
         | A screen whose only button posts leaves somebody with scripting
         | off no way to see the imbalance before committing to it - and
         | "press post and read the refusal" is a workflow that teaches
         | people to press post.
         */
        if ($request->input('intent') === 'check') {
            return view('app.ledger.journals.create', $this->screen(
                $context,
                $request,
                $this->validator->validate($entity, $lines),
            ));
        }

        try {
            $entry = $this->poster->post($entity, $lines, $date, $narration);
        } catch (ManualEntryRefused $refused) {
            return view('app.ledger.journals.create', [
                ...$this->screen($context, $request, $refused->verdict),
                'refusal' => $refused->getMessage(),
            ]);
        }

        /*
         | `refresh()`, because the number is written under the model.
         |
         | `Poster::post()` allocates the number inside the transaction and
         | the instance it hands back does not know it. The banner read
         | `$entry->number` and printed nothing - an empty confirmation,
         | which is worse than none: the entry had posted, the screen said
         | so, and there was no number to follow it up by. Measured in a
         | browser; the entry was `GEN-2026/00002` and the banner was blank.
         */
        return redirect()
            ->route('journals.create')
            ->with('posted', (string) $entry->refresh()->number);
    }

    /**
     * The entity in scope, or a refusal rather than a null.
     *
     * `EntityContext::entity()` returns null when nothing is in scope, and
     * every path here needs the functional currency and the chart. A null
     * reaching `validate()` would be a type error at the bottom of a stack
     * instead of a sentence at the top.
     */
    private function entityOf(EntityContext $context): \App\Tenant\Tenancy\Models\Entity
    {
        $entity = $context->entity();

        if (! $entity instanceof \App\Tenant\Tenancy\Models\Entity) {
            abort(409, 'No entity is in scope, so there is no chart of accounts to post into.');
        }

        return $entity;
    }

    /**
     * @return array<string, mixed>
     */
    private function screen(EntityContext $context, Request $request, ?object $verdict): array
    {
        $entity = $this->entityOf($context);

        /*
         | The account picker lists what may be SELECTED.
         |
         | A control account is in the chart and is not in this list, and
         | PF-055 says why it is not merely hidden: the refusal explains -
         | the subledger IS the receivable, a manual posting makes the two
         | disagree, and afterwards nothing can say which is right. So the
         | list omits it and `validate()` still explains it, for anybody
         | who types the code anyway.
         */
        $accounts = Account::query()
            ->where('entity_id', (string) $entity->getKey())
            ->where('postable', true)
            ->orderBy('code')
            ->get(['code', 'name_ar', 'name_en'])
            ->map(static fn (Account $account): array => [
                'code' => (string) $account->code,
                'label' => (string) ($account->name_ar ?? $account->name_en ?? $account->code),
            ])
            ->all();

        return [
            'rows' => $this->rowsFrom($request),
            'verdict' => $verdict,
            'accounts' => $accounts,
            'currency' => (string) $entity->functional_currency,
            'accountingDate' => $this->dateFrom($request)->toDateString(),
            'narration' => $this->narrationFrom($request),
            'posted' => session('posted'),
            'refusal' => null,
        ];
    }

    /**
     * The rows as the screen shows them - strings, blanks and all.
     *
     * PF-055's second criterion: "a line being typed is represented as it is
     * typed". An account code somebody is half way through and an amount
     * that is currently "1,2" are both representable, and that is what stops
     * the screen keeping its own shadow copy of the entry.
     *
     * @return list<array<string, string>>
     */
    private function rowsFrom(Request $request): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) $request->input('lines', []);
        $rows = [];

        foreach ($raw as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $rows[] = [
                'account_code' => $this->text($row['account_code'] ?? null),
                'amount' => $this->text($row['amount'] ?? null),
                'currency' => $this->text($row['currency'] ?? null),
                'narration' => $this->text($row['narration'] ?? null),
            ];
        }

        /*
         | Four blank rows when there are none, and one spare after the last.
         |
         | A grid with no spare row makes adding a line a separate act with
         | its own button; a grid that grows as it is filled lets somebody
         | type an entry without leaving the keyboard, which is the whole
         | subject of this criterion. The blank row at the bottom is counted
         | as blank, not reported as an error - PF-055 again.
         */
        if ($rows === []) {
            return array_fill(0, 4, [
                'account_code' => '',
                'amount' => '',
                'currency' => '',
                'narration' => '',
            ]);
        }

        $rows[] = ['account_code' => '', 'amount' => '', 'currency' => '', 'narration' => ''];

        return $rows;
    }

    /** @return list<DraftLine> */
    private function linesFrom(Request $request): array
    {
        return array_values(array_map(
            static fn (array $row): DraftLine => DraftLine::fromArray($row),
            $this->rowsFrom($request),
        ));
    }

    private function dateFrom(Request $request): Carbon
    {
        $date = $request->input('accounting_date');

        if (! \is_string($date) || $date === '') {
            return Carbon::now();
        }

        /*
         | Parsed against an explicit format, which is PF-055's own lesson.
         |
         | `Carbon::parse('2026-02-30')` returns 2 March - silently, two days
         | into a period that may be closed. The import path learned this the
         | hard way and the screen gets the same treatment.
         */
        $parsed = Carbon::createFromFormat('Y-m-d', $date);

        return $parsed === false ? Carbon::now() : $parsed->startOfDay();
    }

    private function narrationFrom(Request $request): ?string
    {
        $narration = $request->input('narration');

        return \is_string($narration) && trim($narration) !== '' ? trim($narration) : null;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The imbalance as a sentence, in the reader's language.
     *
     * ## "Balanced" is only said about a draft that could post
     *
     * A line with an amount and no account contributes to neither side -
     * `validate()` cannot resolve it, so it has nothing to add - and the
     * two sides of a draft like that are both zero. Saying "the two sides
     * are equal" about it is true arithmetic and a lie about the entry: it
     * reads as "ready", and the next thing somebody does is press post.
     *
     * So an unpostable draft says how many problems it has instead, and the
     * problems themselves are beside their lines after a Check. The figure
     * is still the figure when there is one - PF-055 wants the imbalance
     * with its side, because 90 is a transposition and twice the amount is
     * a sign.
     */
    private function sentence(\App\Tenant\Ledger\Manual\DraftVerdict $verdict): string
    {
        $side = $verdict->imbalanceSide();

        if ($side !== null) {
            return trim(sprintf(
                '%s %s %s',
                (string) __('ناقص'),
                (string) $verdict->imbalance->abs()->toPresentation(),
                $side === 'credit' ? (string) __('في طرف الدائن.') : (string) __('في طرف المدين.'),
            ));
        }

        if (! $verdict->postable) {
            /*
             | ICU, through `Message`, and not `trans_choice()`.
             |
             | The pipe syntax cannot express Arabic: the rule is on the
             | last two digits, so 103 counts the way 3 does, and no range
             | expression says `n % 100 = 3..10`. A check fails the build
             | for any call to it, which is how this sentence came to be
             | written twice.
             */
            return \App\Support\Lang\Message::of('journal.balanced_but_unpostable', [
                'problems' => \count($verdict->problems),
            ]);
        }

        return (string) __('الطرفان متساويان، والقيد جاهز للترحيل.');
    }
}
