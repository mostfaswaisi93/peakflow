<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Http\Api\Wire;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A manual entry that will not be posted, addressed line by line - PF-219.
 *
 * ## The verdict is carried, not summarised
 *
 * {@see ManualJournal} already knows which line is wrong, which field on it,
 * and what to say about it in both languages. Flattening that into a sentence
 * - "the entry is invalid" - would make an integrator read every line of
 * their own request to find out which one, and the information needed to
 * avoid that was computed and then thrown away.
 *
 * So each problem becomes one entry in the RFC 9457 `errors` array, addressed
 * by a JSON pointer into the request body: `/lines/3/account_code`. A client
 * can put the message next to the field, which is the difference between a
 * payload somebody fixes and one they rewrite.
 *
 * ## The imbalance is a figure and is not a line problem
 *
 * An entry whose lines are each fine and do not sum to zero has no problem
 * with any line, and an `errors` array pointing at four correct rows is worse
 * than one pointing at none. It is reported as the amount the entry is out
 * BY, in an extension member, because the shape of that number says what went
 * wrong: 90 is a transposition, twice a line is a sign, a round figure is a
 * missing line.
 *
 * And it is reported as a money OBJECT with a string amount, like every other
 * amount on the wire - PF-222. An imbalance is the one figure a client is
 * most likely to do arithmetic on, so it is the last place to send a float.
 */
final class ManualEntryRefused extends RuntimeException
{
    private function __construct(
        public readonly DraftVerdict $verdict,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function from(DraftVerdict $verdict): self
    {
        return new self($verdict, sprintf(
            'The entry was not posted: %d line problem(s), and it is out by %s %s.',
            \count($verdict->problems),
            (string) $verdict->imbalance->amount,
            $verdict->imbalance->currency,
        ));
    }

    /**
     * The summary, in the language the request asked for.
     *
     * The exception's own message stays English and goes to the log, where it
     * is read by us. This is the sentence the client is shown, and it is the
     * one place the count and the imbalance appear in prose - the figures are
     * also extensions, so nothing has to be parsed out of it.
     */
    private function summary(string $locale): string
    {
        $problems = \count($this->verdict->problems);
        $imbalance = (string) $this->verdict->imbalance->amount;
        $currency = $this->verdict->imbalance->currency;

        if ($locale === 'ar') {
            return $this->verdict->imbalance->isZero()
                ? sprintf('لم يُرحَّل القيد: %d مشكلة في السطور.', $problems)
                : sprintf(
                    'لم يُرحَّل القيد: %d مشكلة في السطور، وهو غير متوازن بمقدار %s %s.',
                    $problems,
                    $imbalance,
                    $currency,
                );
        }

        return $this->verdict->imbalance->isZero()
            ? sprintf('The entry was not posted: %d line problem(s).', $problems)
            : sprintf(
                'The entry was not posted: %d line problem(s), and it is out by %s %s.',
                $problems,
                $imbalance,
                $currency,
            );
    }

    /**
     * Fewer than two lines.
     *
     * Reported as a line problem on the collection rather than on a row,
     * because there is no row to blame: the request is short of one.
     */
    public static function needsTwoLines(string $currency): self
    {
        $zero = Money::zero($currency);

        return new self(
            new DraftVerdict(
                postable: false,
                problems: [LineProblem::of(
                    1,
                    'lines',
                    'too_few_lines',
                    'القيد المزدوج يحتاج سطرين على الأقل، وإلا فلا شيء يوازن شيئًا.',
                    'A double-entry journal needs at least two lines; with one, nothing balances anything.',
                )],
                lines: [],
                debits: $zero,
                credits: $zero,
                imbalance: $zero,
                blankLines: 0,
            ),
            'A journal entry with fewer than two lines cannot balance against anything.',
        );
    }

    public function render(Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        $locale = app()->getLocale();

        $problem = Problem::of(ProblemType::ValidationFailed)
            ->withLocalizedDetail($this->summary('ar'), $this->summary('en'));

        foreach ($this->verdict->problems as $lineProblem) {
            $problem->withFieldError(
                /*
                 | Zero-based, because the pointer addresses the JSON array
                 | the client sent and RFC 6901 indexes from zero. The
                 | verdict counts lines from one, which is what a person
                 | reading a grid expects - and handing a client a pointer
                 | that is off by one is worse than handing it none, because
                 | it will highlight the wrong row with complete confidence.
                 */
                sprintf('/lines/%d/%s', $lineProblem->line - 1, $lineProblem->field),
                $lineProblem->code,
                $lineProblem->message($locale),
            );
        }

        if (! $this->verdict->imbalance->isZero()) {
            $problem->withExtensions([
                'imbalance' => Wire::money($this->verdict->imbalance),
                'imbalance_side' => $this->verdict->imbalanceSide(),
            ]);
        }

        return $problem->toResponse($request);
    }
}
