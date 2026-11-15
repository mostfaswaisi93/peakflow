<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Ledger\Enums\PeriodState;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The period is not open.
 *
 * The message says which state it is in and what that state means, because
 * "period closed" tells a user nothing they can act on - a soft-closed period
 * still takes adjustments and a locked one never takes anything again, and
 * those are completely different conversations.
 */
final class PeriodDoesNotAcceptPostings extends DomainException
{
    private function __construct(string $message, public readonly string $period, public readonly string $state)
    {
        parent::__construct($message);
    }

    public static function in(string $period, PeriodState $state): self
    {
        $because = match ($state) {
            PeriodState::Future => 'It has not been opened yet. Opening a period is deliberate, which is what '.
                'stops December being posted into in March.',
            PeriodState::SoftClosed => 'The operational close has happened and only audit adjustments are '.
                'accepted, which need the period.post_adjusting permission.',
            PeriodState::Closed => 'It has been closed and reported on. Reopening it needs a permission, a '.
                'second-factor confirmation and a written reason, and the reopening appears on the compliance '.
                'report.',
            PeriodState::Locked => 'It has been filed with an authority. Changing it is an amendment to a '.
                'statutory return rather than an edit to a book, and there is deliberately no way to do it '.
                'from the application.',
            PeriodState::Open => 'It is open, so this refusal is itself a bug.',
        };

        return new self(
            sprintf('Period %s is %s, so it does not accept this posting. %s', $period, $state->value, $because),
            $period,
            $state->value,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::PeriodNotAcceptingPostings)
            ->withDetail($this->getMessage())
            ->withExtensions(['period' => $this->period, 'state' => $this->state])
            ->toResponse($request);
    }
}
