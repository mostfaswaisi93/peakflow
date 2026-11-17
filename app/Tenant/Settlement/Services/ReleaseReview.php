<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Hashing\Canonicalizer;
use App\Support\Money\Money;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Illuminate\Support\Facades\DB;

/**
 * What changed between preparing a run and releasing it - PF-175.
 *
 * ## The releaser is approving a moment, not a row
 *
 * Somebody prepared this run on Tuesday against a state of the world:
 * these payables approved, these beneficiaries verified, these accounts on
 * file. On Thursday a second person is asked to send it. If anything in
 * that state has moved, what they are approving is not what was prepared,
 * and the maker-checker control has been reduced to two people clicking
 * the same button a day apart.
 *
 * So the preparation is **invalidated** by any change, and the run goes
 * back for re-preparation. That is the conservative direction, and it is
 * the right one here: re-preparing a run costs minutes, and releasing a
 * stale one moves money to a destination nobody checked.
 *
 * ## The beneficiary account is the one that matters most
 *
 * PF-061's control is that changing a supplier's bank details needs a
 * second person's verification. The hole this closes is narrower and
 * worse: the details change **after** the payable was approved and
 * **after** the run was prepared, are re-verified by a colleague who
 * knows nothing of the pending run, and the release pays the new account.
 *
 * Everybody did their job and the money went somewhere the approver never
 * saw. So an account touched since preparation invalidates the
 * preparation whether or not it has been re-verified since: the point is
 * that this run was built against the old one.
 *
 * ## It reads, and changes nothing
 *
 * A review that wrote would be a review somebody could run to make a
 * stale run releasable.
 */
final class ReleaseReview
{
    /**
     * Everything that moved since this run was prepared.
     *
     * @return array{
     *     run_id: string,
     *     number: string,
     *     prepared_at: string|null,
     *     changes: list<array{what: string, subject: string, why: string}>,
     *     blocks: bool,
     *     hash: string,
     *     why: string,
     * }
     */
    public function of(string $runId): array
    {
        $run = DB::connection('tenant')->table('payment_runs')->where('id', $runId)->first();

        if ($run === null) {
            throw PaymentRefused::because('There is no payment run with that id to review.');
        }

        $changes = [
            ...$this->beneficiaryChanges($run),
            ...$this->payableChanges($run),
        ];

        return [
            'run_id' => $runId,
            'number' => (string) $run->number,
            'prepared_at' => $run->prepared_at === null ? null : (string) $run->prepared_at,
            'changes' => $changes,
            'blocks' => $changes !== [],
            'hash' => $this->hash($runId),
            'why' => $changes === []
                ? sprintf(
                    'Nothing has moved since %s was prepared%s. What is on the screen is what was '.
                    'prepared, and releasing it sends exactly that.',
                    (string) $run->number,
                    $run->prepared_at === null ? '' : ' on '.(string) $run->prepared_at,
                )
                : sprintf(
                    '%d thing%s changed since %s was prepared, so this is no longer the run somebody '.
                    'checked. Prepare it again: the figures will be recomputed against the world as '.
                    'it is now, and the next releaser will be approving that. %s',
                    \count($changes),
                    \count($changes) === 1 ? '' : 's',
                    (string) $run->number,
                    implode(' ', array_map(
                        static fn (array $change): string => ucfirst($change['what']).': '.$change['why'].'.',
                        $changes,
                    )),
                ),
        ];
    }

