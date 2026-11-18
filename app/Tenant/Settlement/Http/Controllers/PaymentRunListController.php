<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Http\Controllers;

use App\Support\Money\Money;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The payment runs, and who stands behind each one - PF-174, PF-175, PF-180.
 *
 * ## It reads. It does not release
 *
 * There is no button on this page and that is deliberate, not an
 * omission. A release needs a prepared run, a releaser who is not the
 * preparer - checked against the audit trail as well as the column - the
 * duty pairs per beneficiary, a fresh second factor for this run, and
 * proof that nothing has moved since preparation. A control on a list
 * screen can carry none of that, so the one it would offer is the
 * version that skips all five.
 *
 * {@see \App\Tenant\Settlement\Services\PaymentRuns::release()} is where
 * those live, behind its own screen, and this page's job is to make the
 * state of every run legible before anybody gets there.
 *
 * ## Three states, not six
 *
 * `draft`, `prepared` and `released`, because that is what the table
 * permits: `payment_runs_state_is_known` names exactly those and
 * `peakflow_a_payment_run_moves_lawfully` permits exactly three
 * transitions between them. What happens after a release - the file
 * going out, the bank accepting or refusing each instruction, the
 * payment settling - is recorded against the FILE and the INSTRUCTIONS
 * and not as a further state of the run, because a run whose state could
 * advance after release would be a released instruction that can still
 * change.
 *
 * Inventing "sent" and "settled" here would have put a word on screen
 * that nothing in the database can ever set, and a state that never
 * arrives reads as a step somebody forgot to do.
 *
 * ## The preparer and the releaser are named side by side
 *
 * Because the only thing anybody needs to check at a glance is that they
 * are two different people. The database refuses the same id in both
 * columns, the release refuses a releaser the audit trail records
 * preparing the run, and neither of those is visible to the person
 * reading a list - so the list shows the pair.
 *
 * ## An accepted duplicate warning is shown, with its acceptance
 *
 * "Same supplier, same amount, four days apart" is usually a duplicate
 * and sometimes a monthly retainer. The release is blocked until
 * somebody says which and signs for it, and that signature is the single
 * thing an auditor asks about afterwards. A page that showed only
 * unaccepted warnings would hide every one that mattered, because by
 * then the money has gone.
 */
final class PaymentRunListController
{
    /**
     * How many runs the page shows.
     *
     * Capped rather than paginated: a tenant with four years of weekly
     * runs has a few hundred, and the question this screen answers is
     * about the recent ones. The cap is stated on the page, because a
     * list that silently stops is one somebody draws a conclusion from.
     */
    private const LIMIT = 50;

    private const STATES = ['draft', 'prepared', 'released'];

    /**
     * @permission payment.prepare - the permission that puts "Payment
     *     runs" in the navigation. Reading what a run will pay, and who
     *     prepared it, is the preparer's own work; `payment.release` is
     *     what the release screen asks for, and asking for it here would
     *     hide a preparer's own runs from them.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        Gate::authorize('payment.prepare');

        $entity = $context->entity();

        /*
         | A query parameter can arrive as an array - `?state[]=x` - and
         | casting one to a string is a 500 on a URL anybody can type.
         */
        $parameter = $request->query('state');
        $state = \is_string($parameter) && \in_array($parameter, self::STATES, true) ? $parameter : null;

        $runs = $entity === null ? [] : $this->runs((string) $entity->getKey(), $state);
        $warnings = $runs === [] ? [] : $this->warnings(array_column($runs, 'id'));

        foreach ($runs as $index => $run) {
            $runs[$index]['warnings'] = $warnings[$run['id']] ?? [];
        }

