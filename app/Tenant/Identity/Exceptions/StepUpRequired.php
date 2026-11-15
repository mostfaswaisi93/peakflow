<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user holds the permission but has not proved they are present - PF-032.
 *
 * Carefully NOT a 403. The user is allowed to do this; they just have to
 * confirm it is them. Answering "forbidden" would send a finance manager to
 * their administrator to ask for a permission they already have.
 */
final class StepUpRequired extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $permission,
        public readonly ?string $subjectId,
        public readonly bool $needsEnrolment,
    ) {
        parent::__construct($message);
    }

    public static function forAction(string $permission, ?string $subjectId, string $label): self
    {
        return new self(
            sprintf(
                /*
                 | "before continuing with %s", not "before you %s".
                 |
                 | The label is a NOUN - RequiresStepUp documents it as what a
                 | person would call the subject, "2026-03" rather than a ULID
                 | - and the old wording read "Confirm it is you before you
                 | 2026-03". A prompt that reads as a mistake is a prompt
                 | people click past.
                 */
                'Confirm it is you before continuing with %s. This step is required for this action '.
                'specifically, and the confirmation applies only to it - approving one payment does not '.
                'silently approve the next.',
                $label,
            ),
            $permission,
            $subjectId,
            false,
        );
    }

    public static function notEnrolled(string $permission, string $label): self
    {
        return new self(
            sprintf(
                'Set up a second factor before continuing with %s. This action can move money, so a '.
                'password alone is not enough to authorise it - not because of your role, which already '.
                'permits it, but because a password is the one thing an attacker can obtain without '.
                'touching your device.',
                $label,
            ),
            $permission,
            null,
            true,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(
            $this->needsEnrolment ? ProblemType::MfaEnrolmentRequired : ProblemType::StepUpRequired,
        )
            ->withDetail($this->getMessage())
            ->withExtensions([
                'permission' => $this->permission,
                'subject_id' => $this->subjectId,
                'needs_enrolment' => $this->needsEnrolment,
            ])
            ->toResponse($request);
    }
}
