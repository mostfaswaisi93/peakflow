<?php

declare(strict_types=1);

namespace App\Platform\ValueObjects;

/**
 * One unsafe thing a migration would do, and what to do instead.
 *
 * `instead` is not decoration. A warning that says only "this is unsafe"
 * leaves the person reading it with two options - ignore it, or guess - and
 * the flag that silences it is right there in the help output.
 */
final readonly class MigrationHazard
{
    public function __construct(
        public string $file,
        public int $line,
        public string $name,
        public string $statement,
        public string $does,
        public string $instead,
        public ?string $reviewedBecause = null,
    ) {}

    public function isReviewed(): bool
    {
        return $this->reviewedBecause !== null;
    }

    public function location(): string
    {
        return sprintf('%s:%d', $this->file, $this->line);
    }
}
