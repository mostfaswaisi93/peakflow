<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Money\Rate;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Proving the inter-entity pairs eliminate - PF-057.
 *
 * ## In the settlement currency, the pair nets to zero by construction
 *
 * One row holds one amount and both entries derive from it, so there is
 * nothing to reconcile: the lending side's receivable and the borrowing
 * side's payable are the same figure with opposite signs. That is the whole
 * reason the settlement amount lives on the transaction rather than being
 * recorded twice.
 *
 * What this service checks is therefore not "do the two numbers agree" but
 * the two things that can still go wrong:
 *
 *   1. **A balance with no transaction behind it.** Somebody reached the
 *      inter-entity account another way. A database trigger makes this
 *      impossible for anything going through the normal path, so a finding
 *      here means something bypassed it - and that is exactly the case worth
 *      reporting rather than assuming away.
 *
 *   2. **The translation difference.** Two entities keeping books in
 *      different currencies translate the same settlement amount at
 *      different rates, so their functional figures differ. That difference
 *      is real and it is NOT an error; what would be an error is absorbing
 *      it, because a consolidated balance sheet then carries an unexplained
 *      plug nobody can trace.
 *
 * ## Why the translation difference is reported per pair
 *
 * A group total would net differences in opposite directions against each
 * other and read as zero. The question during a consolidation is "which
 * balance is this difference on", and only the per-pair figure answers it.
 */
final class InterEntityReconciliation
{
    public function __construct(private readonly EntityContext $entities) {}

    /**
     * Every pair as at a date, with both sides' functional figures.
     *
     * ## Read entity by entity, which the first version did not
     *
     * The obvious implementation is one query across `inter_entity_transactions`
     * with no entity in context, since a pair spans two entities. It returns
     * NOTHING: the table's row-level security admits a row only when the
     * current entity is one of the two sides, and with no entity established
     * `peakflow_current_entity()` is null. Measured on a group of four
     * entities and six transactions - the consolidation reported zero pairs,
     * zero translation difference and "eliminates: true".
     *
     * Which is the failure this codebase keeps warning about: a query that
     * returns nothing looks like data.
     *
     * So each entity reads its own side, inside its own scope, and the halves
     * are joined here by transaction id. It costs one query per entity and
     * needs no row-level-security bypass at all - and it proves something the
     * single query could not: that each side is visible from its own books,
     * which is what makes the pair reconcilable from both ends.
     *
     * @return list<array{
     *     reference: string,
     *     description: string,
     *     accounting_date: string,
     *     from_entity: string,
     *     to_entity: string,
     *     settlement: string,
     *     settlement_currency: string,
     *     from_functional: string,
     *     from_currency: string,
     *     to_functional: string,
     *     to_currency: string,
     *     settlement_residual: string,
     *     inter_entity_lines: int,
     *     sides_seen: int,
     * }>
     */
    public function pairs(Carbon $asAt): array
    {
        $entities = DB::connection('tenant')->table('entities')
            ->whereNull('deleted_at')
            ->get(['id', 'code', 'functional_currency']);

        $byTransaction = [];

        foreach ($entities as $entity) {
            $entityId = (string) $entity->id;

            $rows = $this->entities->during($entityId, fn (): array => DB::connection('tenant')->select(<<<'SQL'
                SELECT
                    t.id,
                    t.reference,
                    t.description,
                    t.accounting_date,
                    t.from_entity_id,
                    t.to_entity_id,
                    t.settlement_amount,
                    t.settlement_currency,
                    coalesce((
                        SELECT sum(l.functional_amount)
                        FROM journal_lines l
                        WHERE l.inter_entity_transaction_id = t.id
                    ), 0) AS my_functional,
                    coalesce((
                        SELECT sum(l.amount)
                        FROM journal_lines l
                        WHERE l.inter_entity_transaction_id = t.id
                          AND l.currency = t.settlement_currency
                    ), 0) AS my_settlement,
                    coalesce((
                        SELECT count(*)
                        FROM journal_lines l
                        WHERE l.inter_entity_transaction_id = t.id
                    ), 0) AS my_lines
                FROM inter_entity_transactions t
                WHERE t.accounting_date <= ?
                ORDER BY t.accounting_date, t.reference
            SQL, [$asAt->toDateString()]));

            foreach ($rows as $row) {
                $id = (string) $row->id;

                $byTransaction[$id] ??= [
                    'reference' => (string) $row->reference,
                    'description' => (string) $row->description,
                    'accounting_date' => (string) $row->accounting_date,
                    'from_entity' => '',
                    'to_entity' => '',
                    'settlement' => (string) $row->settlement_amount,
                    'settlement_currency' => (string) $row->settlement_currency,
                    'from_functional' => '0',
                    'from_currency' => '',
                    'to_functional' => '0',
                    'to_currency' => '',
                    'settlement_residual' => '0',
                    'inter_entity_lines' => 0,
                    'sides_seen' => 0,
                ];

                /*
                 | `my_functional` is this entity's own half, because the
                 | row-level security on `journal_lines` admitted only its
                 | own lines - which is the same mechanism that made the
                 | cross-entity query return nothing, used deliberately here.
                 */
                $side = $entityId === (string) $row->from_entity_id ? 'from' : 'to';

                $byTransaction[$id][$side.'_entity'] = (string) $entity->code;
                $byTransaction[$id][$side.'_currency'] = (string) $entity->functional_currency;
                $byTransaction[$id][$side.'_functional'] = (string) $row->my_functional;
                $byTransaction[$id]['sides_seen']++;

                $byTransaction[$id]['settlement_residual'] = (string) BigDecimal::of(
                    $byTransaction[$id]['settlement_residual'],
                )->plus((string) $row->my_settlement);

                $byTransaction[$id]['inter_entity_lines'] += (int) $row->my_lines;
            }
        }

        return array_values($byTransaction);
    }

