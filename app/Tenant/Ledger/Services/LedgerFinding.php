<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

/**
 * One thing `ledger:verify` found - PF-054.
 *
 * `consequence` is not decoration. A finding that says "entry JE-7 is out by
 * 0.01" leaves whoever reads it to judge whether that matters, at 7pm, under
 * pressure. Saying what it MEANS - a figure somebody reported on has changed
 * since they reported it - is what makes the severity obvious without them
 * having to reconstruct the reasoning.
 */
final readonly class LedgerFinding
{
    private function __construct(
        public string $severity,
        public string $kind,
        public string $detail,
        public string $consequence,
    ) {}

    public static function critical(string $kind, string $detail, string $consequence): self
    {
        return new self('critical', $kind, $detail, $consequence);
    }

    public static function warning(string $kind, string $detail, string $consequence): self
    {
        return new self('warning', $kind, $detail, $consequence);
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }
}
