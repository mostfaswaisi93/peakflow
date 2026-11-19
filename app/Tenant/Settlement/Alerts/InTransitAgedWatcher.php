<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Alerts;

use App\Support\Money\Money;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Alerts\WatchScope;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Settlement\Services\TransitClearing;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;

/**
 * A payment that left and never appeared on a statement - PF-242.
 *
 * ## Why this is an alert and not only a figure on a dashboard
 *
 * Because of what the three causes have in common. An aged in-transit item is
 * a file that was generated and never handed to the bank, a payment the bank
 * rejected without telling us, or a statement line nobody matched - and every
 * one of them ends with a supplier who has not been paid and a ledger that
 * says they have.
 *
 * The supplier finds out. Nobody here does, because the ledger is correct
 * about what was instructed and the dashboard is a number somebody looks at
 * on the days they look at it.
 *
 * ## The age floor is five days, which is `TransitClearing`'s own default
 *
 * Long enough that an ordinary domestic transfer has cleared, short enough
 * that a missing file is caught in the week it was generated. Taking the same
 * figure as the aged-items register rather than inventing a second threshold
 * means the alert and the register the runbook sends you to agree about what
 * "aged" means - two definitions would disagree first on the day one of them
 * was right.
 *
 * ## The clock is passed in
 *
 * `aged()` would default to `Carbon::now()` internally, which is the same
 * clock - but passing one named instant makes the figure a function of that
 * instant rather than of whenever each call happened to be made, so the
 * alert and the register it points at cannot straddle midnight and disagree.
 */
final class InTransitAgedWatcher implements InvariantWatcher
{
    /** The age floor, in days - the same one the aged-items register uses. */
    private const FLOOR = 5;

    public function __construct(private readonly TransitClearing $transit) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['in_transit_aged'];
    }

    public function scope(): WatchScope
    {
        return WatchScope::Entity;
    }

    /** @return list<Alert> */
    public function watch(string $tenant, ?string $entityId): array
    {
        if ($entityId === null) {
            return [];
        }

        $entity = Entity::query()->whereKey($entityId)->first();

        if ($entity === null) {
            return [];
        }

        $aged = $this->transit->aged($entityId, self::FLOOR, Carbon::now());

        if ($aged === []) {
            return [];
        }

        $total = Money::zero((string) $entity->functional_currency);
        $oldest = $aged[0];

        foreach ($aged as $item) {
            if ($item['amount'] instanceof Money) {
                $total = $total->plus($item['amount']);
            }

            if ((int) $item['age_in_days'] > (int) $oldest['age_in_days']) {
                $oldest = $item;
            }
        }

        return [Alert::critical(
            key: 'in_transit_aged',
            tenant: $tenant,
            entityId: $entityId,
            period: $this->openPeriod($entityId),
            /*
             | The instruction and the run, never the beneficiary's name.
             |
             | `aged()` returns the legal name because the register has to
             | say who is waiting; an alert goes to a log and a pager and is
             | kept for weeks, so it carries the ids and the reader opens the
             | register.
             */
            firstDivergence: sprintf(
                'instruction %s on run %s, payable %s, %d day(s) old, bank status %s',
                (string) $oldest['instruction_id'],
                (string) $oldest['run'],
                (string) $oldest['pay_on'],
                (int) $oldest['age_in_days'],
                $oldest['bank_status'] === null ? '(none reported)' : (string) $oldest['bank_status'],
            ),
            messageAr: sprintf(
                '%s من المدفوعات قيد التحويل منذ أكثر من %d أيام عبر %d أمر، أقدمها عمره %d يوماً. '.
                'إمّا ملف لم يُسلَّم للبنك، أو رفض لم يُبلَّغ عنه، أو سطر كشف لم يُطابَق - وكلها تنتهي '.
                'بمورّد لم يُدفَع له ودفتر يقول إنه دُفِع. افتح سجل البنود المتقادمة ولا تُسوِّ الحساب.',
                (string) $total->abs()->toPresentation(),
                self::FLOOR,
                count($aged),
                (int) $oldest['age_in_days'],
            ),
            messageEn: sprintf(
                '%s has been in transit for more than %d days across %d instruction(s), the oldest %d '.
                'day(s) old. It is a file never handed to the bank, a rejection nobody reported, or a '.
                'statement line nobody matched - and all three end with a supplier who has not been '.
                'paid and a ledger that says they have. Open the aged-items register; do NOT clear the '.
                'control account.',
                (string) $total->abs()->toPresentation(),
                self::FLOOR,
                count($aged),
                (int) $oldest['age_in_days'],
            ),
            observed: (string) $total->abs()->amount,
            threshold: '0.0000',
        )];
    }

    private function openPeriod(string $entityId): string
    {
        $period = Period::query()
            ->where('entity_id', $entityId)
            ->where('state', 'open')
            ->orderBy('starts_on')
            ->first();

        return $period === null
            ? '(no open period: every correction here is a restatement)'
            : (string) $period->label;
    }
}
