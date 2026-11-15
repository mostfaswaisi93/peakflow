<?php

declare(strict_types=1);

namespace App\Support\Closing;

/**
 * The registered reasons a period might not close - PF-088.
 *
 * A singleton the ledger consults and other modules register into, so the
 * ledger never learns what a tax return is. The same shape as the posting rule
 * registry, for the same reason: the list is open, and the thing consulting it
 * must not have to change when something is added.
 */
final class ClosingChecks
{
    /** @var array<string, ClosingCheck> */
    private array $checks = [];

    public function register(ClosingCheck $check): void
    {
        $this->checks[$check->name()] = $check;
    }

    /**
     * Every reason, from every check, in registration order.
     *
     * A check that throws is reported rather than allowed to stop the close
     * silently or to let it through: "the tax reconciliation could not be
     * computed" is itself a reason not to close, because the alternative is
     * closing a period whose reconciliation nobody has seen.
     *
     * @return list<string>
     */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
    {
        $reasons = [];

        foreach ($this->checks as $name => $check) {
            try {
                foreach ($check->reasonsToRefuse($entityId, $label, $from, $until) as $reason) {
                    $reasons[] = $reason;
                }
            } catch (\Throwable $e) {
                $reasons[] = sprintf(
                    'the %s check could not be computed (%s), and a period whose checks nobody has seen '.
                    'must not close',
                    $name,
                    $e->getMessage(),
                );
            }
        }

        return $reasons;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->checks);
    }

    public function has(string $name): bool
    {
        return isset($this->checks[$name]);
    }
}
