<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A posting rule produced lines that do not sum to zero - PF-047.
 *
 * Raised BEFORE anything is written, so the message names the rule. The
 * database check at commit is the one that cannot be bypassed; this one
 * exists so that a developer is told which of forty rules is wrong rather
 * than which trigger fired.
 */
final class EntryDoesNotBalance extends DomainException
{
    private function __construct(string $message, public readonly string $ruleKey)
    {
        parent::__construct($message);
    }

    public static function by(string $ruleKey, Money $difference, int $lineCount): self
    {
        return new self(
            sprintf(
                'The posting rule "%s" produced %d line(s) summing to %s instead of zero. A rule that does not '.
                'balance has not decided what the transaction is worth - if this records something in another '.
                'currency, the difference belongs on an explicit exchange-difference line rather than being '.
                'left implicit.',
                $ruleKey,
                $lineCount,
                $difference->format(),
            ),
            $ruleKey,
        );
    }

    public static function producedNothing(string $ruleKey): self
    {
        return new self(
            sprintf(
                'The posting rule "%s" produced no lines at all. An event that posts nothing is either an event '.
                'that should not have reached the ledger, or a rule with a condition that is never true - and '.
                'the second is invisible until a balance is short.',
                $ruleKey,
            ),
            $ruleKey,
        );
    }

    public static function wrongCurrency(string $ruleKey, string $given, string $functional): self
    {
        return new self(
            sprintf(
                'The posting rule "%s" returned a line in %s, but this entity keeps its books in %s. A rule has '.
                'to translate before it posts: the ledger cannot choose a rate on its behalf, because which '.
                'rate applies is a property of the transaction and not of the moment it is recorded.',
                $ruleKey,
                $given,
                $functional,
            ),
            $ruleKey,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::EntryNotBalanced)
            ->withDetail($this->getMessage())
            ->withExtensions(['posting_rule' => $this->ruleKey])
            ->toResponse($request);
    }
}
