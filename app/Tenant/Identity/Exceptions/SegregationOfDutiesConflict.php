<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Models\SodRule;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: the same person already performed the conflicting duty - PF-033.
 *
 * ## The message names what, when, and who to ask
 *
 * A refusal that says "segregation of duties violation" teaches the user
 * nothing except that the software is in their way, and the next thing they do
 * is look for the setting that turns it off. So the message says which duty
 * they already performed, when they performed it, and - if the rule permits an
 * exception at all - that a second person can approve one.
 *
 * For the two rules that are never overridable it says so plainly instead of
 * implying a path that does not exist.
 */
final class SegregationOfDutiesConflict extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $ruleKey,
        public readonly string $attemptedDuty,
        public readonly string $conflictingDuty,
        public readonly bool $overridable,
        public readonly ?string $conflictingAuditId = null,
    ) {
        parent::__construct($message);
    }

    public static function between(
        SodRule $rule,
        string $attemptedDuty,
        string $conflictingDuty,
        ?Carbon $performedAt,
        ?string $subjectLabel,
        ?string $conflictingAuditId,
    ): self {
        $when = $performedAt === null ? 'earlier' : 'on '.$performedAt->toDayDateTimeString();
        $what = $subjectLabel === null ? '' : sprintf(' on %s', $subjectLabel);

        $remedy = $rule->overridable
            ? 'Another user holding this permission can perform it, or an administrator can approve a recorded, '.
              'expiring exception. An exception cannot be approved by you.'
            : 'This rule cannot be overridden by anyone, including an administrator. The control requires a second '.
              'person, and no configuration makes one appear.';

        return new self(
            trim(sprintf(
                'You cannot %s%s, because you performed "%s"%s %s. %s %s',
                $attemptedDuty,
                $what,
                $conflictingDuty,
                $what,
                $when,
                $rule->rationale(),
                $remedy,
            )),
            $rule->key,
            $attemptedDuty,
            $conflictingDuty,
            $rule->overridable,
            $conflictingAuditId,
        );
    }

    public static function approverIsBeneficiary(SodRule $rule): self
    {
        return new self(
            'An exception to a segregation-of-duties rule cannot be approved by the person it applies to. '.
            'A control a user can switch off for themselves is not a control.',
            $rule->key,
            'sod.override.approve',
            'sod.override.request',
            $rule->overridable,
        );
    }

    public static function notOverridable(SodRule $rule): self
    {
        return new self(
            sprintf(
                'The rule "%s" cannot be overridden. %s',
                $rule->key,
                $rule->rationale() !== ''
                    ? $rule->rationale()
                    : 'It requires a second person, and no configuration makes one appear.',
            ),
            $rule->key,
            'sod.override.request',
            $rule->duty_a,
            false,
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::SegregationOfDutiesConflict)
            ->withDetail($this->getMessage())
            ->withExtensions([
                'rule' => $this->ruleKey,
                'attempted_duty' => $this->attemptedDuty,
                'conflicting_duty' => $this->conflictingDuty,
                'overridable' => $this->overridable,
                'conflicting_audit_id' => $this->conflictingAuditId,
            ])
            ->toResponse($request);
    }
}
