<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Charts\Exceptions;

use RuntimeException;

/**
 * A shipped chart template is not coherent - PF-039.
 *
 * Thrown at load, not at use. A template with a parent that does not exist or
 * a role assigned twice produces a chart that is wrong in a way the tenant
 * discovers on their first posting, weeks after provisioning, with no way to
 * tell that the template was at fault.
 *
 * Every message names the template and the row, because the person reading it
 * is editing a data file and needs the line.
 */
final class InvalidChartTemplate extends RuntimeException
{
    public static function because(string $template, string $reason): self
    {
        return new self(sprintf('The chart template "%s" is not usable: %s', $template, $reason));
    }

    public static function unreadable(string $path, string $reason): self
    {
        return new self(sprintf('The chart template at %s could not be read: %s', $path, $reason));
    }
}
