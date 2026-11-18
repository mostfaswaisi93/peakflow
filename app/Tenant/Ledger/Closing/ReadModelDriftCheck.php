<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Closing;

use App\Support\Closing\ClosingCheck;
use App\Tenant\Ledger\Services\BalanceRebuilder;

/**
 * A period does not close over balances that disagree with the journal - PF-196.
 *
 * ## Why this is a blocking item and not a nightly warning
 *
 * `account_balances` is maintained by triggers as lines are posted, and
 * every report in this product reads it rather than aggregating ten million
 * journal lines. So when it drifts, the trial balance is wrong, the
 * statement of financial position is wrong, and both of them look exactly
 * as right as they did yesterday.
 *
 * Closing the period freezes that. Afterwards the figures somebody reported
 * are the drifted ones, the correction is a prior-period adjustment under
 * IAS 8, and the conversation is with an auditor rather than with a
 * rebuild command that would have taken a minute.
 *
 * ## It cannot be signed off
 *
 * Deliberately, and unlike suspense. Unexplained money in suspense is a
 * fact about the world that the close cannot always resolve - a bank will
 * not say who sent a transfer. Drift is a fact about THIS SYSTEM, it has a
 * one-command fix, and there is no circumstance in which closing over it is
 * the better of two options.
 */
final class ReadModelDriftCheck implements ClosingCheck
{
    public function __construct(private readonly BalanceRebuilder $balances) {}

    public function name(): string
    {
        return 'read-model drift';
    }

    /** @return list<string> */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
    {
        unset($label, $from, $until);

        /*
         | The whole entity, not the period.
         |
         | A balance row's grain is the period, so drift in an EARLIER
         | period is still drift the closing period's opening balance is
         | built on. Checking only the period being closed would let a
         | wrong opening balance through every month until somebody ran
         | the rebuild for another reason.
         */
        /*
         | `check()` returns a REPORT, not a list of findings.
         |
         | `['checked' => n, 'findings' => [...], 'clean' => bool]`. The
         | first version of this treated the whole report as the list and
         | handed an integer to a closure expecting an array - which the
         | registry caught and reported as "the check could not be
         | computed", so the close was still refused. The registry wrapping
         | a throwing check is what turned a bug into a safe failure.
         */
        $report = $this->balances->check($entityId);
        $findings = $report['findings'];

        if ($findings === []) {
            return [];
        }

        $worst = array_slice($findings, 0, 3);

        $named = array_map(
            static fn (array $finding): string => sprintf(
                '%s (%s)',
                (string) $finding['key'],
                (string) $finding['kind'],
            ),
            $worst,
        );

        return [sprintf(
            '%d stored balance(s) disagree with the journal, starting with %s. Every report in the '.
            'product reads the stored balances, so the trial balance and the statements are wrong and '.
            'look exactly as right as they did yesterday. Closing freezes that, and the correction '.
            'afterwards is a prior-period adjustment rather than `ledger:rebuild-balances`.',
            \count($findings),
            implode(', ', $named),
        )];
    }
}
