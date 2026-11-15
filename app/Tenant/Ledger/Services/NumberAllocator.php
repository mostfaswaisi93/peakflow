<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

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
 * ## The lock is held for as little as possible
 *
 * Every other writer for this series waits behind it, so the allocation is
 * the LAST thing before the insert and no I/O happens while it is held. A
 * rule that resolves accounts or reads configuration does so first.
 */
final class NumberAllocator
{
    public function next(string $entityId, string $scope, string $key, Carbon $date): string
    {
        $connection = DB::connection('tenant');

        return $connection->transaction(function () use ($connection, $entityId, $scope, $key, $date): string {
            /*
             | The series row, created on first use.
             |
             | `resets_annually` decides whether the year is part of the
             | identity: a series that should restart each year and does not
             | produces numbers that keep climbing, and one that should not
             | and does produces duplicates across years. Both are found by
             | an auditor rather than by a test.
             */
            $series = $connection->table('number_series')
                ->where('entity_id', $entityId)
                ->where('scope', $scope)
                ->where('key', $key)
                ->where('year', $date->year)
                ->lockForUpdate()
                ->first();

            if ($series === null) {
                $connection->table('number_series')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'scope' => $scope,
                    'key' => $key,
                    'prefix' => strtoupper($key).'-',
                    'padding' => 5,
                    'next_number' => 1,
                    'resets_annually' => true,
                    'year' => $date->year,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $series = $connection->table('number_series')
                    ->where('entity_id', $entityId)
                    ->where('scope', $scope)
                    ->where('key', $key)
                    ->where('year', $date->year)
                    ->lockForUpdate()
                    ->first();
            }

            $number = (int) $series->next_number;

            $connection->table('number_series')
                ->where('id', $series->id)
                ->update(['next_number' => $number + 1, 'updated_at' => now()]);

            return sprintf(
                '%s%s/%s',
                $series->prefix,
                $date->year,
                str_pad((string) $number, (int) $series->padding, '0', STR_PAD_LEFT),
            );
        });
    }
}
