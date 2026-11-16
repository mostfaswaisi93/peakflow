<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;
use App\Tenant\Banking\Models\MatchingRule;

/**
 * Pass 6: the rules somebody wrote - PF-159.
 *
 * ## It scores highest because it is not a guess
 *
 * A rule is a person saying "a debit from Riyadh Utilities is an electricity
 * charge, post it to 5-2-1". The engine is not inferring anything; it is
 * doing what it was told. 0.95 rather than 1.00 only because a rule can be
 * written too broadly and the score should leave room to say so.
 *
 * ## It is the only pass for the traffic that has no documents
 *
 * Bank fees, interest, standing charges, payroll, transfers between our own
 * accounts. None of them has an invoice and none of them ever will, so every
 * document pass fails on them - and without this pass they would land in
 * suspense every month, for ever, in the same amounts.
 *
 * ## First match wins, and the order is explicit
 *
 * By `priority`, not by creation date: a rule added later is routinely meant
 * to run before an existing one, and "move it up" has to be possible without
 * recreating everything below it. The first rule whose conditions all hold
 * produces the candidate and the rest are not consulted - which is what
 * makes a rule set readable, and what makes the order matter.
 *
 * ## Versioned, so a past match stays explainable
 *
 * The candidate carries the rule's **version** id. A rule edited next March
 * must not change what it says about a match it made today, and a proposal
 * pointing at a mutable row would do exactly that.
 */
final class RuleEnginePass implements MatchingPass
{
    public function key(): string
    {
        return 'rule_engine';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        return true;
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $rules = MatchingRule::query()
            ->where('entity_id', $context->entityId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('rule_key')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $context)) {
                continue;
            }

            return [$this->candidate($rule, $context)];
        }

        return [];
    }

    /**
     * Whether every condition a rule states holds.
     *
     * Every one it states - a condition left blank is not a condition, and
     * a rule with none at all is refused by the schema rather than being
     * allowed to claim every line in the account.
     */
    public function matches(MatchingRule $rule, MatchingContext $context): bool
    {
        $transaction = $context->transaction;
        $amount = $context->amount;

        if ($rule->direction !== null) {
            $isDebit = $amount->amount->isNegative();

            if (($rule->direction === 'debit') !== $isDebit) {
                return false;
            }
        }

        foreach ([
            'code_domain' => $transaction->code_domain,
            'code_family' => $transaction->code_family,
            'code_subfamily' => $transaction->code_subfamily,
            'bank_code_proprietary' => $transaction->bank_code_proprietary,
        ] as $field => $value) {
            if ($rule->{$field} !== null && strcasecmp((string) $rule->{$field}, (string) $value) !== 0) {
                return false;
            }
        }

        if ($rule->bank_account_id !== null
            && (string) $rule->bank_account_id !== (string) $transaction->bank_account_id) {
            return false;
        }

        if ($rule->counterparty_account !== null
            && $this->squashed((string) $rule->counterparty_account)
                !== $this->squashed((string) $transaction->counterparty_account)) {
            return false;
        }

        if ($rule->counterparty_pattern !== null
            && ! $this->contains((string) $transaction->counterparty_name, (string) $rule->counterparty_pattern)) {
            return false;
        }

        if ($rule->remittance_pattern !== null
            && ! $this->contains((string) $transaction->remittance_unstructured, (string) $rule->remittance_pattern)) {
            return false;
        }

        /*
         | Amount bounds are on the magnitude.
         |
         | A rule for "bank charges between 10 and 100" is written with
         | positive numbers by anybody who has ever written one, and
         | comparing a signed debit against them would match nothing.
         */
        $magnitude = $amount->abs()->amount;

        if ($rule->amount_min !== null && $magnitude->isLessThan($rule->amount_min)) {
            return false;
        }

        if ($rule->amount_max !== null && $magnitude->isGreaterThan($rule->amount_max)) {
            return false;
        }

        return true;
    }

    private function candidate(MatchingRule $rule, MatchingContext $context): Candidate
    {
        $conditions = $rule->statedConditions();

        $confidence = Confidence::for($this->key())
            ->when(\count($conditions) === 1, 'rule_is_broad', -0.10,
                'the rule tests one thing, so it will claim more than it was written for')
            ->when(\count($conditions) >= 3, 'rule_is_specific', 0.03,
                sprintf('the rule tests %d things, so it is unlikely to claim a line it was not written for', \count($conditions)));

        return new Candidate(
            pass: $this->key(),
            matchedType: (string) $rule->matched_type,
            matchedId: $rule->matched_type === 'journal_entry' ? null : (string) $rule->party_id,
            label: (string) $rule->name,
            amount: $context->amount,
            confidence: $confidence->score(),
            reason: sprintf(
                'The rule "%s" (version %d) matched: %s. %s',
                (string) $rule->name,
                (int) $rule->version,
                implode(', ', $conditions),
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
            partyId: $rule->party_id === null ? null : (string) $rule->party_id,
            ruleId: (string) $rule->getKey(),
        );
    }

    /**
     * A pattern match that is not a regular expression.
     *
     * Deliberately: a tenant writing a rule is not writing code, and a
     * malformed expression from a form field is either an error they cannot
     * act on or a catastrophic backtrack on somebody else's statement. A
     * case-insensitive substring is what people mean by "contains", and
     * `*` is offered because they will reach for it.
     */
    private function contains(string $haystack, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $expression = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/iu';

            return preg_match($expression, $haystack) === 1;
        }

        return mb_stripos($haystack, $pattern) !== false;
    }

    private function squashed(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));
    }
}
