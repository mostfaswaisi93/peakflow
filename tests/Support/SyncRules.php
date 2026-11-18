<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Device;
use App\Platform\Models\Tenant;
use App\Platform\Services\Devices;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sync\Exceptions\SyncRefused;
use App\Tenant\Sync\Services\Submissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Devices, and writes that survive a bad network - PF-255.
 *
 * ## What a check here is for
 *
 * Three failures, in order of how expensive they are.
 *
 * A **stolen till that keeps working** is the security one: somebody
 * walks out with a tablet and sells from it for a week. Everything
 * about the token - hashed, scoped to one tenant, revocable in one
 * place - exists for that.
 *
 * A **sale recorded twice** is the one that happens every day. A
 * network that drops a response is indistinguishable from one that
 * drops a request, so the till retries, and a server that cannot tell a
 * retry from a second sale doubles the day's takings.
 *
 * A **conflict decided by latency** is the one nobody notices. Two
 * tills sell the last unit; a last-write-wins rule picks the one with
 * better wifi and tells nobody. The stock is then wrong and a customer
 * has paid for something that is not there.
 */
final class SyncRules
{
    use Concerns\IssuesInvoices;

    private ?Tenant $tenant = null;

    private string $deviceId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $this->tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($this->tenant === null) {
            return ['sync (no tenant provisioned - skipped)' => []];
        }

        $devices = [];

