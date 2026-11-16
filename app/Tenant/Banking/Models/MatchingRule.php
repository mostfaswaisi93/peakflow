<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A rule somebody wrote, at one version - PF-159.
 *
 * ## Never updated in place
 *
 * A rule edited today must not change what it says about a match it made
 * last March. So an edit writes a new row with the same `rule_key` and the
 * next `version`, and the old one is marked superseded - which means a
 * proposal pointing at a rule id points at the exact text that produced it,
 * for ever.
 *
 * @property string $rule_key
 * @property int $version
 * @property int $priority
 */
final class MatchingRule extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'matching_rules';

    protected $fillable = [
        'id', 'entity_id', 'rule_key', 'version', 'name', 'priority',
        'direction', 'counterparty_pattern', 'remittance_pattern', 'counterparty_account',
        'code_domain', 'code_family', 'code_subfamily', 'bank_code_proprietary',
        'amount_min', 'amount_max', 'bank_account_id',
        'gl_account_id', 'party_id', 'dimensions', 'matched_type',
        'is_active', 'superseded_by_id', 'superseded_at', 'created_by',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'priority' => 'integer',
        'superseded_at' => 'immutable_datetime',
    ];

    /**
     * The conditions this rule actually states, in words.
     *
     * Shown in the proposal's reason. "The rule matched" is not an
     * explanation; "the rule matched: counterparty contains Riyadh
     * Utilities, amount between 10 and 500" is one somebody can check
     * against the line in front of them.
     *
     * @return list<string>
     */
    public function statedConditions(): array
    {
        $conditions = [];

        if ($this->direction !== null) {
            $conditions[] = sprintf('the transaction is a %s', (string) $this->direction);
        }

        foreach ([
            'counterparty_pattern' => 'the counterparty contains "%s"',
            'remittance_pattern' => 'the remittance contains "%s"',
            'counterparty_account' => 'the counterparty account is %s',
            'bank_code_proprietary' => 'the bank\'s own code is %s',
        ] as $field => $sentence) {
            if ($this->{$field} !== null) {
                $conditions[] = sprintf($sentence, (string) $this->{$field});
            }
        }

        $code = implode('/', array_filter([$this->code_domain, $this->code_family, $this->code_subfamily]));

        if ($code !== '') {
            $conditions[] = sprintf('the transaction code is %s', $code);
        }

        if ($this->amount_min !== null && $this->amount_max !== null) {
            $conditions[] = sprintf('the amount is between %s and %s', (string) $this->amount_min, (string) $this->amount_max);
        } elseif ($this->amount_min !== null) {
            $conditions[] = sprintf('the amount is at least %s', (string) $this->amount_min);
        } elseif ($this->amount_max !== null) {
            $conditions[] = sprintf('the amount is at most %s', (string) $this->amount_max);
        }

        if ($this->bank_account_id !== null) {
            $conditions[] = 'it is on this bank account';
        }

        return $conditions;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }
}
