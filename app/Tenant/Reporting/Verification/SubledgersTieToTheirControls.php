<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Verification;

use App\Support\Verification\LedgerCheck;
use App\Tenant\Reporting\Services\AgingReport;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;

/**
 * Receivables and payables equal their control accounts - PF-163, PF-242.
 *
 * ## The gap this closes, and how long it was open
 *
 * `ledger:verify` composed ten checks and registered three more from other
 * modules - suspense, in-transit money and inventory. None of them compared
 * **receivables or payables** to their control accounts, which is the first
 * reconciliation an auditor performs and the one every accountant means by
 * "does the subledger tie".
 *
 * Three things made that invisible rather than obvious:
 *
 * `LedgerVerifier` carried an orphaned docblock reading *"Each subledger
 * control account equals the sum of its own lines. Checked per role so that
 * a discrepancy names which one."* It described `controlTotals()`, two
 * hundred lines below, which is reachable only from `ledger:verify --totals`
 * and is not in `verify()` at all. So the verifier read as though it made
 * this comparison.
 *
 * `controlAccountsPostedManually()` reports a manual posting with the
 * consequence *"The subledger and the control account now disagree"* - and
 * it fires only when `posting_rule IS NULL`. An entry **with** a rule
 * recorded that moves a control account without a document is well-formed,
 * balanced, and silent.
 *
 * And `SuspenseIsTraceable`'s own docblock, arguing that its finding is
 * critical rather than a warning, reaches for the obvious comparison:
 * *"the same class of fault as a receivables control that does not agree
 * with the customer ledger - and nobody would call that a warning"*. The
 * example it borrows for authority is the one nothing checked.
 *
 * ## This is not hypothetical
 *
 * The sales foreign-currency defect fixed on 2026-11-18 produced exactly
 * this state. A 1,000 EUR invoice credited 1,000 of the entity's own
 * currency to receivables, so the control account disagreed with the
 * customer ledger by the whole translation - and the entry balanced, the
 * trial balance tied, and `ledger:verify` passed. Every line was wrong by
 * the same factor, which is what makes this class of defect invisible to
 * every check that asks whether the books are internally consistent.
 *
 * A subledger tie is the check that is not internal. It asks whether the
 * ledger agrees with the documents, which is the only question a balanced
 * wrong answer cannot survive.
 *
 * ## It asks `AgingReport`, and computes nothing
 *
 * Both sides already exist and are already compared: `receivables()` and
 * `payables()` each return a reconciliation against their control account,
 * because an ageing report that does not tie is a report nobody can act on.
 * A second comparison here would be a second answer to "does receivables
 * tie", and the first of the two to drift would be believed by whoever read
 * it first.
 *
 * That is also why this check lives in `Reporting`. The ageing is where the
 * one definition of a document's open balance is used, `Sales` may not
 * depend on `Reporting`, and a tie computed in `Sales` from invoices plus a
 * control balance read from `journal_lines` would be precisely the second
 * opinion the layering exists to prevent.
 *
 * ## Critical, and `as of` today
 *
 * Critical because a control account that does not agree with its own
 * subsidiary record is not advice: one of the two figures is wrong, both are
 * used to decide who gets paid, and neither can be shown to be the right one
 * without re-deriving the history.
 *
 * `asOf` is the clock here rather than a parameter, and that is the one
 * place in this product where that is right: the question is whether the
 * books tie **now**, and a verifier that took a date could be run against a
 * date on which they happened to.
 */
final class SubledgersTieToTheirControls implements LedgerCheck
{
    public function __construct(private readonly AgingReport $aging) {}

    public function name(): string
    {
        return 'subledgers';
    }

    /** @return list<array{severity: string, kind: string, detail: string, consequence: string}> */
    public function findings(?string $entityId): array
    {
        $entities = Entity::query()
            ->when($entityId !== null, fn ($q) => $q->where('id', $entityId))
            ->get();

        $findings = [];
        $asOf = Carbon::now();

        foreach ($entities as $entity) {
            foreach (['receivables', 'payables'] as $side) {
                $findings = [...$findings, ...$this->side($entity, $side, $asOf)];
            }
        }

        return $findings;
    }

    /**
     * One side of one entity.
     *
     * @return list<array{severity: string, kind: string, detail: string, consequence: string}>
     */
    private function side(Entity $entity, string $side, Carbon $asOf): array
    {
        $currency = (string) $entity->functional_currency;

        /*
         | No try/catch, and that is deliberate rather than an omission.
         |
         | `LedgerChecks::findings()` already wraps every registered check
         | and turns a throw into a critical finding - "a verification that
         | did not run reports a clean ledger, which is worse than not
         | running it at all", which is the right sentence and is already
         | written. A catch here would be a second, worse copy of it.
         |
         | It was written and then removed, because no fixture can make the
         | ageing throw and a bite therefore could not prove the branch
         | existed. Unprovable code that duplicates working handling is the
         | thing this suite spends its time deleting.
         */
        $report = $side === 'receivables'
            ? $this->aging->receivables((string) $entity->getKey(), $currency, $asOf)
            : $this->aging->payables((string) $entity->getKey(), $currency, $asOf);

        $reconciliation = $report['reconciliation'] ?? [];

        /*
         | `checked => false` is a REASON, not a failure.
         |
         | The ageing declines to compare when the report holds a foreign
         | currency, because translating each document at its own tax point
         | is the ageing's job and not this check's - and comparing without
         | translating would report an exchange rate as a defect. Returning
         | a finding here would make every multi-currency entity
         | permanently critical, which is how a check gets switched off.
         |
         | It is not silence either: the ageing states the reason on its own
         | output, where somebody reading the report sees it.
         */
        if (($reconciliation['checked'] ?? false) !== true) {
            return [];
        }

        if (($reconciliation['agrees'] ?? false) === true) {
            return [];
        }

        return [[
            'severity' => 'critical',
            'kind' => sprintf('%s in %s', $side, (string) $entity->code),
            'detail' => sprintf(
                'The %s ledger comes to %s and the control account holds %s, a difference of %s %s. %s',
                $side,
                (string) ($reconciliation['aging_total'] ?? '?'),
                (string) ($reconciliation['control_account'] ?? '?'),
                (string) ($reconciliation['difference'] ?? '?'),
                (string) ($reconciliation['currency'] ?? $currency),
                (string) ($reconciliation['why'] ?? ''),
            ),
            'consequence' => 'one of the two figures is wrong and both are used to decide who gets '.
                'paid, and neither can be shown to be the correct one without re-deriving the whole '.
                'history - which is why this is the first thing an auditor reconciles',
        ]];
    }
}