        return view('app.settlement.payment-runs', [
            'entity' => $entity,
            'runs' => $runs,
            'state' => $state,
            'states' => self::STATES,
            'totals' => $this->totals($runs),
            'limit' => self::LIMIT,
        ]);
    }

    /**
     * The runs, with their file and the two people who signed for them.
     *
     * @return list<array<string, mixed>>
     */
    private function runs(string $entityId, ?string $state): array
    {
        $rows = DB::connection('tenant')->table('payment_runs as r')
            /*
             | The two names come from a join on `users`, not from the
             | Identity model.
             |
             | Deptrac does not permit Settlement to depend on Identity,
             | and it is right not to: a payment run has no business
             | holding a user record. What this page needs is two names
             | to put beside each other, the table is on the tenant
             | connection under the same row policies, and `name` is the
             | only column read.
             */
            ->leftJoin('users as preparer', 'preparer.id', '=', 'r.prepared_by')
            ->leftJoin('users as releaser', 'releaser.id', '=', 'r.released_by')
            /*
             | The one file that is still live, where there is one.
             |
             | `payment_files_one_live_file_per_run` is a partial unique
             | index on exactly this condition, so the join cannot
             | multiply the row - which a join on every file for the run
             | would, once somebody regenerated one, turning one run into
             | two rows that differ only in a reference nobody can tell
             | apart.
             */
            ->leftJoin('payment_files as f', static function (JoinClause $join): void {
                $join->on('f.run_id', '=', 'r.id')->whereNull('f.superseded_by_id');
            })
            ->where('r.entity_id', $entityId)
            ->when($state !== null, static fn (Builder $query): Builder => $query->where('r.state', $state))
            /*
             | By pay date, newest first, then by number.
             |
             | Two runs can share a pay date - one per bank account, which
             | is the normal shape of a Friday - and `number` is issued in
             | sequence, so the pair comes back in the order they were
             | prepared rather than in whatever order the planner chose.
             */
            ->orderByDesc('r.pay_on')
            ->orderByDesc('r.number')
            ->limit(self::LIMIT)
            ->get([
                'r.id', 'r.number', 'r.state', 'r.pay_on', 'r.currency',
                /*
                 | Read as text out of the database.
                 |
                 | `numeric` comes back through a float on some drivers,
                 | and these are the figures somebody authorises a
                 | payment against. A total that has been through a float
                 | is one nobody can defend to an auditor.
                 */
                DB::raw('r.gross_total::text AS gross_total'),
                DB::raw('r.discount_total::text AS discount_total'),
                DB::raw('r.net_total::text AS net_total'),
                'r.item_count', 'r.instruction_count',
                'r.prepared_at', 'r.released_at', 'r.released_hash',
                DB::raw('preparer.name AS preparer_name'),
                DB::raw('releaser.name AS releaser_name'),
                DB::raw('f.message_id AS file_reference'),
                DB::raw('f.scheme AS file_scheme'),
                DB::raw('f.format AS file_format'),
                DB::raw('f.generated_at AS file_generated_at'),
                DB::raw('f.downloaded_at AS file_downloaded_at'),
                DB::raw('f.authorised_hash AS file_authorised_hash'),
            ]);

        return $rows->map(static function (object $row): array {
            $currency = (string) $row->currency;

            return [
                'id' => (string) $row->id,
                'number' => (string) $row->number,
                'state' => (string) $row->state,
                'pay_on' => (string) $row->pay_on,
                'currency' => $currency,
                'gross' => Money::of((string) $row->gross_total, $currency),
                'discount' => Money::of((string) $row->discount_total, $currency),
                'net' => Money::of((string) $row->net_total, $currency),
                /*
                 | Items and instructions are both shown, because they
                 | are different things.
                 |
                 | An item is a payable somebody selected; an instruction
                 | is a transfer the bank will make. Four invoices to one
                 | supplier are four items and one payment, and a single
                 | "payments" figure would be whichever of the two the
                 | author happened to pick - which is the figure somebody
                 | then reconciles against a bank statement.
                 */
                'item_count' => (int) $row->item_count,
                'instruction_count' => (int) $row->instruction_count,
                'preparer' => $row->preparer_name === null ? null : (string) $row->preparer_name,
                'releaser' => $row->releaser_name === null ? null : (string) $row->releaser_name,
                'prepared_at' => $row->prepared_at === null ? null : (string) $row->prepared_at,
                'released_at' => $row->released_at === null ? null : (string) $row->released_at,
                'file_reference' => $row->file_reference === null ? null : (string) $row->file_reference,
                'file_scheme' => $row->file_scheme === null ? null : (string) $row->file_scheme,
                'file_format' => $row->file_format === null ? null : (string) $row->file_format,
                'file_generated_at' => $row->file_generated_at === null ? null : (string) $row->file_generated_at,
                'file_downloaded_at' => $row->file_downloaded_at === null ? null : (string) $row->file_downloaded_at,
                /*
                 | Whether the file is the one the release authorised.
                 |
                 | The run's `released_hash` is the hash of the
                 | instruction set that was approved and the file carries
                 | it as `authorised_hash`, so "is this the file for what
                 | was approved" is a string comparison rather than a
                 | reconstruction. Said on the page, because a file that
                 | does not match its release is the one fact on this row
                 | that nobody would think to ask about.
                 */
                'file_matches_release' => $row->file_authorised_hash !== null
                    && $row->released_hash !== null
                    && hash_equals((string) $row->released_hash, (string) $row->file_authorised_hash),
            ];
        })->all();
    }

    /**
     * The duplicate warnings on these runs, accepted ones included.
     *
     * One query for the page rather than one per run: the list is capped
     * and the warnings table is indexed on `(entity_id, run_id,
     * acknowledged_at)`.
     *
     * @param  list<string>  $runIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function warnings(array $runIds): array
    {
        $rows = DB::connection('tenant')->table('payment_duplicate_warnings as w')
            ->leftJoin('parties as p', 'p.id', '=', 'w.party_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.acknowledged_by')
            ->whereIn('w.run_id', $runIds)
            /*
             | Unaccepted first, then the closest in time.
             |
             | An unaccepted warning blocks the release, so it is the one
             | somebody has to act on; `days_apart` ascending puts "the
             | same amount yesterday" above "the same amount five weeks
             | ago", which is the order of how alike the two payments are.
             */
            ->orderByRaw('w.acknowledged_at IS NOT NULL, w.days_apart NULLS LAST, w.id')
            ->get([
                'w.run_id', 'w.reason', 'w.detail',
                DB::raw('w.amount::text AS amount'), 'w.currency',
                'w.other_entity_code', 'w.other_reference', 'w.other_date', 'w.days_apart',
                'w.acknowledged_at', 'w.acknowledgement',
                DB::raw('coalesce(p.trading_name, p.legal_name) AS party_name'),
                DB::raw('u.name AS acknowledged_by_name'),
            ]);

        $grouped = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;

            $grouped[(string) $row->run_id][] = [
                'reason' => (string) $row->reason,
                'detail' => (string) $row->detail,
                'amount' => Money::of((string) $row->amount, $currency),
                'currency' => $currency,
                'party' => $row->party_name === null ? null : (string) $row->party_name,
                'other_entity' => $row->other_entity_code === null ? null : (string) $row->other_entity_code,
                'other_reference' => $row->other_reference === null ? null : (string) $row->other_reference,
                'other_date' => $row->other_date === null ? null : (string) $row->other_date,
                'days_apart' => $row->days_apart === null ? null : (int) $row->days_apart,
                'accepted' => $row->acknowledged_at !== null,
                'accepted_at' => $row->acknowledged_at === null ? null : (string) $row->acknowledged_at,
                'accepted_by' => $row->acknowledged_by_name === null ? null : (string) $row->acknowledged_by_name,
                /*
                 | The reason somebody gave, verbatim.
                 |
                 | The table demands at least fifteen characters of it,
                 | because a dismissal with no sentence is what an
                 | investigation finds instead of an answer. Printing it
                 | is the other half of that: a reason nobody ever reads
                 | is a reason nobody writes carefully.
                 */
                'acceptance' => $row->acknowledgement === null ? null : (string) $row->acknowledgement,
            ];
        }

        return $grouped;
    }

    /**
     * What the listed runs come to, per currency.
     *
     * Never summed across currencies. A single total over a riyal run and
     * a euro run is a number with no unit, and it is the kind of figure
     * that gets quoted in a meeting.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, array<string, mixed>>
     */
    private function totals(array $runs): array
    {
        $totals = [];

        foreach ($runs as $run) {
            $currency = (string) $run['currency'];

            if (! isset($totals[$currency])) {
                $totals[$currency] = [
                    'currency' => $currency,
                    'runs' => 0,
                    'instructions' => 0,
                    'net' => Money::zero($currency),
                    /*
                     | What has actually left, kept apart from the total.
                     |
                     | A draft run is a selection somebody is still
                     | editing and a released one is money gone. Adding
                     | them into one figure would report cash as spent
                     | that nobody has approved, which is exactly the
                     | direction an error here must not go.
                     */
                    'released' => Money::zero($currency),
                ];
            }

            $totals[$currency]['runs']++;
            $totals[$currency]['instructions'] += (int) $run['instruction_count'];
            $totals[$currency]['net'] = $totals[$currency]['net']->plus($run['net']);

            if ($run['state'] === 'released') {
                $totals[$currency]['released'] = $totals[$currency]['released']->plus($run['net']);
            }
        }

        ksort($totals);

        return $totals;
    }
}
