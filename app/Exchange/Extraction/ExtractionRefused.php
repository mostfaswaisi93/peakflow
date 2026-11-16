<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

/**
 * An operation on a reading the pipeline will not perform - PF-142.
 */
final class ExtractionRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
