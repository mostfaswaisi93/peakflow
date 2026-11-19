<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A tenant can be handed everything it owns, and nothing else - PF-239.
 *
 * ## The second half is the one worth checking
 *
 * "Everything it owns" is an arithmetic question - do the row counts match -
 * and arithmetic is easy to assert. "Nothing else" is the one that has to be
 * driven adversarially, because the way it fails is that somebody reaches
 * for a convenience: a join to the platform registry to put the plan name in
 * the manifest, a lookup of who provisioned it, a `SELECT` on the operator
 * table to label the export.
 *
 * Each of those is one line and each puts another customer's name in a file
 * this customer is about to be given.
 *
 * So the check takes a REAL identifier belonging to a different tenant - its
 * id and its slug, read from the registry - and asserts neither appears
 * anywhere in the export. That is the assertion that fails the day somebody
 * adds the convenience.
 */
final class TenantExportRules
{
    private string $directory = '';

    private ?Tenant $subject = null;

    /**
     * The first tenant whose database holds an entity.
     *
     * @param  \Illuminate\Support\Collection<int, Tenant>  $tenants
     */
    private function oneHoldingAnEntity(\Illuminate\Support\Collection $tenants): ?Tenant
    {
        $resolver = app(TenantConnectionResolver::class);

        foreach ($tenants as $tenant) {
            try {
                $resolver->point($tenant);

                if (DB::connection('tenant')->table('entities')->exists()) {
                    return $tenant;
                }
            } catch (Throwable) {
                // A tenant whose database cannot be reached is not a fixture.
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenants = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->whereNotNull('provisioned_at')
            ->orderBy('slug')
            ->get();

        if ($tenants->count() < 2) {
            return ['the tenant export (fewer than two tenants provisioned - skipped)' => []];
        }

        /*
         | A tenant that actually holds an entity, not the first by slug.
         |
         | Alphabetical order picks `acme`, which holds none - and the
         | attachment probe needs one, so the check reported "the tenant
         | holds no entity" rather than anything about exports. The same
         | selection mistake as choosing a fixture by `created_at` among
         | rows written in the same second: it looks deterministic, and what
         | it is deterministic about is not what is being tested.
         */
        $subject = $this->oneHoldingAnEntity($tenants) ?? $tenants->first();
        $other = $tenants->first(fn (Tenant $t): bool => (string) $t->getKey() !== (string) $subject->getKey());

        if ($other === null) {
            return ['the tenant export (only one usable tenant - skipped)' => []];
        }

        $this->subject = $subject;

        app(TenantConnectionResolver::class)->point($subject);

        $this->directory = sys_get_temp_dir().'/peakflow-export-'.Str::random(8);

        try {
            $manifest = app(TenantExport::class)->write($subject, $this->directory);

            return [
                'every logical table is written, and no partition is written twice'
                    => $this->everyTableOnce($manifest),
                'the row counts in the manifest are the row counts in the database'
                    => $this->countsAgree($manifest),
                'an attachment\'s bytes travel with it'
                    => $this->attachmentsTravel(),
                'no other tenant appears anywhere in the archive'
                    => $this->nothingElse($other),
            ];
        } catch (Throwable $e) {
            return ['the tenant export could not be written' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function everyTableOnce(array $manifest): array
    {
        $connection = DB::connection('tenant');

        $partitions = array_map(
            static fn (object $row): string => (string) $row->relname,
            $connection->select('SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid'),
        );

        $all = array_map(
            static fn (object $row): string => (string) $row->table_name,
            $connection->select(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'",
            ),
        );

        $logical = array_values(array_diff($all, $partitions));
        $written = array_keys($manifest['tables']);

        $problems = [];

        foreach (array_diff($logical, $written) as $missing) {
            $problems[] = sprintf(
                '`%s` exists in the schema and is not in the export. A tenant handed an archive with '.
                'a table missing has been handed something that looks complete',
                $missing,
            );
        }

        /*
         | And no partition is written on its own.
         |
         | `journal_lines` is RANGE partitioned and so is `audit_log`: this
         | schema has 191 logical tables behind more than a thousand
         | partitions. Writing both the parent and its partitions puts every
         | row in twice, and the reader - having no reason to suspect it -
         | imports a ledger with doubled balances.
         */
        foreach (array_intersect($written, $partitions) as $twice) {
            $problems[] = sprintf(
                '`%s` is a partition and has a file of its own. Its rows are already in its parent, '.
                'so every one of them is in the archive twice - and a ledger imported from it has '.
                'doubled balances with nothing saying so',
                $twice,
            );
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function countsAgree(array $manifest): array
    {
        $connection = DB::connection('tenant');
        $problems = [];
        $checked = 0;

        foreach ($manifest['tables'] as $table => $entry) {
            $actual = (int) $connection->selectOne(sprintf('SELECT count(*) AS c FROM %s', $connection->getQueryGrammar()->wrapTable($table)))->c;

            if ($actual !== (int) $entry['rows']) {
                $problems[] = sprintf(
                    '`%s` holds %d row(s) and the export wrote %d',
                    (string) $table,
                    $actual,
                    (int) $entry['rows'],
                );
            }

            $lines = $this->lineCount($this->directory.'/tables/'.$table.'.jsonl');

            if ($lines !== (int) $entry['rows']) {
                $problems[] = sprintf(
                    '`%s` has %d line(s) on disk and a manifest claiming %d. The manifest is what a '.
                    'recipient checks the transfer against, and one that disagrees with the file is '.
                    'worse than none',
                    (string) $table,
                    $lines,
                    (int) $entry['rows'],
                );
            }

            if ((int) $entry['rows'] > 0) {
                $checked++;
            }
        }

        if ($checked === 0) {
            $problems[] = 'no table in the export has a single row, so the counts agreed about '.
                'nothing - which is how an export check comes to pass against an empty archive';
        }

        return $problems;
    }

    /**
     * The bytes behind an attachment are in the archive.
     *
     * Written here rather than hoped for: the fixture holds no attachment,
     * and a check that only ran when one happened to exist would be a check
     * that never ran. The row and its object are created, the export is
     * taken again, and both are removed.
     *
     * @return list<string>
     */
    private function attachmentsTravel(): array
    {
        $connection = DB::connection('tenant');

        $entityId = (string) $connection->table('entities')->orderBy('code')->value('id');

        if ($entityId === '') {
            return ['the tenant holds no entity, so no attachment could be made'];
        }

        /*
         | The entity scope, established before the write.
         |
         | `attachment_blobs` is entity-scoped with forced row-level
         | security, so an insert with no scope is refused by the database -
         | correctly, and it is the control working rather than a fixture
         | problem to route around.
         */
        $connection->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$entityId]);

        $bytes = '%PDF-1.7 export probe '.Str::random(16)."\n%%EOF\n";
        $disk = \Illuminate\Support\Facades\Storage::disk((string) config('attachments.disk', 'local'));
        $path = 'export-probe/'.Str::random(12).'.pdf';
        $disk->put($path, $bytes);

        $id = (string) Str::ulid();

        $connection->table('attachment_blobs')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'content_hash' => hash('sha256', $bytes),
            'disk' => (string) config('attachments.disk', 'local'),
            'path' => $path,
            'byte_size' => \strlen($bytes),
            'content_type' => 'application/pdf',
            'scan_result' => 'clean',
            'scanned_at' => now(),
            'scanner' => 'export probe',
            'reference_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $directory = sys_get_temp_dir().'/peakflow-export-attach-'.Str::random(8);

        try {
            $manifest = app(TenantExport::class)->write($this->subject, $directory);

            $problems = [];

            if ($manifest['attachments']['missing'] !== []) {
                $problems[] = 'an attachment whose object is on the disk was reported missing';
            }

            $written = $directory.'/attachments/'.$id;

            if (! is_file($written)) {
                $problems[] = 'the attachment\'s bytes are not in the archive. The rows say a '.
                    'document has evidence attached and the evidence is not there, which is an '.
                    'archive that looks complete';
            } elseif ((string) file_get_contents($written) !== $bytes) {
                $problems[] = 'the attachment in the archive is not the bytes that were stored';
            }

            return $problems;
        } finally {
            $connection->table('attachment_blobs')->where('id', $id)->delete();
            $disk->delete($path);
            $this->remove($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function nothingElse(Tenant $other): array
    {
        $needles = array_filter([
            'the other tenant\'s id' => (string) $other->getKey(),
            'the other tenant\'s slug' => (string) $other->slug,
            'the other tenant\'s name' => (string) $other->name,
        ], static fn (string $value): bool => mb_strlen($value) >= 4);

        $problems = [];

        foreach ($this->filesIn($this->directory) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($needles as $what => $needle) {
                if (str_contains($contents, $needle)) {
                    $problems[] = sprintf(
                        '%s appears in %s. The way this fails is that somebody reaches for a '.
                        'convenience - a join to the registry for the plan name, a lookup of who '.
                        'provisioned it - and each of those is one line that puts another customer '.
                        'in a file this customer is about to be given',
                        $what,
                        basename($file),
                    );
                }
            }
        }

        if ($problems === [] && $needles === []) {
            return ['the other tenant has no identifier long enough to search for, so this proved '.
                'nothing'];
        }

        return $problems;
    }

    /** @return list<string> */
    private function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function lineCount(string $path): int
    {
        if (! is_file($path)) {
            return -1;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return -1;
        }

        $lines = 0;

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return $lines;
    }

    private function cleanUp(): void
    {
        $this->remove($this->directory);
    }

    private function remove(string $directory): void
    {
        foreach ($this->filesIn($directory) as $file) {
            @unlink($file);
        }

        @rmdir($directory.'/tables');
        @rmdir($directory.'/attachments');
        @rmdir($directory);
    }
}