    /**
     * The eliminations, and the translation difference each one leaves.
     *
     * @param  array<string, Rate>  $rates  "CURRENCY/PRESENTATION" => the closing rate
     * @return array{
     *     presentation_currency: string,
     *     pairs: list<array<string, string>>,
     *     settlement_residual: string,
     *     translation_difference: string,
     *     missing_rates: list<string>,
     *     eliminates: bool,
     * }
     */
    public function eliminations(Carbon $asAt, string $presentationCurrency, array $rates): array
    {
        $pairs = [];
        $settlementResidual = BigDecimal::zero();
        $translationDifference = BigDecimal::zero();
        $missing = [];

        foreach ($this->pairs($asAt) as $pair) {
            /*
             | The settlement residual: zero by construction, measured.
             |
             | "By construction" is a claim about code that can change, so
             | this reads what the LINES hold in the settlement currency and
             | sums the two sides. Non-zero means the single settlement
             | amount has stopped being the thing both sides derive from,
             | and the whole design has quietly failed.
             */
            $settlementResidual = $settlementResidual->plus($pair['settlement_residual']);

            if ($pair['sides_seen'] !== 2) {
                /*
                 | A pair neither side could see, or only one could.
                 |
                 | Which is what the cross-entity query produced for every
                 | pair in the group, silently. Counting the sides that were
                 | actually read is what turns "the consolidation found
                 | nothing" from a clean result into a finding.
                 */
                $missing[] = sprintf(
                    '%s was visible from %d of its 2 sides',
                    $pair['reference'],
                    $pair['sides_seen'],
                );
            }

            if ($pair['inter_entity_lines'] !== 2) {
                /*
                 | Two lines, one per side, and nothing else.
                 |
                 | A third line naming the same transaction would net to
                 | zero in the residual above while leaving the pair
                 | unexplainable - so the count is checked rather than
                 | inferred from the sum.
                 */
                $missing[] = sprintf(
                    '%s has %d inter-entity line(s), not 2',
                    $pair['reference'],
                    $pair['inter_entity_lines'],
                );
            }

            $fromInPresentation = $this->translate(
                $pair['from_functional'],
                $pair['from_currency'],
                $presentationCurrency,
                $rates,
                $missing,
            );

            $toInPresentation = $this->translate(
                $pair['to_functional'],
                $pair['to_currency'],
                $presentationCurrency,
                $rates,
                $missing,
            );

            if ($fromInPresentation === null || $toInPresentation === null) {
                continue;
            }

            /*
             | The two sides are signed oppositely, so they SUM.
             |
             | A receivable of +100 and a payable of -100 sum to zero. What
             | is left after the sum is the translation difference, and it is
             | left per pair rather than aggregated, because a group total
             | nets differences in opposite directions and reads as zero.
             */
            $difference = $fromInPresentation->plus($toInPresentation);
            $translationDifference = $translationDifference->plus($difference);

            $pairs[] = [
                'reference' => $pair['reference'],
                'from_entity' => $pair['from_entity'],
                'to_entity' => $pair['to_entity'],
                'settlement' => $pair['settlement'].' '.$pair['settlement_currency'],
                'from_functional' => $pair['from_functional'].' '.$pair['from_currency'],
                'to_functional' => $pair['to_functional'].' '.$pair['to_currency'],
                'from_in_presentation' => (string) $fromInPresentation->toScale(4),
                'to_in_presentation' => (string) $toInPresentation->toScale(4),
                'translation_difference' => (string) $difference->toScale(4),
                /*
                 | Per pair, so a caller can ask about ITS pairs.
                 |
                 | A tenant has inter-entity history of its own and the
                 | group total includes all of it - which is right for a
                 | consolidation and wrong for anything checking a subset.
                 */
                'settlement_residual' => (string) BigDecimal::of($pair['settlement_residual'])->toScale(4),
            ];
        }

        return [
            'presentation_currency' => $presentationCurrency,
            'pairs' => $pairs,
            'settlement_residual' => (string) $settlementResidual->toScale(4),
            'translation_difference' => (string) $translationDifference->toScale(4),
            'missing_rates' => array_values(array_unique($missing)),
            /*
             | "Eliminates" is about the SETTLEMENT currency, not the
             | presentation one.
             |
             | The translation difference is expected and is not a failure -
             | it is a figure that has to appear in the consolidation rather
             | than vanish into one. What would be a failure is the
             | settlement amounts not cancelling, or a rate missing so that a
             | pair was not translated at all.
             */
            'eliminates' => $settlementResidual->isZero() && $missing === [],
        ];
    }

