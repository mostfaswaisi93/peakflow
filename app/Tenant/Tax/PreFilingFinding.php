<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use JsonSerializable;

/**
 * One item on the pre-filing checklist - PF-086.
 *
 * ## `blocking` and `pending` are different things
 *
 * A **blocking** finding is something wrong with this period that somebody has
 * to deal with before the return can be filed.
 *
 * A **pending** finding is a check this system cannot run yet because the
 * thing it inspects does not exist - unposted documents before there are
 * documents, unmatched bank lines before there is a bank feed. It is listed
 * anyway, and that is the whole point: a checklist that silently omits a check
 * tells the person filing that everything was looked at. One that says "this
 * was not checked, and here is why" lets them decide.
 */
final readonly class PreFilingFinding implements JsonSerializable
{
    private function __construct(
        public string $check,
        public string $status,
        public string $detail,
        public int $count = 0,
    ) {}

    /** Something is wrong with this period. */
    public static function blocking(string $check, string $detail, int $count = 0): self
    {
        return new self($check, 'blocking', $detail, $count);
    }

    /** Checked, and there is nothing to report. */
    public static function clear(string $check, string $detail = ''): self
    {
        return new self($check, 'clear', $detail);
    }

    /** Not checked, because what it inspects does not exist yet. */
    public static function pending(string $check, string $detail): self
    {
        return new self($check, 'pending', $detail);
    }

    public function isBlocking(): bool
    {
        return $this->status === 'blocking';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'check' => $this->check,
            'status' => $this->status,
            'detail' => $this->detail,
            'count' => $this->count,
        ];
    }
}
