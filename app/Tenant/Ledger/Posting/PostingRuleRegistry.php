<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting;

use App\Tenant\Ledger\Exceptions\PostingRuleNotRegistered;
use Illuminate\Support\Collection;

/**
 * Where modules register how their documents post - PF-047.
 *
 * ## Resolution is by key, and the key is a string on purpose
 *
 * `Ledger` holds no reference to `Sales`, so it cannot resolve a rule by
 * class - it would have to import it. A string key means the ledger resolves
 * `sales.invoice.issued` without the class existing at compile time, which is
 * what lets Deptrac prove the ledger does not depend on sales.
 *
 * ## Registering twice is an error, not a replacement
 *
 * Two modules claiming the same key means one of them silently stops posting,
 * and the symptom is a balance that is wrong by exactly the documents the
 * loser was responsible for. The second registration throws.
 */
final class PostingRuleRegistry
{
    /** @var array<string, PostingRule> */
    private array $rules = [];

    public function register(PostingRule $rule): void
    {
        $key = $rule->key();

        if (isset($this->rules[$key]) && $this->rules[$key]::class !== $rule::class) {
            throw new \LogicException(sprintf(
                'Two posting rules claim the key "%s": %s and %s. One of them would silently stop posting, and '.
                'the symptom would be a balance wrong by exactly the documents it was responsible for.',
                $key,
                $this->rules[$key]::class,
                $rule::class,
            ));
        }

        $this->rules[$key] = $rule;
    }

    /** @throws PostingRuleNotRegistered */
    public function resolve(string $key): PostingRule
    {
        return $this->rules[$key] ?? throw PostingRuleNotRegistered::forKey($key, array_keys($this->rules));
    }

    public function has(string $key): bool
    {
        return isset($this->rules[$key]);
    }

    /**
     * Every rule, described without running any of them.
     *
     * This is the inspection screen's data source: an operator sees which
     * accounts each document type will hit during configuration, rather than
     * discovering it from a trial balance in March.
     *
     * @return Collection<string, RuleDescription>
     */
    public function describeAll(): Collection
    {
        return collect($this->rules)
            ->map(static fn (PostingRule $rule): RuleDescription => $rule->describe())
            ->sortKeys();
    }
}
