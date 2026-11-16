<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

/**
 * No engine is installed - PF-142.
 *
 * A distinct exception rather than an empty result, because the two mean
 * different things to whoever looks at the queue: "nothing could be read
 * from this document" is a problem with the document, and "no engine is
 * installed" is a problem with the deployment. A pipeline that reported the
 * first for the second would have somebody re-scanning invoices that were
 * perfectly legible.
 */
final class OpticalReaderUnavailable extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
