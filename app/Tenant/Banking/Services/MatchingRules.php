<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Banking\Matching\MatchingContext;
use App\Tenant\Banking\Matching\OpenDocuments;
use App\Tenant\Banking\Matching\PartyIdentifiers;
use App\Tenant\Banking\Matching\Passes\RuleEnginePass;
use App\Tenant\Banking\Matching\ReferenceExtraction;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Models\MatchingRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writing and changing matching rules - PF-159.
 *
 * ## The tester runs before the rule is saved, not after
 *
 * A rule is a standing instruction. Written too broadly it does not fail -
 * it quietly claims every receipt in the account from the day it is saved,
 * and the first sign is a month that will not reconcile.
 *
 * So {@see test()} answers "which of the transactions you already have would
 * this have matched" **before** anything is stored. That turns a rule from a
 * guess into something with evidence, and the evidence is the transactions
 * themselves rather than a count.
 *
 * ## An edit is a new version, never an update
 *
 * A rule edited today must not change what it says about a match it made
 * last March. So `change()` writes a new row with the same `rule_key` and
 * the next version, marks the old one superseded, and leaves every proposal
 * pointing at the text that actually produced it.
 *
 * That also means a rule can be read back exactly as it was: "the rule said
 * X then and says Y now" is answerable, and with an in-place update it is
 * not.
 */
