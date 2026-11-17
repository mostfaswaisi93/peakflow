<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Exceptions;

/**
 * A stock movement the product will not record - PF-247, PF-248.
 *
 * Carries a sentence rather than a code, because the reader is somebody
 * holding a delivery note or a till receipt and the next thing they do
 * depends on which refusal this is.
 */
final class StockRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }
}
