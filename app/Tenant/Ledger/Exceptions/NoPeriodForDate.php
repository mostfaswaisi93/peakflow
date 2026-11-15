<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DateTimeInterface;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An accounting date that belongs to no defined period.
 *
 * Refused with the date named, rather than posted to nothing. An entry with
 * no period is invisible to every report that bounds by period, which is all
 * of them - so it would not appear in the trial balance, would not appear in
 * the VAT return, and would still be in the table.
 */
final class NoPeriodForDate extends DomainException
{
    public static function on(DateTimeInterface $date): self
    {
        return new self(sprintf(
            'No accounting period covers %s in this entity. Either the fiscal year has not been created yet, '.
            'or the date falls outside every year that has. Posting it anyway would make the entry invisible '.
            'in every report that bounds by period, while leaving it in the ledger.',
            $date->format('Y-m-d'),
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::PeriodNotAcceptingPostings)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
