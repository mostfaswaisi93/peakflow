<?php

declare(strict_types=1);

namespace App\Support\Attachments;

/**
 * What a scanner concluded - PF-073.
 *
 * `failed` is not `clean`. A scanner that could not run has told you
 * nothing, and treating "nothing" as "fine" is the single decision that
 * makes a virus scan decorative.
 */
final readonly class ScanVerdict
{
    private function __construct(
        public string $result,
        public string $scanner,
        public ?string $detail = null,
    ) {}

    public static function clean(string $scanner): self
    {
        return new self('clean', $scanner);
    }

    public static function infected(string $scanner, string $detail): self
    {
        return new self('infected', $scanner, $detail);
    }

    /** The scanner could not reach a verdict, which is not a clean one. */
    public static function failed(string $scanner, string $detail): self
    {
        return new self('failed', $scanner, $detail);
    }

    public function isClean(): bool
    {
        return $this->result === 'clean';
    }
}
