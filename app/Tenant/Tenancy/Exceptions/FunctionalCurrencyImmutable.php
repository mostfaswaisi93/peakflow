<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: the functional currency of an entity that has posted.
 *
 * The message explains the accounting rather than the constraint. A user who
 * is told "this field is locked" files a support ticket; a user who is told
 * that changing it is an IAS 21 re-presentation understands why nobody can
 * unlock it for them.
 */
final class FunctionalCurrencyImmutable extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $entityCode,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct($message);
    }

    public static function hasPostings(string $entityCode, string $from, string $to): self
    {
        return new self(
            sprintf(
                'Entity %s has posted transactions in %s, so its functional currency cannot be changed to %s. '.
                'Under IAS 21 a change of functional currency is applied prospectively from the date of change - '.
                'it is a re-presentation of the accounts, not a correction, and it cannot be performed by editing '.
                'this field. Create a new entity, or raise a change-of-functional-currency request.',
                $entityCode,
                $from,
                $to,
            ),
            $entityCode,
            $from,
            $to,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::PostedRecordImmutable)
            ->withDetail($this->getMessage())
            ->withExtensions([
                'entity_code' => $this->entityCode,
                'from_currency' => $this->from,
                'to_currency' => $this->to,
                'standard' => 'IAS 21.35',
            ])
            ->toResponse($request);
    }
}