    /**
     * The canonical hash of what would be sent.
     *
     * PF-175 asks that the released file be identifiable afterwards.
     * PF-176 is what generates the file, so what is hashed here is the
     * **content the file will be built from**: the run's identity and
     * every instruction's beneficiary, account, amount and position, in a
     * fixed order.
     *
     * That is the better control either way. A hash of a file proves
     * which bytes went out; a hash of the instruction set proves **what
     * was authorised**, and the file must then match it. PF-176 asserts
     * exactly that, which it could not do against a hash of itself.
     */
    public function hash(string $runId): string
    {
        $run = DB::connection('tenant')->table('payment_runs')->where('id', $runId)->first();

        if ($run === null) {
            throw PaymentRefused::because('There is no payment run with that id to hash.');
        }

        $instructions = DB::connection('tenant')->table('payment_run_instructions')
            ->where('run_id', $runId)
            /*
             | Ordered by something total and stable.
             |
             | The id, because two instructions can share a party, an
             | amount and a sequence - a split to one beneficiary in a run
             | that also pays them separately - and a hash whose input
             | order depends on how a query happened to return rows is a
             | hash that changes without the content changing.
             */
            ->orderBy('id')
            ->get();

        return Canonicalizer::hash([
            'run' => (string) $run->number,
            'entity' => (string) $run->entity_id,
            'bank_account' => (string) $run->bank_account_id,
            'currency' => (string) $run->currency,
            'pay_on' => (string) $run->pay_on,
            'net_total' => (string) $run->net_total,
            'instructions' => $instructions->map(static fn (object $row): array => [
                'party' => (string) $row->party_id,
                'account' => $row->party_bank_account_id === null ? null : (string) $row->party_bank_account_id,
                'amount' => (string) $row->amount,
                'sequence' => (int) $row->sequence,
                'of' => (int) $row->of,
            ])->all(),
        ]);
    }

    /**
     * What identifies where the money is going.
     *
     * Content, not a clock: both `updated_at` and `prepared_at` are
     * stored to the second, so an account changed in the same second as
     * the preparation compared equal and reported nothing. A hash of the
     * destination itself is clock-free, says nothing about a bookkeeping
     * column being touched, and treats a change reverted as the
     * non-change it is.
     *
     * Not over `verified_at`. An account re-verified with no change to
     * where the money goes is not a change to where the money goes, and
     * blocking on it would be noise - whereas details changed and not
     * re-verified has its own branch and a better message.
     *
     * @param  object{last_four: mixed, bic: mixed, country: mixed, active: mixed}  $account
     */
    public static function fingerprintOf(object $account): string
    {
        return Canonicalizer::hash([
            'tail' => (string) ($account->last_four ?? ''),
            'bic' => (string) ($account->bic ?? ''),
            'country' => (string) ($account->country ?? ''),
            'active' => (bool) ($account->active ?? false),
        ]);
    }

