<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Recurrence;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A recurrence rule this system will not pretend to understand - PF-105.
 *
 * Every message names the part that was refused, because the person reading
 * it has to decide between rewriting the rule and asking for the part to be
 * built - and "invalid recurrence rule" supports neither decision.
 */
final class RecurrenceRefused extends DomainException
{
    public static function empty(): self
    {
        return new self('A recurring schedule needs a recurrence rule. There is no default: a '.
            'schedule that billed monthly because nobody said otherwise is a schedule nobody chose.');
    }

    public static function malformed(string $part): self
    {
        return new self(sprintf(
            '"%s" is not a NAME=VALUE pair, so it cannot be part of an RRULE. The rule is a '.
            'semicolon-separated list, for example FREQ=MONTHLY;BYMONTHDAY=-1.',
            trim($part),
        ));
    }

    /** @param list<string> $supported */
    public static function unsupportedPart(string $name, array $supported): self
    {
        return new self(sprintf(
            'RRULE part "%s" is not supported. This system implements a deliberate subset of RFC '.
            '5545 - %s - and refuses the rest by name rather than approximating it: a rule nobody '.
            'can express is a conversation, and a rule silently approximated is an invoice on the '.
            'wrong day that somebody has to find.',
            $name,
            implode(', ', $supported),
        ));
    }

    public static function unsupportedFrequency(string $frequency): self
    {
        return new self(sprintf(
            'FREQ=%s is not supported. Billing recurs daily, weekly, monthly or yearly; the '.
            'sub-daily frequencies in the specification describe calendar events rather than '.
            'invoices.',
            $frequency === '' ? '(missing)' : $frequency,
        ));
    }

    public static function countAndUntil(): self
    {
        return new self(
            'A rule carries COUNT or UNTIL, not both - RFC 5545 forbids it, because the two answer '.
            'the same question differently and nothing says which wins.',
        );
    }

    public static function aNonPositiveInterval(string $value): self
    {
        return new self(sprintf('INTERVAL=%s. An interval is how many periods apart, so it is at least 1.', $value));
    }

    public static function aNonPositiveCount(string $value): self
    {
        return new self(sprintf(
            'COUNT=%s. A schedule that produces no occurrences is one somebody meant to delete.',
            $value,
        ));
    }

    public static function aMonthOutOfRange(string $value): self
    {
        return new self(sprintf('BYMONTH=%s is not a month.', $value));
    }

    public static function aMonthDayOutOfRange(string $value): self
    {
        return new self(sprintf(
            'BYMONTHDAY=%s is not supported. Days 1 to 31 and -1 (the last day of the month) are: '.
            '"the last day" is a billing term people use, and "the second-to-last day" is not.',
            $value,
        ));
    }

    public static function anUnknownWeekday(string $token): self
    {
        return new self(sprintf(
            'BYDAY=%s is not a weekday. The days are MO TU WE TH FR SA SU, optionally with an '.
            'ordinal: 1MO is the first Monday and -1FR the last Friday.',
            $token,
        ));
    }

    public static function anOrdinalOutOfRange(string $token): self
    {
        return new self(sprintf(
            'BYDAY=%s has an ordinal outside ±5. A month has at most five of any weekday.',
            $token,
        ));
    }

    public static function anUnsupportedSetPos(string $value): self
    {
        return new self(sprintf(
            'BYSETPOS=%s is not supported. 1 and -1 are: "the first" and "the last" of what a '.
            'period produces, which is what expresses "the last working day of the quarter". The '.
            'middle positions have no billing meaning anybody has asked for.',
            $value,
        ));
    }

    public static function setPosWithNothingToSelect(): self
    {
        return new self(
            'BYSETPOS selects from the days a period produces, and this rule has no BYDAY or '.
            'BYMONTHDAY to produce any. On its own it would select the one date the frequency '.
            'already gives, which is the rule without it.',
        );
    }

    public static function dailyWithASelector(): self
    {
        return new self(
            'FREQ=DAILY with BYDAY or BYMONTHDAY is legal in the specification and means "every day, '.
            'keep the ones that match" - which is the weekly or monthly rule written confusingly. '.
            'Say FREQ=WEEKLY;BYDAY=... or FREQ=MONTHLY;BYMONTHDAY=... instead, so the rule reads as '.
            'the thing it does.',
        );
    }

    public static function anUnreadableUntil(string $value): self
    {
        return new self(sprintf(
            'UNTIL=%s cannot be read as a date. 20261231, 20261231T000000Z and 2026-12-31 are all '.
            'accepted; the time is discarded, because a billing occurrence is a date and the moment '.
            'it is generated belongs to the tenant\'s timezone.',
            $value,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::ValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
