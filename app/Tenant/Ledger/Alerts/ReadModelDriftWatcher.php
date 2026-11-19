<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Alerts;

use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Alerts\WatchScope;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Services\BalanceRebuilder;

/**
 * The nightly rebuild's drift check, watched - PF-242, PF-053.
 *
 * ## Why this is the first alert on the list
 *
 * `account_balances` is maintained by triggers and every report in this
 * product reads it rather than aggregating ten million journal lines. When it
 * drifts, the trial balance is wrong, the statement of financial position is
 * wrong, and both of them look exactly as right as they did yesterday. There
 * is no symptom. The only thing that notices is a recomputation from the
 * journal, and before this watcher existed nothing ran one.
 *
 * ## It names the key, which is the first divergent record
 *
 * `BalanceRebuilder::check()` returns findings keyed
 * `entity|period|account|dimensions`, which is precisely the "first divergent
 * record" the criterion asks for - and the period inside that key is the
 * period the alert reports, not the entity's current one, because the drift
 * is dated where the stored row is.
 *
 * ## It reports and never repairs
 *
 * The rebuild exists and is a deliberate act with its own audit entry. A
 * watcher that repaired on the way past would erase the only evidence of how
 * long a figure had been wrong - and a figure that was wrong for a week was
 * on a report for a week, in an email, possibly in a filed return.
 */
final class ReadModelDriftWatcher implements InvariantWatcher
{
    public function __construct(private readonly BalanceRebuilder $balances) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['read_model_drift'];
    }

    public function scope(): WatchScope
    {
        return WatchScope::Entity;
    }

    /** @return list<Alert> */
    public function watch(string $tenant, ?string $entityId): array
    {
        $report = $this->balances->check($entityId);

        if ($report['findings'] === []) {
            return [];
        }

        $first = $report['findings'][0];
        $parts = explode('|', (string) $first['key']);

        return [Alert::critical(
            key: 'read_model_drift',
            tenant: $tenant,
            entityId: $parts[0] !== '' ? $parts[0] : ($entityId ?? Alert::NO_ENTITY),
            period: $this->labelFor($parts[1] ?? ''),
            firstDivergence: sprintf(
                '%s (%s): stored %s, journal %s',
                (string) $first['key'],
                (string) $first['kind'],
                $first['stored'] === null ? '(absent)' : (string) $first['stored'],
                $first['rebuilt'] === null ? '(absent)' : (string) $first['rebuilt'],
            ),
            messageAr: sprintf(
                'اختلف %d رصيد مخزَّن عن دفتر اليومية، أوّلها %s. كل تقرير في النظام يقرأ الأرصدة '.
                'المخزَّنة، فميزان المراجعة والقوائم خاطئة وتبدو صحيحة تماماً كما بدت أمس. لا تُصلِح '.
                'قبل معرفة السبب: الإصلاح الصامت يخفي الخلل الذي سبّبه.',
                count($report['findings']),
                (string) $first['key'],
            ),
            messageEn: sprintf(
                '%d stored balance(s) disagree with the journal, the first being %s. Every report in '.
                'the product reads the stored balances, so the trial balance and the statements are '.
                'wrong and look exactly as right as they did yesterday. Do NOT repair before the cause '.
                'is known: a silent repair hides the bug that caused it, and erases the only evidence '.
                'of how long the figure was wrong.',
                count($report['findings']),
                (string) $first['key'],
            ),
            observed: (string) count($report['findings']),
            threshold: '0',
        )];
    }

    /**
     * A period id turned into the label a person uses.
     *
     * `2026-05` and not a ULID. The id is in the first-divergence field for
     * whoever is going to query it; the label is what the sentence says,
     * because "period 01M7FW…" is not a period anybody recognises at 03:00.
     */
    private function labelFor(string $periodId): string
    {
        if ($periodId === '') {
            return Alert::NO_PERIOD;
        }

        $label = Period::query()->whereKey($periodId)->value('label');

        return is_string($label) && $label !== '' ? $label : $periodId;
    }
}