    /**
     * Beneficiary accounts touched since the run was prepared.
     *
     * @return list<array{what: string, subject: string, why: string}>
     */
    private function beneficiaryChanges(object $run): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.account_fingerprint AS prepared_fingerprint,
                   coalesce(p.legal_name, 'a beneficiary') AS party_name,
                   a.id AS account_id,
                   a.active,
                   a.deleted_at,
                   a.requires_reverification,
                   a.last_four,
                   a.bic,
                   a.country
            FROM payment_run_instructions i
            LEFT JOIN parties p ON p.id = i.party_id
            LEFT JOIN party_bank_accounts a ON a.id = i.party_bank_account_id
            WHERE i.run_id = ?
            ORDER BY p.legal_name, i.sequence
        SQL, [(string) $run->id]);

        $changes = [];

        foreach ($rows as $row) {
            if ($row->account_id === null) {
                $changes[] = [
                    'what' => 'a beneficiary account is gone',
                    'subject' => (string) $row->party_name,
                    'why' => sprintf(
                        'the account %s was to be paid into no longer exists, so this transfer has '.
                        'nowhere to go',
                        (string) $row->party_name,
                    ),
                ];

                continue;
            }

            if ($row->deleted_at !== null || $row->active === false) {
                $changes[] = [
                    'what' => 'a beneficiary account was withdrawn',
                    'subject' => (string) $row->party_name,
                    'why' => sprintf(
                        '%s\'s account ending %s has been deactivated since the run was prepared',
                        (string) $row->party_name,
                        (string) $row->last_four,
                    ),
                ];

                continue;
            }

            if ($row->requires_reverification) {
                /*
                 | The criterion's own case, and the worst one.
                 |
                 | An invoice arrives with new bank details, somebody
                 | updates the supplier, and the pending run pays a
                 | stranger. PF-174 keeps an unverified account out of a
                 | run at selection; this keeps it out of a release.
                 */
                $changes[] = [
                    'what' => 'a beneficiary\'s bank details changed',
                    'subject' => (string) $row->party_name,
                    'why' => sprintf(
                        '%s\'s bank details were changed since the run was prepared and nobody has '.
                        're-verified them. This is the invoice-fraud path: new details arrive, the '.
                        'supplier record is updated, and the pending run pays whoever sent them',
                        (string) $row->party_name,
                    ),
                ];

                continue;
            }

            /*
             | Nullable, so a run prepared before the column existed is
             | not reported as changed.
             |
             | "We cannot tell" and "it changed" are different answers,
             | and reporting the first as the second would block every
             | run released across the deploy with a reason nobody can
             | act on.
             */
            if ($row->prepared_fingerprint !== null
                && (string) $row->prepared_fingerprint !== self::fingerprintOf($row)) {
                /*
                 | Changed and re-verified is still a change.
                 |
                 | The second person who verified it knew nothing of this
                 | pending run. Everybody did their job and the money
                 | would go somewhere the approver never saw.
                 */
                $changes[] = [
                    'what' => 'a beneficiary account was changed and re-verified',
                    'subject' => (string) $row->party_name,
                    'why' => sprintf(
                        '%s\'s account was changed and verified again after this run was prepared, '.
                        'so the run was built against the old one and whoever re-verified it knew '.
                        'nothing of this pending payment',
                        (string) $row->party_name,
                    ),
                ];
            }
        }

        return $changes;
    }

    /**
     * Payables that moved under the run.
     *
     * @return list<array{what: string, subject: string, why: string}>
     */
    private function payableChanges(object $run): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT pri.id,
                   b.supplier_document_number AS number,
                   b.status,
                   b.approved_at,
                   pri.gross_amount::text AS was_open,
                   peakflow_document_open_balance('bill', b.id)::text AS is_open,
                   pri.currency,
                   (b.terms_snapshot->'discount'->>'percent') AS discount_percent,
                   pri.discount_percent::text AS prepared_percent
            FROM payment_run_items pri
            JOIN bills b ON b.id = pri.bill_id
            WHERE pri.run_id = ?
            ORDER BY b.supplier_document_number
        SQL, [(string) $run->id]);

        $changes = [];

        foreach ($rows as $row) {
            if ($row->approved_at === null || (string) $row->status === 'cancelled') {
                $changes[] = [
                    'what' => 'a payable is no longer approved',
                    'subject' => (string) $row->number,
                    'why' => sprintf(
                        'bill %s is %s and its approval has gone, so the run would pay what nobody '.
                        'currently stands behind',
                        (string) $row->number,
                        (string) $row->status,
                    ),
                ];

                continue;
            }

            $was = Money::of((string) $row->was_open, (string) $row->currency);
            $is = Money::of((string) $row->is_open, (string) $row->currency);

            /*
             | Less owed than when the run was prepared.
             |
             | A credit note, a write-off, or somebody else's payment
             | landing in between. Paying the prepared figure would
             | over-settle the bill, and the supplier would be holding
             | money against nothing.
             |
             | MORE owed is not a change that invalidates anything: the
             | run pays what it prepared and the rest waits for the next
             | one, which is how a run is supposed to behave.
             */
            if ($is->amount->isLessThan($was->amount)) {
                $changes[] = [
                    'what' => 'a payable is worth less than when it was selected',
                    'subject' => (string) $row->number,
                    'why' => sprintf(
                        'bill %s had %s open when the run was prepared and has %s now - a credit '.
                        'note, a write-off or somebody else\'s payment landed in between, and '.
                        'paying the prepared figure would over-settle it',
                        (string) $row->number,
                        (string) $was->toPresentation(),
                        (string) $is->toPresentation(),
                    ),
                ];
            }
        }

        return $changes;
    }
}
