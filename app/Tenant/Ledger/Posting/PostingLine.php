<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;

/**
 * One line a posting rule asks for - PF-047.
 *
 * ## It names a ROLE or an account, never both
 *
 * A rule that posts a receivable says `ControlRole::AccountsReceivable`, not
 * an account id. The rule does not know - and must not know - which account
 * this particular entity uses for receivables, or it would have to be
 * rewritten for every customer who renumbers their chart.
 *
 * A rule that posts revenue names an account directly, because which revenue
 * account an invoice line hits is a property of the line, not of the entity.
 *
 * ## The amount is Money, and it is signed
 *
 * Positive is a debit. The sign lives in the amount rather than in a separate
 * `side` field so that summing the lines is the balance check - with a side
 * field, "debit -100" is representable and means the same as "credit 100",
 * and the two will eventually both appear in the same rule.
 */
final readonly class PostingLine
{
    private function __construct(
        public Money $amount,
        public ?ControlRole $role,
        public ?string $accountId,
        public ?string $partyId = null,
        public ?string $narration = null,
        public ?string $taxCodeId = null,
        /** @var array<int, string|null> dimension axis (1-8) => value id */
        public array $dimensions = [],
    ) {}

    /**
     * A line against a control account, resolved per entity at posting time.
     */
    public static function toRole(
        ControlRole $role,
        Money $amount,
        ?string $partyId = null,
        ?string $narration = null,
    ): self {
        return new self($amount, $role, null, $partyId, $narration);
    }

    /**
     * A line against a specific account the caller already knows.
     *
     * @param  array<int, string|null>  $dimensions
     */
    public static function toAccount(
        string $accountId,
        Money $amount,
        ?string $partyId = null,
        ?string $narration = null,
        ?string $taxCodeId = null,
        array $dimensions = [],
    ): self {
        return new self($amount, null, $accountId, $partyId, $narration, $taxCodeId, $dimensions);
    }

    public function isDebit(): bool
    {
        return $this->amount->isPositive();
    }

    /** A human description, for the rule-inspection screen. */
    public function describe(): string
    {
        $target = $this->role?->value ?? ('account '.$this->accountId);

        return sprintf('%s %s', $this->isDebit() ? 'Dr' : 'Cr', $target);
    }
}
