<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Everything one tenant owns, and nothing else - PF-239.
 *
 * ## What "nothing else" rests on
 *
 * The tenant's data is in the tenant's own database, so the boundary is not
 * something this class enforces - it is something the architecture already
 * decided, and this class is careful not to cross it. It reads the `tenant`
 * connection and never the `platform` one: the platform registry holds every
 * OTHER tenant's name, slug and plan, and a dump that reached into it to
 * answer "who is this export for" would put them in the file.
 *
 * The one thing it takes from outside is the slug it was given, written into
 * the manifest as a label. That is the tenant's own.
 *
 * ## Logical tables only
 *
 * `journal_lines` is RANGE partitioned and `audit_log` is too: this schema
 * has 191 logical tables behind 1,270 partitions. Dumping both the parent
 * and its partitions writes **every row twice** - and the reader, having no
 * reason to suspect it, imports a ledger with doubled balances.
 *
 * So partitions are excluded by asking `pg_inherits` which tables have a
 * parent, rather than by matching names: a partition named for its month is
 * a convention, and the catalog is a fact.
 *
 * ## JSON Lines, not one JSON document
 *
 * One row per line. A 10-million-line `journal_lines` as a single JSON array
 * cannot be written without holding it in memory and cannot be read without
 * the same - so the export would work on a fixture and fail on the tenant
 * who asked for it. Line-delimited streams in both directions.
 *
 * ## The manifest is what makes it checkable
 *
 * Per table: the row count and a sha256 of the file. An archive without one
 * is a directory somebody has to trust; with one, a truncated transfer is
 * found by the person who received it rather than by the person who later
 * relied on it.
 */
final class TenantExport
{
    /**
     * Write the export under `$directory` and return the manifest.
     *
     * @return array<string, mixed>
     */
    public function write(Tenant $tenant, string $directory): array
    {
        $connection = DB::connection('tenant');

        $tables = $this->logicalTables($connection);

        @mkdir($directory.'/tables', 0o755, true);
        @mkdir($directory.'/attachments', 0o755, true);

        $manifest = [
            'tenant' => (string) $tenant->slug,
            'written_at' => now()->toIso8601ZuluString(),
            'tables' => [],
            'attachments' => [],
        ];

        foreach ($tables as $table) {
            $path = $directory.'/tables/'.$table.'.jsonl';
            $handle = fopen($path, 'wb');

            if ($handle === false) {
                continue;
            }

            $rows = 0;

            /*
             | Cursored, so the memory used is one row and not one table.
             |
             | `get()` on a table with years of journal lines is the same
             | mistake as the single JSON document, one layer down.
             */
            foreach ($connection->table($table)->orderBy($this->orderColumn($connection, $table))->cursor() as $row) {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                $rows++;
            }

            fclose($handle);

            $manifest['tables'][$table] = [
                'rows' => $rows,
                'sha256' => hash_file('sha256', $path),
            ];
        }

        $manifest['attachments'] = $this->writeAttachments($connection, $directory);

        file_put_contents(
            $directory.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        return $manifest;
    }

    /**
     * The bytes behind every attachment this tenant holds.
     *
     * By blob rather than by claim: the store deduplicates, so two documents
     * carrying the same file are one object and two rows. Writing it per
     * claim would put the same bytes in the archive twice, and the rows in
     * `attachments.jsonl` already say which document claims which object.
     *
     * @return array<string, mixed>
     */
    private function writeAttachments(Connection $connection, string $directory): array
    {
        if (! $this->hasTable($connection, 'attachment_blobs')) {
            return ['written' => 0, 'missing' => []];
        }

        $written = 0;
        $missing = [];

        foreach ($connection->table('attachment_blobs')->orderBy('id')->cursor() as $blob) {
            /*
             | The blob names its own disk, and the export follows it.
             |
             | A tenant whose attachments were moved to a second disk - an
             | archival one, a region-local one - has rows pointing at both,
             | and an export reading only the configured default would write
             | an archive that silently omits the older half.
             */
            $path = (string) ($blob->path ?? '');
            $disk = Storage::disk((string) ($blob->disk ?: config('attachments.disk', 'local')));

            if ($path === '' || ! $disk->exists($path)) {
                /*
                 | Named, not skipped.
                 |
                 | A blob row whose object is gone is the one thing an export
                 | must not be quiet about: the archive would otherwise look
                 | complete while a document's evidence is absent, and the
                 | person who finds out is whoever needed the file.
                 */
                $missing[] = (string) $blob->id;

                continue;
            }

            file_put_contents($directory.'/attachments/'.(string) $blob->id, $disk->get($path));
            $written++;
        }

        return ['written' => $written, 'missing' => $missing];
    }

    /**
     * Tables with no parent: the logical ones.
     *
     * @return list<string>
     */
    private function logicalTables(Connection $connection): array
    {
        $partitions = array_map(
            static fn (object $row): string => (string) $row->relname,
            $connection->select('SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid'),
        );

        $all = array_map(
            static fn (object $row): string => (string) $row->table_name,
            $connection->select(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name",
            ),
        );

        return array_values(array_diff($all, $partitions));
    }

    /**
     * Something stable to order by, so two exports of the same data match.
     *
     * `id` where there is one, otherwise the first column. Not "no order at
     * all": PostgreSQL may return rows in any order it likes, and an export
     * whose bytes change between two runs of the same unchanged data cannot
     * be diffed, which is most of what an archive is for.
     */
    private function orderColumn(Connection $connection, string $table): string
    {
        $columns = array_map(
            static fn (object $row): string => (string) $row->column_name,
            $connection->select(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
                ['public', $table],
            ),
        );

        return \in_array('id', $columns, true) ? 'id' : ($columns[0] ?? '1');
    }

    private function hasTable(Connection $connection, string $table): bool
    {
        return $connection->selectOne(
            'SELECT 1 AS x FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            ['public', $table],
        ) !== null;
    }
}
