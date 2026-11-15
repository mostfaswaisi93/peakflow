<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use RuntimeException;
use Throwable;

final class ProvisioningFailed extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self(
            'Provisioning failed and was rolled back: '.$previous->getMessage(),
            previous: $previous,
        );
    }

    public static function unknownPlan(string $key): self
    {
        return new self(sprintf('There is no plan with the key "%s".', $key));
    }

    public static function slugTaken(string $slug): self
    {
        return new self(sprintf(
            'The slug "%s" is already in use, including by a soft-deleted tenant. Slugs are never reused, because a URL that used to point at one customer must not start pointing at another.',
            $slug,
        ));
    }

    public static function unreachable(string $detail): self
    {
        return new self(sprintf(
            'The supplied database could not be reached, so the tenant was not marked usable: %s',
            $detail,
        ));
    }
}