    /**
     * Inter-entity balances that no transaction explains.
     *
     * A database trigger requires every line reaching an inter-entity account
     * to name a transaction, so this should always be empty. It is checked
     * anyway, because the finding it would report - an inter-company balance
     * with no counterpart - is the one that cannot be eliminated and cannot be
     * explained, and "the trigger prevents it" is a statement about a trigger
     * that can be disabled.
     *
     * Read entity by entity for the same reason as {@see pairs()}: the lines
     * are under row-level security, and a cross-entity read of them returns
     * nothing rather than everything.
     *
     * @return list<array{entity: string, account: string, balance: string, lines: int}>
     */
    public function unexplainedBalances(Carbon $asAt): array
    {
        $findings = [];

        $entities = DB::connection('tenant')->table('entities')
            ->whereNull('deleted_at')
            ->get(['id', 'code']);

        foreach ($entities as $entity) {
            $rows = $this->entities->during(
                (string) $entity->id,
                fn (): array => DB::connection('tenant')->select(<<<'SQL'
                    SELECT
                        a.code AS account,
                        sum(l.functional_amount) AS balance,
                        count(*) AS lines
                    FROM journal_lines l
                    JOIN journal_entries j ON j.id = l.journal_entry_id
                    JOIN accounts a ON a.id = l.account_id
                    JOIN account_roles r ON r.account_id = a.id AND r.role = 'inter_entity'
                    WHERE j.status IN ('posted', 'reversed')
                      AND l.accounting_date <= ?
                      AND l.inter_entity_transaction_id IS NULL
                    GROUP BY a.code
                    HAVING sum(l.functional_amount) <> 0
                    ORDER BY a.code
                SQL, [$asAt->toDateString()]),
            );

            foreach ($rows as $row) {
                $findings[] = [
                    'entity' => (string) $entity->code,
                    'account' => (string) $row->account,
                    'balance' => (string) $row->balance,
                    'lines' => (int) $row->lines,
                ];
            }
        }

        return $findings;
    }

    /**
     * Transactions missing one of their two entries.
     *
     * The deferred constraint makes this impossible to commit, so a row here
     * means something wrote the table directly. Reported rather than trusted
     * away, for the same reason as above.
     *
     * @return list<array{reference: string, missing: string}>
     */
    public function halfPosted(): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                reference,
                CASE
                    WHEN from_entry_id IS NULL AND to_entry_id IS NULL THEN 'both sides'
                    WHEN from_entry_id IS NULL THEN 'the lending side'
                    ELSE 'the borrowing side'
                END AS missing
            FROM inter_entity_transactions
            WHERE from_entry_id IS NULL OR to_entry_id IS NULL
            ORDER BY reference
        SQL);

        return array_map(static fn (object $row): array => [
            'reference' => (string) $row->reference,
            'missing' => (string) $row->missing,
        ], $rows);
    }

    /**
     * @param  array<string, Rate>  $rates
     * @param  list<string>  $missing
     */
    private function translate(
        string $amount,
        string $from,
        string $to,
        array $rates,
        array &$missing,
    ): ?BigDecimal {
        if ($from === $to) {
            return BigDecimal::of($amount);
        }

        $rate = $rates[$from.'/'.$to] ?? null;

        if (! $rate instanceof Rate) {
            $missing[] = $from.'/'.$to;

            return null;
        }

        /*
         | The rate is MULTIPLIED, never inverted.
         |
         | A rate goes one way and the direction is stored, not inferred -
         | see Rate's own class note. Dividing by a TO/FROM rate here would
         | produce a plausible figure that is wrong by the square of the
         | rate's distance from 1.
         */
        return BigDecimal::of($amount)->multipliedBy($rate->value)->toScale(4, RoundingMode::HalfUp);
    }
}