        try {
            $devices = $this->checkTheDevices();
        } catch (Throwable $e) {
            $devices = ['devices could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        }

        app(TenantConnectionResolver::class)->point($this->tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();
            $this->deviceId = (string) Str::ulid();

            return $devices + $this->runEachInASavepoint([
                // ---- Idempotency is the contract -----------------------
                'a write carries the device\'s own id' => fn (): array => $this->aWriteIsNamed(),
                'replaying one id creates one record' => fn (): array => $this->replayingIsSafe(),
                'and the replay returns the ORIGINAL answer' => fn (): array => $this->theReplayIsTheSameAnswer(),
                'two attempts at once settle to one' => fn (): array => $this->theIndexSerialisesTheRace(),
                'a rejection is recorded, so a retry is refused the same way' => fn (): array => $this->rejectionsAreRecorded(),

                // ---- Conflicts -----------------------------------------
                'a conflict is surfaced with both sides, never merged' => fn (): array => $this->conflictsAreSurfaced(),
                'a conflict needs somebody\'s decision and name' => fn (): array => $this->conflictsAreDecided(),
                'a decided conflict is not decided again' => fn (): array => $this->conflictsSettleOnce(),

                // ---- The clock -----------------------------------------
                'ordering is by the server\'s receipt, not the device\'s clock' => fn (): array => $this->orderingIsServerSide(),
                'a wrong clock is reported, and dates nothing' => fn (): array => $this->skewIsVisible(),

                // ---- The log -------------------------------------------
                'the log says what each device sent and when' => fn (): array => $this->theLogAnswersTheQuestion(),
                'a submission cannot be edited or removed' => fn (): array => $this->submissionsAreFacts(),
            ]);
        } catch (Throwable $e) {
            return $devices + ['sync could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Devices, in the operator's plane ---------------------------------

    /** @return array<string, list<string>> */
    private function checkTheDevices(): array
    {
        $devices = app(Devices::class);
        \assert($this->tenant !== null);

        $results = [];

        // ---- The token is never stored -----------------------------------
        $failures = [];
        $registered = $devices->register($this->tenant, 'Till 2, front counter', 'till', str_repeat('0', 26));
        $token = (string) $registered['token'];
        $device = $registered['device'];

        try {
            $stored = (string) DB::table('devices')->where('id', (string) $device->getKey())->value('token_hash');

            if ($stored === $token || str_contains($stored, mb_substr($token, 4, 20))) {
                $failures[] = 'the token is stored in a form the row reveals, so a dump of this table '.
                    'can sell things';
            }

            if ($stored !== hash('sha256', $token)) {
                $failures[] = 'the stored hash is not sha256 of the token, so the token cannot be '.
                    'verified at all';
            }

            /*
             | And the hint is only the tail.
             |
             | Enough to say "revoke the one ending 7f3a" over the
             | telephone, and not enough to be worth stealing.
             */
            $hint = (string) DB::table('devices')->where('id', (string) $device->getKey())->value('token_hint');

            if (mb_strlen($hint) > 12 || ! str_ends_with($token, $hint)) {
                $failures[] = sprintf('the token hint is %s, which is not a short tail of the token', $hint);
            }

            $results['a device token is stored as a hash, never in clear'] = $failures;

            // ---- It resolves, and only to its own tenant -----------------
            $failures = [];
            $resolved = $devices->resolve($token, '10.0.0.5', '1.4.2');

            if ((string) $resolved->getKey() !== (string) $device->getKey()) {
                $failures[] = 'the token resolved to a different device';
            }

            if ((string) $resolved->tenant_id !== (string) $this->tenant->getKey()) {
                $failures[] = 'the token is not scoped to the tenant it was issued for, which is a '.
                    'cross-tenant read waiting for a bug in a route';
            }

            if ($resolved->last_seen_at === null) {
                $failures[] = '"when did till 2 last reach us" is unanswerable, which is the first '.
                    'question asked when a shop says the totals are wrong';
            }

            $unknown = $this->attempt(fn () => $devices->resolve('pfd_'.str_repeat('x', 43)));

            if ($unknown === null) {
                $failures[] = 'an invented token resolved to a device';
            } elseif (str_contains((string) $unknown, 'no such') && ! str_contains((string) $unknown, 'is not one this platform issued')) {
                $failures[] = 'the refusal distinguishes an unknown token from a malformed one, which '.
                    'tells somebody with a list of guesses which ones exist';
            }

            $results['a token resolves to one device and one tenant'] = $failures;

            // ---- Revocation ----------------------------------------------
            $failures = [];

            $silent = $this->attempt(fn () => $devices->revoke($device, 'lost', str_repeat('1', 26)));

            if ($silent === null) {
                $failures[] = 'a device was revoked with "lost" as the reason - "sold the van" and "the '.
                    'till was stolen" are read a month later by somebody deciding whether to '.
                    're-register it';
            }

            $devices->revoke($device, 'the tablet was stolen from the counter on Saturday', str_repeat('1', 26));

            $afterwards = $this->attempt(fn () => $devices->resolve($token));

            if ($afterwards === null) {
                $failures[] = 'a revoked device still resolves, so a stolen till keeps selling - which '.
                    'is the whole risk this task exists for';
            } elseif (! str_contains((string) $afterwards, 'stolen from the counter')) {
                $failures[] = 'the refusal does not say why the device was revoked, so the person '.
                    'holding it cannot tell a theft from a network fault: '.(string) $afterwards;
            }

            $again = $this->attempt(fn () => $devices->revoke(
                $device->refresh(),
                'revoking it a second time, for the same reason',
                str_repeat('1', 26),
            ));

            if ($again === null) {
                $failures[] = 'a device was revoked twice, so the record of WHEN it was revoked moved';
            }

            /*
             | And the database refuses an unexplained revocation.
             |
             | Because the way a device gets revoked quietly is an
             | UPDATE, not a service call.
             */
            $direct = $this->attempt(fn () => DB::table('devices')
                ->where('id', (string) $device->getKey())
                ->update(['revoked_at' => now(), 'revoked_by' => null, 'revoked_reason' => null]));

            if ($direct === null) {
                $failures[] = 'the database accepts a revocation with nobody\'s name and no reason on it';
            }

            $results['a revoked device stops working, and says why'] = $failures;

            // ---- A device must name a tenant that exists -----------------
            $failures = [];

            $unnamed = $this->attempt(fn () => $devices->register($this->tenant, 'x', 'till', str_repeat('0', 26)));

            if ($unnamed === null) {
                $failures[] = 'a device was registered with a one-character name, so the revocation '.
                    'list is a list nobody can act on';
            } elseif (! str_contains((string) $unnamed, 'nine on a Saturday evening')) {
                /*
                 | And the sentence is the service's, not the column's.
                 |
                 | The constraint refuses a short name too, which is the
                 | control - but "violates check constraint
                 | devices_are_named" is read by whoever is setting up a
                 | shop, and it tells them nothing about what the name
                 | is for.
                 */
                $failures[] = 'a nameless device is refused by the column rather than explained by the '.
                    'service: '.(string) $unnamed;
            }

            $wrongKind = $this->attempt(fn () => $devices->register(
                $this->tenant,
                'A thing of some kind',
                'fridge',
                str_repeat('0', 26),
            ));

            if ($wrongKind === null) {
                $failures[] = 'a device of an unknown kind was registered, and the kind decides what it '.
                    'is allowed to do';
            }

            $results['a device is named, and of a kind this release knows'] = $failures;
        } finally {
            DB::table('devices')->where('tenant_id', (string) $this->tenant->getKey())->delete();
        }

        return $results;
    }

    // ---- Idempotency ------------------------------------------------------

    /** @return list<string> */
    private function aWriteIsNamed(): array
    {
        $refusal = $this->refuses(fn () => $this->submit('', ['amount' => '10']));

        if ($refusal === null) {
            return ['a write with no client id was accepted, so a retry cannot be told from a second sale'];
        }

        return str_contains((string) $refusal, 'indistinguishable')
            ? []
            : ['the refusal does not say why the id has to come from the device: '.(string) $refusal];
    }

    /** @return list<string> */
    private function replayingIsSafe(): array
    {
        $id = 'till-2-'.Str::random(12);
        $applied = 0;

        $work = function (array $payload) use (&$applied): array {
            $applied++;

            return ['receipt' => 'R-100', 'why' => 'the sale was recorded as receipt R-100'];
        };

        $failures = [];
        $first = $this->submit($id, ['amount' => '10'], apply: $work);
        $second = $this->submit($id, ['amount' => '10'], apply: $work);

        if ($applied !== 1) {
            $failures[] = sprintf(
                'the work ran %d time(s) for one client id - a network that drops a response looks '.
                'exactly like one that drops a request, so the till retries and the day\'s takings double',
                $applied,
            );
        }

        if (($first['outcome'] ?? '') !== 'applied') {
            $failures[] = sprintf('the first attempt reports %s', (string) ($first['outcome'] ?? '-'));
        }

        if (($second['outcome'] ?? '') !== 'replayed') {
            $failures[] = sprintf(
                'the second attempt reports %s rather than a replay, so the till cannot tell that its '.
                'retry was understood',
                (string) ($second['outcome'] ?? '-'),
            );
        }

        $rows = (int) DB::connection('tenant')->table('sync_submissions')->where('client_id', $id)->count();

        if ($rows !== 1) {
            $failures[] = sprintf('one client id produced %d submission rows', $rows);
        }

        return $failures;
    }

    /** @return list<string> */
    private function theReplayIsTheSameAnswer(): array
    {
        $id = 'till-2-'.Str::random(12);
        $counter = 0;

        /*
         | The work returns a DIFFERENT receipt each time it runs.
         |
         | So a replay that re-ran it would be caught by the number
         | changing, which is what the till is showing to a customer. A
         | check whose work returned a constant would pass whether the
         | answer was stored or recomputed.
         */
        $work = function (array $payload) use (&$counter): array {
            $counter++;

            return [
                'receipt' => 'R-'.$counter,
                'why' => sprintf('the sale was recorded as receipt R-%d', $counter),
            ];
        };

        $first = $this->submit($id, ['amount' => '10'], apply: $work);
        $second = $this->submit($id, ['amount' => '10'], apply: $work);

        $failures = [];

        if (($first['result']['receipt'] ?? '') !== 'R-1') {
            $failures[] = 'the fixture is wrong: the first attempt did not produce R-1';
        }

        if (($second['result']['receipt'] ?? '') !== 'R-1') {
            $failures[] = sprintf(
                'the replay answered with %s rather than the original R-1 - the till is showing a '.
                'figure to a customer, and a second total is worse than a slow one',
                (string) ($second['result']['receipt'] ?? '-'),
            );
        }

        if (! str_contains((string) ($second['why'] ?? ''), 'arrived before')) {
            $failures[] = 'the replay does not say it is one, so a client cannot distinguish it from a '.
                'fresh application';
        }

        return $failures;
    }

    /** @return list<string> */
    private function theIndexSerialisesTheRace(): array
    {
        $id = 'till-2-'.Str::random(12);

        $this->submit($id, ['amount' => '10']);

        /*
         | The second arrival written straight to the table.
         |
         | Which is the case a read-then-write check cannot see: a till
         | retrying while the first attempt is still in flight. The
         | unique index is what settles it, and it is the only thing
         | that can.
         */
        $direct = $this->refuses(fn () => DB::connection('tenant')->table('sync_submissions')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'client_id' => $id,
            'device_id' => $this->deviceId,
            'kind' => 'sale',
            'payload' => json_encode(['amount' => '10'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'received_at' => now(),
            'state' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $failures = $direct === null
            ? ['the database accepted a second submission with one client id, so two attempts in '.
                'flight at once both succeed and the sale is recorded twice']
            : [];

        /*
         | And the row is claimed BEFORE the work runs.
         |
         | Which is what makes the index able to do that job. If the
         | work ran first and the row were written after it, two
         | attempts in flight would both pass the read-then-write check,
         | both do the work, and only the second insert would fail -
         | by which time the sale is recorded twice and one of the two
         | transactions rolls back a record that is already correct.
         |
         | Asserted from inside the work itself, because the ordering is
         | invisible from outside: both orders end with one row.
         */
        $claimed = null;
        $second = 'till-2-'.Str::random(12);

        $this->submit($second, ['amount' => '10'], apply: function (array $payload) use ($second, &$claimed): array {
            $claimed = (string) DB::connection('tenant')->table('sync_submissions')
                ->where('client_id', $second)
                ->value('state');

            return ['why' => 'recorded as a sale'];
        });

        if ($claimed !== 'accepted') {
            $failures[] = sprintf(
                'the submission was %s while its work was running, so the row is written after the '.
                'work rather than before it - and the unique index cannot serialise two attempts that '.
                'are both in flight',
                $claimed === null ? 'not there at all' : $claimed,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function rejectionsAreRecorded(): array
    {
        $id = 'till-2-'.Str::random(12);
        $attempts = 0;

        $work = function (array $payload) use (&$attempts): array {
            $attempts++;

            throw SyncRefused::because('That item does not exist in this catalogue.');
        };

        $failures = [];
        $first = $this->submit($id, ['item' => 'ghost'], apply: $work);
        $second = $this->submit($id, ['item' => 'ghost'], apply: $work);

        if (($first['outcome'] ?? '') !== 'rejected') {
            $failures[] = sprintf('a refusal with no two sides reports %s rather than a rejection', (string) ($first['outcome'] ?? '-'));
        }

        if ($attempts !== 1) {
            $failures[] = sprintf(
                'the work ran %d time(s) - a rejection that left no row is retried forever by a till '.
                'that cannot parse the error, and the queue on the device never empties',
                $attempts,
            );
        }

        if (($second['outcome'] ?? '') !== 'replayed') {
            $failures[] = 'retrying a rejected id was treated as new work';
        }

        if ((string) DB::connection('tenant')->table('sync_submissions')->where('client_id', $id)->value('state') !== 'rejected') {
            $failures[] = 'the rejection was not recorded as one';
        }

        return $failures;
    }

    // ---- Conflicts --------------------------------------------------------

    /** @return list<string> */
    private function conflictsAreSurfaced(): array
    {
        $id = 'till-2-'.Str::random(12);

        $outcome = $this->submit($id, ['item' => 'LAST-ONE', 'quantity' => '3'], apply: function (array $payload): array {
            throw SyncRefused::aConflict('sold 3 of LAST-ONE', 'the shelf holds 1');
        });

        $failures = [];

        if (($outcome['outcome'] ?? '') !== 'conflicted') {
            return [sprintf('a disagreement about the world reports %s', (string) ($outcome['outcome'] ?? '-'))];
        }

        /*
         | Both sides on the row.
         |
         | "The till sold 3 and the shelf had 1" is a decision somebody
         | can make. A conflict recording only "refused" sends them to
         | two screens and a clock.
         */
        foreach ([['device_said', 'sold 3 of LAST-ONE'], ['server_says', 'the shelf holds 1']] as [$key, $expected]) {
            if (($outcome[$key] ?? '') !== $expected) {
                $failures[] = sprintf(
                    'the conflict reports %s as "%s" rather than "%s"',
                    $key,
                    (string) ($outcome[$key] ?? '-'),
                    $expected,
                );
            }
        }

        $open = app(Submissions::class)->openConflicts($this->entityId);

        if (\count($open) !== 1) {
            $failures[] = sprintf('%d conflict(s) are waiting for a decision rather than 1', \count($open));
        }

        /*
         | Both sides on the ROW, and in the queue a person reads.
         |
         | The first draft asserted them only on the value `submit()`
         | returned, which reads the refusal rather than the record - so
         | a bite that wrote "refused" into the column changed nothing
         | the check could see, and the queue would have shown a
         | conflict with one side missing.
         */
        $row = DB::connection('tenant')->table('sync_conflicts')
            ->where('submission_id', DB::connection('tenant')->table('sync_submissions')
                ->where('client_id', $id)
                ->value('id'))
            ->first();

        foreach ([
            ['what_the_device_said', 'sold 3 of LAST-ONE'],
            ['what_the_server_says', 'the shelf holds 1'],
        ] as [$column, $expected]) {
            if ((string) ($row->$column ?? '') !== $expected) {
                $failures[] = sprintf(
                    'the conflict row holds "%s" as %s rather than "%s", so the person deciding it is '.
                    'shown one side',
                    (string) ($row->$column ?? '-'),
                    $column,
                    $expected,
                );
            }
        }

        if (($open[0]['server_says'] ?? '') !== 'the shelf holds 1') {
            $failures[] = sprintf(
                'the queue shows "%s" as the server\'s side, so a decision is asked for without the '.
                'figure it turns on',
                (string) ($open[0]['server_says'] ?? '-'),
            );
        }

        /*
         | And nothing was merged.
         |
         | The submission is `conflicted`, not `applied`: a
         | last-write-wins rule would have recorded the sale and decided
         | the question by which shop has better wifi.
         */
        $state = (string) DB::connection('tenant')->table('sync_submissions')
            ->where('client_id', $id)
            ->value('state');

        if ($state !== 'conflicted') {
            $failures[] = sprintf('the submission is %s, so the disagreement was decided rather than raised', $state);
        }

        if (! str_contains((string) ($outcome['why'] ?? ''), 'not merged and not discarded')) {
            $failures[] = 'the answer does not say that somebody has to decide it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function conflictsAreDecided(): array
    {
        $conflictId = $this->aConflict();
        $sync = app(Submissions::class);
        $failures = [];

        $silent = $this->refuses(fn () => $sync->resolve($conflictId, 'ok', $this->actorId()));

        if ($silent === null) {
            $failures[] = 'a conflict about money was closed with "ok" - this row is the only record '.
                'that the decision was made';
        }

        $sync->resolve($conflictId, 'we honoured the sale and wrote the missing unit off', $this->actorId());

        $row = DB::connection('tenant')->table('sync_conflicts')->where('id', $conflictId)->first();

        if ((string) ($row->state ?? '') !== 'resolved') {
            $failures[] = 'the conflict is not resolved after being decided';
        }

        if (($row->decided_by ?? null) === null || ($row->decided_at ?? null) === null) {
            $failures[] = 'the decision has no name or no time against it';
        }

        /*
         | And the database refuses a closed conflict with no decision.
         |
         | Because the way a queue gets emptied quietly is an UPDATE
         | that sets the state.
         */
        $direct = $this->refuses(fn () => DB::connection('tenant')->table('sync_conflicts')
            ->where('id', $this->aConflict())
            ->update(['state' => 'resolved']));

        if ($direct === null) {
            $failures[] = 'a conflict can be marked resolved with nobody deciding it, so the queue can '.
                'be emptied by a script';
        }

        return $failures;
    }

    /** @return list<string> */
    private function conflictsSettleOnce(): array
    {
        $conflictId = $this->aConflict();
        $sync = app(Submissions::class);

        $sync->resolve($conflictId, 'we honoured the sale and wrote the missing unit off', $this->actorId());

        $again = $this->refuses(fn () => $sync->resolve(
            $conflictId,
            'on reflection we refunded the customer instead',
            $this->secondActorId(),
        ));

        return $again === null
            ? ['a decided conflict was decided again, leaving two records of one decision about the '.
                'same money']
            : [];
    }

    // ---- The clock --------------------------------------------------------

    /** @return list<string> */
    private function orderingIsServerSide(): array
    {
        /*
         | Three writes, arriving in order, with clocks that disagree.
         |
         | The middle one claims last month - a tablet that spent a
         | month in a drawer - and the last one claims next year. If
         | anything ordered by the device's clock, the order would come
         | out 2, 1, 3.
         */
        $ids = [];

        foreach ([
            ['first', '2026-05-18 10:00:00'],
            ['second', '2026-04-18 10:00:00'],
            ['third', '2027-05-18 10:00:00'],
        ] as [$label, $claimed]) {
            $id = 'till-'.$label.'-'.Str::random(8);
            $ids[] = $id;
            $this->submit($id, ['label' => $label], occurredAt: Carbon::parse($claimed));
        }

        $log = app(Submissions::class)->logFor($this->entityId, $this->deviceId);
        $order = array_reverse(array_column($log, 'client_id'));

        $failures = [];

        if ($order !== $ids) {
            $failures[] = sprintf(
                'the log is not in the order the writes arrived, so a till with the wrong date decides '.
                'what happened first - sent %s, read back %s',
                implode(', ', $ids),
                implode(', ', $order),
            );
        }

        /*
         | And the id comes back exactly as it was sent.
         |
         | `client_id` was `char(64)` in the first draft, and `char` is
         | blank-padded in PostgreSQL - so a 20-character id came back
         | as 20 characters and 44 spaces. The till cannot then match
         | our answer to its own queue entry, which is the one thing the
         | column exists for. This check found it by comparing the order
         | against the ids it sent.
         */
        foreach ($order as $index => $readBack) {
            if ($readBack !== rtrim($readBack)) {
                $failures[] = sprintf(
                    'client id %d comes back padded ("%s"), so the till cannot match our answer to its '.
                    'own queue entry',
                    $index + 1,
                    $readBack,
                );
            }
        }

        /*
         | And the sequence is strictly increasing with arrival.
         |
         | Two submissions in the same microsecond are possible, which
         | is why this is a sequence and not a timestamp.
         */
        $sequences = array_reverse(array_column($log, 'sequence'));
        $sorted = $sequences;
        sort($sorted);

        if ($sequences !== $sorted || \count(array_unique($sequences)) !== \count($sequences)) {
            $failures[] = 'the arrival sequence is not a strict total order, so a replay cannot '.
                'reproduce what happened';
        }

        return $failures;
    }

    /** @return list<string> */
    private function skewIsVisible(): array
    {
        $id = 'till-skew-'.Str::random(8);

        $outcome = $this->submit(
            $id,
            ['amount' => '10'],
            occurredAt: Carbon::now()->subMonth(),
        );

        $failures = [];

        if (abs((int) ($outcome['skew']['seconds'] ?? 0)) < 86_400) {
            $failures[] = sprintf(
                'a clock a month out reports a skew of %d seconds, so "the till says 400 and the '.
                'server says 380" stays unanswerable',
                (int) ($outcome['skew']['seconds'] ?? 0),
            );
        }

        if (! str_contains((string) ($outcome['skew']['why'] ?? ''), 'used for nothing')) {
            $failures[] = 'the answer does not say that the device\'s clock dates nothing, which is '.
                'the thing somebody has to be able to rely on';
        }

        /*
         | And the stored row keeps both.
         |
         | The device's claim, so the skew is auditable, and the arrival,
         | so nothing is dated by the claim.
         */
        $row = DB::connection('tenant')->table('sync_submissions')->where('client_id', $id)->first();

        if (Carbon::parse((string) $row?->occurred_at)->isSameDay(Carbon::parse((string) $row?->received_at))) {
            $failures[] = 'the device\'s clock and the arrival time are the same, so the claim was '.
                'overwritten rather than recorded';
        }

        return $failures;
    }

    // ---- The log ----------------------------------------------------------

    /** @return list<string> */
    private function theLogAnswersTheQuestion(): array
    {
        $id = 'till-log-'.Str::random(8);

        $this->submit($id, ['amount' => '400'], apply: static fn (array $p): array => [
            'why' => 'the sale was recorded at 380 because one line was out of stock',
        ]);

        $log = app(Submissions::class)->logFor($this->entityId, $this->deviceId);
        $failures = [];

        if ($log === []) {
            return ['the log is empty, so "the till says 400 and the server says 380" is unanswerable'];
        }

        $row = $log[0];

        foreach (['client_id', 'device_id', 'kind', 'state', 'device_said', 'arrived', 'outcome'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $failures[] = sprintf('the log carries no %s', $field);
            }
        }

        if (! str_contains((string) ($row['outcome'] ?? ''), 'out of stock')) {
            $failures[] = 'the log does not say what the server did with the write, which is the half '.
                'of the question the till cannot answer';
        }

        /*
         | And a log scoped to one device.
         |
         | "What did till 2 send" is the question; a list of everything
         | every device sent is one nobody reads.
         */
        $others = array_filter($log, fn (array $entry): bool => $entry['device_id'] !== $this->deviceId);

        if ($others !== []) {
            $failures[] = 'the log for one device includes another device\'s writes';
        }

        return $failures;
    }

    /** @return list<string> */
    private function submissionsAreFacts(): array
    {
        $id = 'till-fact-'.Str::random(8);
        $this->submit($id, ['amount' => '10'], apply: static fn (array $p): array => ['why' => 'recorded as a sale']);

        $rowId = (string) DB::connection('tenant')->table('sync_submissions')->where('client_id', $id)->value('id');
        $failures = [];

        foreach ([
            'what the device sent' => ['payload' => json_encode(['amount' => '1'], JSON_THROW_ON_ERROR)],
            'when the device said it happened' => ['occurred_at' => now()->subYear()],
            'the order it arrived in' => ['received_seq' => 1],
            'the outcome it settled to' => ['state' => 'conflicted'],
        ] as $label => $change) {
            if ($this->refuses(fn () => DB::connection('tenant')->table('sync_submissions')
                ->where('id', $rowId)
                ->update($change)) === null) {
                $failures[] = sprintf(
                    '%s can be changed after the fact, which makes the difference between the till and '.
                    'the server unexplainable',
                    $label,
                );
            }
        }

        if ($this->refuses(fn () => DB::connection('tenant')->table('sync_submissions')
            ->where('id', $rowId)
            ->delete()) === null) {
            $failures[] = 'a submission can be deleted, so the record of what a device sent is '.
                'removable by whoever disagrees with it';
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function submit(
        string $clientId,
        array $payload,
        ?Carbon $occurredAt = null,
        ?callable $apply = null,
    ): array {
        return app(Submissions::class)->submit(
            entityId: $this->entityId,
            deviceId: $this->deviceId,
            clientId: $clientId,
            kind: 'sale',
            payload: $payload,
            occurredAt: $occurredAt ?? Carbon::now(),
            apply: $apply ?? static fn (array $p): array => ['why' => 'recorded as a sale'],
        );
    }

    /** An open conflict, for the checks about deciding one. */
    private function aConflict(): string
    {
        $outcome = $this->submit(
            'till-conflict-'.Str::random(10),
            ['item' => 'LAST-ONE', 'quantity' => '3'],
            apply: static function (array $payload): array {
                throw SyncRefused::aConflict('sold 3 of LAST-ONE', 'the shelf holds 1');
            },
        );

        return (string) $outcome['conflict_id'];
    }

    /**
     * A refusal from a write to the TENANT database, inside a savepoint.
     *
     * PostgreSQL aborts the whole transaction on a constraint violation,
     * so a probe that expects one has to be wrapped - otherwise every
     * statement after it fails with "current transaction is aborted" and
     * the check reports a confusing error instead of its finding. Which
     * it did, the first time this was written with {@see attempt()}.
     *
     * {@see attempt()} stays for the DEVICE checks, which run against
     * the operator's database and hold no transaction at all.
     */
    private function refuses(callable $work): ?string
    {
        $savepoint = 'sy_'.bin2hex(random_bytes(4));

        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $work();
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return $e::class.': '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300);
        }
    }

    /**
     * Run something and return its refusal, WITHOUT a savepoint.
     *
     * The device checks run against the operator's database, which this
     * suite does not hold a transaction on - so `refusal()`'s savepoint
     * would roll back the tenant connection instead, which is a
     * different database and a confusing failure.
     */
    private function attempt(callable $work): ?string
    {
        try {
            $work();

            return null;
        } catch (Throwable $e) {
            return $e::class.': '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300);
        }
    }
}
