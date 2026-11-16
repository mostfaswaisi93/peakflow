<?php

declare(strict_types=1);

namespace App\Support\Verification;

/**
 * The registered assertions `ledger:verify` makes - PF-163.
 *
 * A singleton the ledger consults and other modules register into, the same
 * shape as `ClosingChecks` and the posting rule registry. The list is open
 * and the thing consulting it must not change when something is added.
 */
final class LedgerChecks
{
    /** @var array<string, LedgerCheck> */
    private array $checks = [];

    public function register(LedgerCheck $check): void
    {
        $this->checks[$check->name()] = $check;
    }

    /**
     * Every finding, from every check.
     *
     * A check that throws becomes a finding of its own. Silently dropping
     * it would report a clean ledger on the strength of an assertion that
     * did not run, which is worse than the assertion not existing: nobody
     * trusts a verifier that has never found anything, and everybody
     * trusts one that just said the ledger is fine.
     *
     * @return list<array{severity: string, kind: string, detail: string, consequence: string}>
     */
    public function findings(?string $entityId): array
    {
        $findings = [];

        foreach ($this->checks as $name => $check) {
            try {
                foreach ($check->findings($entityId) as $finding) {
                    $findings[] = $finding;
                }
            } catch (\Throwable $e) {
                $findings[] = [
                    'severity' => 'critical',
                    'kind' => sprintf('the %s check', $name),
                    'detail' => sprintf('could not be computed: %s', $e->getMessage()),
                    'consequence' => 'a verification that did not run reports a clean ledger, which '.
                        'is worse than not running it at all',
                ];
            }
        }

        return $findings;
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