final class MatchingRules
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly RuleEnginePass $pass = new RuleEnginePass(),
        private readonly OpenDocuments $documents = new OpenDocuments(),
        private readonly ReferenceExtraction $references = new ReferenceExtraction(),
        private readonly PartyIdentifiers $parties = new PartyIdentifiers(),
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws MatchRefused
     */
    public function write(
        string $entityId,
        string $name,
        array $definition,
        string $userId,
        int $priority = 100,
    ): MatchingRule {
        $this->assertItProducesSomething($definition);

        $rule = MatchingRule::create([
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'rule_key' => (string) Str::ulid(),
            'version' => 1,
            'name' => trim($name),
            'priority' => $priority,
            'created_by' => $userId,
            ...$this->fields($definition),
        ]);

        $this->audit->record(
            category: 'banking',
            action: 'matching_rule.written',
            subjectType: 'matching_rule',
            subjectId: (string) $rule->getKey(),
            subjectLabel: trim($name),
            after: ['version' => 1, 'priority' => $priority, 'conditions' => $rule->statedConditions()],
            entityId: $entityId,
        );

        return $rule->refresh();
    }

    /**
     * Change a rule, which writes a new version.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws MatchRefused
     */
    public function change(
        MatchingRule $rule,
        array $definition,
        string $userId,
        ?string $name = null,
        ?int $priority = null,
    ): MatchingRule {
        if ($rule->isSuperseded()) {
            throw MatchRefused::because(sprintf(
                'Version %d of "%s" was already superseded. Change the current version, or the '.
                'history stops being a line and becomes a tree nobody can read.',
                (int) $rule->version,
                (string) $rule->name,
            ));
        }

        $this->assertItProducesSomething($definition);

        return DB::connection('tenant')->transaction(function () use ($rule, $definition, $userId, $name, $priority): MatchingRule {
            $next = MatchingRule::create([
                'id' => (string) Str::ulid(),
                'entity_id' => (string) $rule->entity_id,
                'rule_key' => (string) $rule->rule_key,
                'version' => (int) $rule->version + 1,
                'name' => $name === null ? (string) $rule->name : trim($name),
                'priority' => $priority ?? (int) $rule->priority,
                'created_by' => $userId,
                ...$this->fields($definition),
            ]);

            $rule->update([
                'is_active' => false,
                'superseded_by_id' => (string) $next->getKey(),
                'superseded_at' => now(),
            ]);

            $this->audit->record(
                category: 'banking',
                action: 'matching_rule.changed',
                subjectType: 'matching_rule',
                subjectId: (string) $next->getKey(),
                subjectLabel: (string) $next->name,
                before: ['version' => (int) $rule->version, 'conditions' => $rule->statedConditions()],
                after: ['version' => (int) $next->version, 'conditions' => $next->statedConditions()],
                entityId: (string) $rule->entity_id,
            );

            return $next->refresh();
        });
    }

    public function retire(MatchingRule $rule, string $userId, string $reason): MatchingRule
    {
        if (mb_strlen(trim($reason)) <= 5) {
            throw MatchRefused::because(
                'Retiring a rule needs a reason. A rule that stopped firing looks identical to one '.
                'somebody deleted, and the difference is what somebody is trying to establish when '.
                'they ask why last month matched differently.',
            );
        }

        $rule->update(['is_active' => false]);

        $this->audit->record(
            category: 'banking',
            action: 'matching_rule.retired',
            subjectType: 'matching_rule',
            subjectId: (string) $rule->getKey(),
            subjectLabel: (string) $rule->name,
            reason: trim($reason),
            entityId: (string) $rule->entity_id,
        );

        return $rule->refresh();
    }

    /**
     * Which transactions this rule would have matched - PF-159.
     *
     * Run **before** a rule is saved, against the transactions already
     * imported. A rule written too broadly does not fail: it quietly claims
     * every receipt in the account from the day it is saved, and the first
     * sign is a month that will not reconcile.
     *
     * The matched transactions are returned rather than counted. "This
     * matches 412 transactions" is a number somebody argues with; the first
     * twenty of them, with their amounts and counterparties, is a rule
     * somebody rewrites.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function test(
        string $entityId,
        array $definition,
        int $overDays = 180,
        int $show = 20,
    ): array {
        $draft = new MatchingRule([
            'entity_id' => $entityId,
            'name' => 'draft',
            ...$this->fields($definition),
        ]);

        $since = now()->subDays($overDays)->toDateString();

        $transactions = BankTransaction::query()
            ->where('entity_id', $entityId)
            ->where('booking_date', '>=', $since)
            ->orderByDesc('booking_date')
            ->orderBy('id')
            ->get();

        /*
         | How many are outside the window, for when it finds nothing.
         |
         | "No transactions to test against" reads as "you have no data",
         | and somebody concludes the rule cannot be tested. If there are
         | four hundred of them a year old, the answer is to widen the
         | window - and only this number tells them that.
         */
        $outside = $transactions->isEmpty()
            ? BankTransaction::query()->where('entity_id', $entityId)->count()
            : 0;

        $matched = [];
        $alreadyMatched = 0;

        foreach ($transactions as $transaction) {
            $context = new MatchingContext(
                transaction: $transaction,
                entityId: $entityId,
                amount: Money::of((string) $transaction->amount, (string) $transaction->currency),
                openDocuments: $this->documents,
                references: $this->references,
                parties: $this->parties,
            );

            if (! $this->pass->matches($draft, $context)) {
                continue;
            }

            /*
             | A transaction already matched to something else is the
             | interesting case.
             |
             | It means the rule would have claimed a line that turned out
             | to be a document - which is the specific way a rule written
             | for fees comes to swallow receipts, and the one a count
             | would hide.
             */
            $settled = DB::connection('tenant')->table('matches')
                ->where('bank_transaction_id', (string) $transaction->getKey())
                ->whereNull('reversed_at')
                ->exists();

            if ($settled) {
                $alreadyMatched++;
            }

            $matched[] = [
                'id' => (string) $transaction->getKey(),
                'booking_date' => $transaction->booking_date->toDateString(),
                'amount' => (string) $transaction->amount,
                'currency' => (string) $transaction->currency,
                'counterparty_name' => $transaction->counterparty_name,
                'remittance' => $transaction->remittance_unstructured,
                'already_matched' => $settled,
            ];
        }

        $total = \count($matched);

        return [
            'considered' => $transactions->count(),
            'over_days' => $overDays,
            'matched' => $total,
            'already_matched_to_something_else' => $alreadyMatched,
            'examples' => \array_slice($matched, 0, $show),
            'conditions' => $draft->statedConditions(),
            'outside_the_window' => $outside,
            'why' => $this->verdict($transactions->count(), $total, $alreadyMatched, $overDays, $outside),
        ];
    }

    private function verdict(
        int $considered,
        int $matched,
        int $alreadyMatched,
        int $overDays,
        int $outside,
    ): string {
        if ($considered === 0) {
            return $outside === 0
                ? 'There are no transactions to test against at all, so this rule is untested '.
                  'rather than safe.'
                : sprintf(
                    'There are no transactions in the last %d days to test against, but %d exist '.
                    'outside that window. Widen it rather than concluding the rule cannot be '.
                    'tested - an untested standing instruction is the one that quietly claims '.
                    'every receipt in the account.',
                    $overDays,
                    $outside,
                );
        }

        if ($matched === 0) {
            return 'This rule would have matched nothing in the window. That may be right - a rule '.
                   'for an annual charge matches once a year - but a rule that matches nothing and '.
                   'was meant to match something is usually one condition too many.';
        }

        $share = $matched / $considered;

        if ($alreadyMatched > 0) {
            return sprintf(
                'This rule would have claimed %d transaction%s, and %d of them turned out to be '.
                'documents that were matched to something else. A rule claiming a line that is '.
                'really an invoice is how a receipt stops reaching the customer\'s account, so '.
                'look at those before saving it.',
                $matched,
                $matched === 1 ? '' : 's',
                $alreadyMatched,
            );
        }

        if ($share > 0.25) {
            return sprintf(
                'This rule would have matched %d of %d transactions - %d%% of everything in the '.
                'window. A rule meant for one kind of traffic rarely covers a quarter of an '.
                'account, so check the examples before saving it.',
                $matched,
                $considered,
                (int) round($share * 100),
            );
        }

        return sprintf(
            'This rule would have matched %d of %d transactions in the window. The examples are '.
            'the evidence: a count is a number somebody argues with, and the transactions '.
            'themselves are a rule somebody rewrites.',
            $matched,
            $considered,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function fields(array $definition): array
    {
        $allowed = [
            'direction', 'counterparty_pattern', 'remittance_pattern', 'counterparty_account',
            'code_domain', 'code_family', 'code_subfamily', 'bank_code_proprietary',
            'amount_min', 'amount_max', 'bank_account_id',
            'gl_account_id', 'party_id', 'dimensions', 'matched_type',
        ];

        $fields = [];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $definition)) {
                $fields[$field] = $definition[$field];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws MatchRefused
     */
    private function assertItProducesSomething(array $definition): void
    {
        if (($definition['gl_account_id'] ?? null) !== null || ($definition['party_id'] ?? null) !== null) {
            return;
        }

        throw MatchRefused::because(
            'A rule has to produce something - a GL account, a party, or both. A rule that '.
            'identifies a transaction and says nothing about what to do with it has moved the work '.
            'rather than done it, and the transaction still lands in suspense.',
        );
    }
}
