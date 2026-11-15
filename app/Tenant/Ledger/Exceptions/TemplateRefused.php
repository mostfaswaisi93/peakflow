<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/**
 * Why a journal template was not saved or prepared - PF-055.
 *
 * Each message names the template, because somebody with twenty of them is
 * looking at a list and needs to know which row to open.
 */
final class TemplateRefused extends RuntimeException
{
    public static function needsTwoLines(string $key, int $lines): self
    {
        return new self(sprintf(
            'Template "%s" has %d line(s). An entry needs at least two, and a template that produces one '.
            'produces an entry the balanced-entry constraint refuses - naming the entry rather than the '.
            'template that caused it.',
            $key,
            $lines,
        ));
    }

    public static function sharesDoNotCancel(string $key, string $total): self
    {
        return new self(sprintf(
            'The shares in template "%s" sum to %s rather than zero. Every entry made from it would be '.
            'out by that proportion of its total, every month, and the refusal would arrive at the '.
            'posting with nothing to say which template was wrong.',
            $key,
            $total,
        ));
    }

    public static function shareIsZero(string $key, string $code): self
    {
        return new self(sprintf(
            'The line for account %s in template "%s" has a share of zero, so every entry made from it '.
            'would carry a zero line - which records nothing and which the ledger refuses.',
            $code,
            $key,
        ));
    }

    public static function unknownAccount(string $key, string $code): self
    {
        return new self(sprintf(
            'Template "%s" names account %s, which does not exist in this entity. A template is written '.
            'once and used every month, so the refusal belongs here rather than on the first run.',
            $key,
            $code,
        ));
    }

    public static function accountIsAHeading(string $key, string $code): self
    {
        return new self(sprintf(
            'Template "%s" names account %s, which is a group heading rather than a posting account. A '.
            'balance on a heading is explained by none of its children and lost by every drill-down.',
            $key,
            $code,
        ));
    }

    public static function accountIsAControl(string $key, string $code): self
    {
        return new self(sprintf(
            'Template "%s" names account %s, which is a control account: its balance is the total of a '.
            'subledger and the documents post to it. A monthly entry into it would make the two disagree '.
            'a little more every month, and nothing afterwards could say which is right.',
            $key,
            $code,
        ));
    }

    public static function unknownRecurrence(string $key, string $recurrence): self
    {
        return new self(sprintf(
            'Template "%s" asks to recur "%s". The schedules are monthly, quarterly and annually - a '.
            'schedule nothing understands is a template that never prepares anything and says nothing '.
            'about it.',
            $key,
            $recurrence,
        ));
    }

    public static function recurrenceNeedsADate(string $key): self
    {
        return new self(sprintf(
            'Template "%s" recurs and names no first run date. "Recurring from nothing" is a template '.
            'that silently never runs.',
            $key,
        ));
    }

    public static function unknownTemplate(string $key): self
    {
        return new self(sprintf('There is no template "%s" in this entity.', $key));
    }

    public static function inactive(string $key): self
    {
        return new self(sprintf(
            'Template "%s" is inactive. It is kept rather than deleted so the entries made from it can '.
            'still be explained, and preparing a new entry from it is the one thing it may not do.',
            $key,
        ));
    }
}
