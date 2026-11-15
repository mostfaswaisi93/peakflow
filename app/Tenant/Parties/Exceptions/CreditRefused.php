<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Exceptions;

use RuntimeException;

/**
 * A commitment the credit controls will not allow - PF-062.
 *
 * ## The message is read by a salesperson with a customer on the phone
 *
 * Which shapes all of it. "Credit check failed" starts an internal hunt;
 * "Gulf Retail would be 4,200 SAR over their 50,000 limit - 46,000 open
 * receivables plus 8,200 uninvoiced orders" lets them say something true to
 * the customer immediately, and decide whether to ask for an override.
 *
 * The binding reason comes first. A hold and a limit breach send the reader
 * to different people.
 */
final class CreditRefused extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    /**
     * An override with a one-word justification.
     *
     * Refused rather than accepted and reviewed later, because the review
     * happens when something has gone wrong and the only artefact is what
     * somebody typed in a hurry. Twenty characters is not a high bar - it is
     * enough to stop "ok" and "approved".
     */
    public static function justificationTooThin(): self
    {
        return new self(
            'A credit override needs a justification somebody can read later. This is the moment a control '.
            'is being set aside, and the only thing that makes it reviewable afterwards is what you write '.
            'here - "approved" is not it. Say who agreed and why.',
        );
    }

    public static function overrideExpiresInThePast(string $when): self
    {
        return new self(sprintf(
            'An override expiring at %s has already expired, so it would cover nothing - and it would '.
            'present as "the override did not work" rather than as a date in the past.',
            $when,
        ));
    }

    /**
     * An override with no author.
     *
     * The whole value of an override is that somebody is accountable for it.
     * One granted by a console command or an unauthenticated request is a
     * limit raised by nobody, which is indistinguishable from a limit that
     * was never there.
     */
    public static function overrideNeedsAnAuthor(): self
    {
        return new self(
            'A credit override cannot be granted without an authenticated user. Its whole value is that '.
            'somebody is accountable for setting the limit aside; one granted by nobody is a limit that was '.
            'never there.',
        );
    }
}
