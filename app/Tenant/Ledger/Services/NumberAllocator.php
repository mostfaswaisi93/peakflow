<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Numbering\NumberFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Allocates a gapless document number - ADR-0009.
 *
 * ## Why not a sequence
 *
 * `nextval()` is deliberately non-transactional: it does not roll back, so a
 * failed entry burns its number. That is exactly right for a surrogate key
 * and exactly wrong for a statutory numbering series, where a gap is a
 * question from an auditor that nobody can answer - a missing document number
 * is indistinguishable from a deleted document.
 *
 * So the counter is a row, locked with `SELECT ... FOR UPDATE`, and it rolls
 * back with the transaction that failed.
 *
 * ## It records what it issued, not only where it is going next
 *
 * `next_number` says where the series will go; it cannot say where it
 * started or whether anything was skipped. A series whose counter is at 400
 * having issued 250 documents is the question an auditor asks, and answering
 * it needs the first number, the last and the count - all three written
 * under the same lock, so they cannot disagree with each other.
 *
 * ## The lock is held for as little as possible
 *
 * Every other writer for this series waits behind it, so the allocation is
 * the LAST thing before the insert and no I/O happens while it is held. A
 * rule that resolves accounts or reads configuration does so first.
 */
final class NumberAllocator
{
    /** @var array<string, string> entity id => code, for the {entity} placeholder */
    private array $entityCodes = [];

    public function next(
        string $entityId,
        string $scope,
        string $key,
        Carbon $date,
        ?string $branch = null,
    ): string {
        $connection = DB::connection('tenant');

        return $connection->transaction(function () use ($connection, $entityId, $scope, $key, $date, $branch): string {
            /*
             | The series row, created on first use.
             |
             | `resets_annually` decides whether the year is part of the
             | identity: a series that should restart each year and does not
             | produces numbers that keep climbing, and one that should not
             | and does produces duplicates across years. Both are found by
             | an auditor rather than by a test.
             */
            $series = $this->lock($connection, $entityId, $scope, $key, $branch, $date);

            if ($series === null) {
                /*
                 | Created on first use, with a format rather than a prefix.
                 |
                 | `{key}-{YYYY}/{seq:5}` is what the old prefix-and-padding
                 | pair rendered, so a series created now is shaped like one
                 | created before the format column existed - which matters
                 | because the two can be the same series either side of a
                 | deploy, and a change of shape mid-sequence is the thing
                 | the freeze trigger exists to prevent.
                 */
                $connection->table('number_series')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'scope' => $scope,
                    'key' => $key,
                    'prefix' => strtoupper($key).'-',
                    'padding' => 5,
                    'format' => strtoupper($key).'-{YYYY}/{seq:5}',
                    'branch' => $branch,
                    'next_number' => 1,
                    'first_number' => 1,
                    'issued_count' => 0,
                    'resets_annually' => true,
                    'year' => $date->year,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $series = $this->lock($connection, $entityId, $scope, $key, $branch, $date);
            }

            $number = (int) $series->next_number;

            /*
             | The counter and the issuance record move together.
             |
             | One statement under one lock: if the count could be written
             | separately it would eventually be written separately, and the
             | gap check compares the two against each other - so a count
             | that lags by one reports a gap that is not there, which is a
             | fortnight of somebody's time.
             */
            $connection->table('number_series')
                ->where('id', $series->id)
                ->update([
                    'next_number' => $number + 1,
                    'last_issued_number' => $number,
                    'issued_count' => DB::raw('issued_count + 1'),
                    'last_issued_at' => now(),
                    'updated_at' => now(),
                ]);

            return NumberFormat::of((string) $series->format)->render($number, $date, [
                'entity' => $this->entityCode($connection, $entityId),
                'branch' => (string) ($series->branch ?? ''),
                'key' => strtoupper($key),
            ]);
        });
    }

    /**
     * Lock the series row, or null if it does not exist yet.
     *
     * A method rather than a repeated query, because the two copies in the
     * original drifted: the second one omitted the branch, so a per-branch
     * series created on first use was then read back as whichever branch's
     * row the planner reached first.
     */
    private function lock(
        \Illuminate\Database\Connection $connection,
        string $entityId,
        string $scope,
        string $key,
        ?string $branch,
        Carbon $date,
    ): ?object {
        return $connection->table('number_series')
            ->where('entity_id', $entityId)
            ->where('scope', $scope)
            ->where('key', $key)
            ->where('year', $date->year)
            ->where(fn ($query) => $branch === null
                ? $query->whereNull('branch')
                : $query->where('branch', $branch))
            ->lockForUpdate()
            ->first();
    }

    /**
     * The entity's code, for a `{entity}` placeholder.
     *
     * Read inside the transaction but BEFORE the counter is touched, so no
     * query happens while the series lock is held. Every other writer for
     * this series is waiting behind it.
     */
    private function entityCode(\Illuminate\Database\Connection $connection, string $entityId): string
    {
        return $this->entityCodes[$entityId] ??= (string) $connection->table('entities')
            ->where('id', $entityId)
            ->value('code');
    }
}
