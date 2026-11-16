<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Archive\Archive;
use App\Exchange\Archive\ArchiveRefused;
use App\Exchange\Profiles\GenericProfile;
use App\Exchange\Profiles\Zatca\ZatcaProfile;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The archive - PF-129.
 *
 * ## What is being defended
 *
 * Most jurisdictions require the original electronic document retained,
 * readable and **unaltered**, for five to eleven years. "Readable" is the
 * easy half. Unaltered means the bytes, and the three ways a system loses
 * them are all quiet:
 *
 * 1. It keeps the data and re-renders the document on request. Five years
 *    later the font, the template and a rounding policy have changed, and
 *    the hash the recipient holds matches nothing.
 * 2. An operator deletes a row - to tidy up, or because a tenant asked.
 * 3. Storage loses an object, or a restore puts back an older version, and
 *    nothing notices until an inspector asks.
 *
 * So: the bytes are stored, the database refuses to delete or change them
 * before the retention date, and the manifest hashes the **list** so that a
 * removal is detectable even when the row goes with the file.
 */
final class ArchiveRules
{
    use \Tests\Support\Concerns\IssuesInvoices;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the archive (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);

        /*
         | Fake whatever the configuration names, not the literal 'archive'.
         |
         | Measured: `.env` pointed `ARCHIVE_DISK` at `local` - a leftover
         | from the initial scaffolding, duplicated later by this task's own
         | block - so the archive wrote to the application's shared disk
         | while this suite faked one nothing used. Every storage assertion
         | passed by looking at an empty fake, and the drill "passed" on a
         | file it had deleted from somewhere else.
         |
         | Reading the config here means the suite checks the disk the
         | application actually writes to, and a misconfiguration shows up as
         | a failure rather than as a vacuous pass.
         */
        $disk = (string) config('archive.disk');
        Storage::fake($disk);

        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        /*
         | A real entity, because one check issues a real document.
         |
         | "The exact bytes that were sent" is the criterion, and the only
         | way to check it is to serialize a document the issuer produced and
         | compare the archived bytes against the serializer's output. A
         | synthetic entity id would let every other check pass and leave
         | that one untestable.
         */
        $this->seedTheSellingEntity();
        $entityId = $this->entityId;

        try {
            /*
             | A savepoint per check.
             |
             | Several of these work by making the database refuse something,
             | and in PostgreSQL a failed statement aborts the transaction:
             | every statement after it fails with "current transaction is
             | aborted". Measured on the first run - the WORM refusal took
             | the next fifteen checks with it and the report said the suite
             | could not be built.
             */
            return $this->runEachInASavepoint([
                'the exact bytes are stored and come back' => fn (): array => $this->roundTrip($entityId),
                'the archive holds what the serializer produced' => fn (): array => $this->theSentBytes($entityId),
                'a delete after the retention expires is allowed' => fn (): array => $this->expiredDelete($entityId),
                'archiving twice is one artefact' => fn (): array => $this->idempotent($entityId),
                'different bytes for the same artefact are refused' => fn (): array => $this->conflicting($entityId),
                'an unknown artefact kind is refused' => fn (): array => $this->unknownKind($entityId),
                'retention comes from the profile and is stated in words' => fn (): array => $this->retention($entityId),
                'the database refuses a delete before the retention date' => fn (): array => $this->wormDelete($entityId),
                'the bytes cannot be changed at all' => fn (): array => $this->wormUpdate($entityId),
                'retention cannot be shortened' => fn (): array => $this->shorteningRefused($entityId),
                'a tenant asking for deletion is told, not obeyed' => fn (): array => $this->retentionNotice($entityId),
                'a sealed manifest hashes the list, not just the files' => fn (): array => $this->manifest($entityId),
                'a manifest cannot be resealed' => fn (): array => $this->manifestIsSealed($entityId),
                'an artefact removed with its row is still detected' => fn (): array => $this->removalIsDetected($entityId),
                'a changed object is reported by verify' => fn (): array => $this->divergenceIsReported($entityId),
                'a restore drill records what it proved' => fn (): array => $this->drill($entityId),
                'a failing drill is recorded as failed' => fn (): array => $this->failingDrill($entityId),
                'the path carries the entity, the kind and the hash' => fn (): array => $this->pathShape($entityId),
            ]);
        } finally {
            /*
             | The WORM trigger refuses the rollback's own deletes? No - a
             | transaction rollback is not a DELETE and the trigger never
             | sees it. That is worth knowing: the retention rule binds
             | statements, not transactions, so a check can create rows and
             | roll them back without disabling anything.
             */
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function roundTrip(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $xml = "<Invoice><ID>INV-2026/00001</ID></Invoice>";
        $subject = (string) Str::ulid();

        $row = $archive->store(
            $entityId, 'invoice', $subject, 'sent_xml', $xml,
            app(GenericProfile::class), 'application/xml',
        );

        if ((string) $row['content_hash'] !== hash('sha256', $xml)) {
            $violations[] = 'the stored hash is not the hash of the bytes';
        }

        if ($archive->read($entityId, 'invoice', $subject, 'sent_xml') !== $xml) {
            $violations[] = 'the bytes that came back are not the bytes that went in. A re-rendered '.
                'document is not an archive copy, and neither is a nearly-identical one';
        }

        if (! Storage::disk((string) config('archive.disk'))->exists((string) $row['path'])) {
            $violations[] = 'the object is not at the path the row names';
        }

        return $violations;
    }

    /**
     * The archive holds the bytes the serializer produced.
     *
     * ## The criterion, stated exactly
     *
     * "The exact bytes that were sent." Not the data they were built from,
     * and not a promise to rebuild them: five years from now the font, the
     * template and a rounding policy have changed, and the hash the
     * recipient holds matches nothing a re-render produces.
     *
     * So this issues a real document, serializes it through the profile's
     * own serializer, archives that string, and requires the archived bytes
     * to be identical - and the hash to be the hash of those bytes rather
     * than of anything recomputed.
     *
     * @return list<string>
     */
    private function theSentBytes(string $entityId): array
    {
        $violations = [];
        $invoice = $this->issue();
        $profile = app(GenericProfile::class);

        $model = app(\App\Exchange\Builders\SemanticInvoiceBuilder::class)->build($invoice);
        $xml = $profile->serializer()->serialize($model);

        $row = app(Archive::class)->store(
            $entityId,
            'invoice',
            (string) $invoice->getKey(),
            'sent_xml',
            $xml,
            $profile,
            $profile->serializer()->mediaType(),
        );

        $back = app(Archive::class)->read($entityId, 'invoice', (string) $invoice->getKey(), 'sent_xml');

        if ($back !== $xml) {
            $violations[] = 'the archived bytes are not the serialized bytes';
        }

        if ((string) $row['content_hash'] !== hash('sha256', $xml)) {
            $violations[] = 'the stored hash is not the hash of what was sent';
        }

        /*
         | And a re-serialization is byte-identical, which is what makes the
         | comparison meaningful at all: a serializer that drifted would make
         | "the bytes that were sent" unverifiable even with them in hand.
         */
        if ($profile->serializer()->serialize($model) !== $xml) {
            $violations[] = 'the serializer is not deterministic, so nothing could be reconciled against '.
                'the archived copy';
        }

        if (! str_contains($back, (string) $invoice->number)) {
            $violations[] = 'the archived document does not carry the invoice number, so it is not the '.
                'document it claims to be';
        }

        return $violations;
    }

    /**
     * Retention expires, and then the row is deletable.
     *
     * The rule is a retention period, not immortality. A system that could
     * never delete would hold every tenant's documents forever, which is its
     * own compliance problem - the obligation is to keep them for a stated
     * period and the period ends.
     *
     * @return list<string>
     */
    private function expiredDelete(string $entityId): array
    {
        $row = app(Archive::class)->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<expired/>',
            app(GenericProfile::class), 'application/xml', Carbon::parse('2010-01-01'),
        );

        if (Carbon::parse((string) $row['retain_until'])->isFuture()) {
            return ['the fixture\'s retention has not expired, so this check proves nothing'];
        }

        try {
            DB::connection('tenant')->table('archived_artefacts')->where('id', (string) $row['id'])->delete();
        } catch (\Throwable $e) {
            return ['an artefact whose retention expired in 2020 could not be deleted: '.$e->getMessage()];
        }

        $remaining = DB::connection('tenant')->table('archived_artefacts')
            ->where('id', (string) $row['id'])
            ->count();

        return $remaining === 0 ? [] : ['the row survived its own delete'];
    }

    /** @return list<string> */
    private function idempotent(string $entityId): array
    {
        $archive = app(Archive::class);
        $xml = '<Invoice><ID>INV-2</ID></Invoice>';
        $subject = (string) Str::ulid();

        $first = $archive->store($entityId, 'invoice', $subject, 'sent_xml', $xml, app(GenericProfile::class), 'application/xml');
        $second = $archive->store($entityId, 'invoice', $subject, 'sent_xml', $xml, app(GenericProfile::class), 'application/xml');

        if ((string) $first['id'] !== (string) $second['id']) {
            return ['archiving the same artefact twice produced two rows. A retried job does this, and '.
                'two rows would mean two candidates for "the bytes that were sent"'];
        }

        $count = DB::connection('tenant')->table('archived_artefacts')
            ->where('subject_id', $subject)
            ->where('kind', 'sent_xml')
            ->count();

        return $count === 1 ? [] : [sprintf('%d rows exist for one artefact', $count)];
    }

    /** @return list<string> */
    private function conflicting(string $entityId): array
    {
        $archive = app(Archive::class);
        $subject = (string) Str::ulid();

        $archive->store($entityId, 'invoice', $subject, 'sent_xml', '<a/>', app(GenericProfile::class), 'application/xml');

        try {
            $archive->store($entityId, 'invoice', $subject, 'sent_xml', '<b/>', app(GenericProfile::class), 'application/xml');
        } catch (ArchiveRefused $e) {
            return str_contains($e->getMessage(), 'two candidates')
                ? []
                : ['the refusal does not explain why two are impossible: '.$e->getMessage()];
        }

        return ['a second, different set of bytes was archived for the same artefact. The recipient '.
            'holds one of them, and nothing here could say which'];
    }

    /** @return list<string> */
    private function unknownKind(string $entityId): array
    {
        try {
            app(Archive::class)->store(
                $entityId, 'invoice', (string) Str::ulid(), 'notes', '<a/>',
                app(GenericProfile::class), 'application/xml',
            );
        } catch (ArchiveRefused $e) {
            return str_contains($e->getMessage(), 'sent_xml')
                ? []
                : ['the refusal does not list the kinds the archive holds: '.$e->getMessage()];
        }

        return ['an undeclared artefact kind was archived. A kind nobody declared is an artefact no '.
            'manifest lists and no restore drill samples'];
    }

    /** @return list<string> */
    private function retention(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $at = Carbon::parse('2026-05-18');

        $generic = $archive->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<g/>',
            app(GenericProfile::class), 'application/xml', $at,
        );

        $zatca = $archive->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<z/>',
            app(ZatcaProfile::class), 'application/xml', $at,
        );

        if ((string) $generic['retain_until'] !== '2036-05-18') {
            $violations[] = sprintf(
                'the generic profile retains until %s and declares ten years',
                (string) $generic['retain_until'],
            );
        }

        if ((string) $zatca['retain_until'] !== '2032-05-18') {
            $violations[] = sprintf(
                'ZATCA retains until %s and the Saudi regulations say six years',
                (string) $zatca['retain_until'],
            );
        }

        foreach ([$generic, $zatca] as $row) {
            $basis = (string) $row['retention_basis'];

            if (! str_contains($basis, 'years') || ! str_contains($basis, '2026-05-18')) {
                $violations[] = sprintf(
                    'the retention basis is "%s". A date with no reason is a date somebody will argue '.
                    'with, and the person asking why a document cannot be deleted reads this',
                    $basis,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function wormDelete(string $entityId): array
    {
        $archive = app(Archive::class);
        $subject = (string) Str::ulid();

        $row = $archive->store(
            $entityId, 'invoice', $subject, 'sent_xml', '<worm/>',
            app(ZatcaProfile::class), 'application/xml',
        );

        try {
            DB::connection('tenant')->table('archived_artefacts')->where('id', (string) $row['id'])->delete();
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'must be retained')
                ? []
                : ['the refusal does not say the artefact must be retained: '.$e->getMessage()];
        }

        return ['an archived artefact was deleted inside its retention period. The obligation is the '.
            'tax authority\'s and a tenant cannot waive it on the authority\'s behalf - and an operator '.
            'tidying up takes the only record of what the bytes were'];
    }

    /** @return list<string> */
    private function wormUpdate(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $row = $archive->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<immutable/>',
            app(GenericProfile::class), 'application/xml',
        );

        $changes = [
            'content_hash' => str_repeat('a', 64),
            'path' => 'somewhere/else',
            'byte_size' => 1,
            'kind' => 'pdf',
        ];

        foreach ($changes as $column => $value) {
            try {
                DB::connection('tenant')->table('archived_artefacts')
                    ->where('id', (string) $row['id'])
                    ->update([$column => $value]);

                $violations[] = sprintf(
                    '%s was changed on an archived artefact. An archive copy that can be corrected is '.
                    'not an archive copy, and the recipient holds a hash of the original',
                    $column,
                );
            } catch (\Throwable) {
                // As intended.
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function shorteningRefused(string $entityId): array
    {
        $row = app(Archive::class)->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<shorten/>',
            app(GenericProfile::class), 'application/xml',
        );

        try {
            DB::connection('tenant')->table('archived_artefacts')
                ->where('id', (string) $row['id'])
                ->update(['retain_until' => Carbon::now()->subDay()->toDateString()]);
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'cannot be shortened')
                ? []
                : ['the refusal does not say retention cannot be shortened: '.$e->getMessage()];
        }

        return ['the retention date was moved earlier, which makes the document deletable before the '.
            'period it was archived under - the delete rule is only as good as the date it reads'];
    }

    /** @return list<string> */
    private function retentionNotice(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $subject = (string) Str::ulid();

        $archive->store(
            $entityId, 'invoice', $subject, 'sent_xml', '<notice/>',
            app(ZatcaProfile::class), 'application/xml', Carbon::parse('2026-05-18'),
        );

        $notice = $archive->retentionNoticeFor($entityId, 'invoice', $subject);

        if ($notice['retained'] !== 1) {
            $violations[] = 'the notice does not count what is retained';
        }

        if ($notice['until'] !== '2032-05-18') {
            $violations[] = sprintf('the notice says %s', (string) $notice['until']);
        }

        foreach (['en', 'ar'] as $locale) {
            if (trim((string) $notice[$locale]) === '') {
                $violations[] = sprintf('the notice has no %s text', $locale);
            }
        }

        if (! str_contains($notice['en'], '2032-05-18')) {
            $violations[] = 'the English notice does not name the date, which is the only part the '.
                'tenant can act on';
        }

        if (! str_contains($notice['en'], 'cannot be waived')) {
            $violations[] = 'the notice does not say whose obligation it is. "We cannot delete this" '.
                'without a reason reads as a product limitation rather than a legal one';
        }

        // And a document with nothing archived says so rather than inventing
        // a retention nobody owes.
        $nothing = $archive->retentionNoticeFor($entityId, 'invoice', (string) Str::ulid());

        if ($nothing['retained'] !== 0 || $nothing['until'] !== null) {
            $violations[] = 'a document with nothing archived was given a retention date';
        }

        return $violations;
    }

    /** @return list<string> */
    private function manifest(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $period = '2026-07';

        foreach (['<one/>', '<two/>', '<three/>'] as $bytes) {
            $archive->store(
                $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', $bytes,
                app(GenericProfile::class), 'application/xml',
                Carbon::parse('2026-07-15'), $period,
            );
        }

        $manifest = $archive->seal($entityId, $period);

        if ((int) $manifest['artefact_count'] !== 3) {
            $violations[] = sprintf('the manifest lists %d artefacts and three were archived', (int) $manifest['artefact_count']);
        }

        if ((string) $manifest['manifest_hash'] !== hash('sha256', (string) $manifest['manifest'])) {
            $violations[] = 'the manifest hash is not the hash of the manifest';
        }

        $report = $archive->verify($entityId, $period);

        if ($report['manifest'] !== 'intact') {
            $violations[] = sprintf('a freshly sealed manifest verifies as %s', (string) $report['manifest']);
        }

        if ($report['removed'] !== [] || $report['added'] !== []) {
            $violations[] = 'a freshly sealed period reports additions or removals';
        }

        return $violations;
    }

    /** @return list<string> */
    private function manifestIsSealed(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $period = '2026-08';

        $archive->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<sealed/>',
            app(GenericProfile::class), 'application/xml', Carbon::parse('2026-08-10'), $period,
        );

        $manifest = $archive->seal($entityId, $period);

        try {
            $archive->seal($entityId, $period);
            $violations[] = 'the period was sealed twice';
        } catch (ArchiveRefused $e) {
            if (! str_contains($e->getMessage(), 'proves nothing')) {
                $violations[] = 'the refusal does not say why resealing is useless: '.$e->getMessage();
            }
        }

        foreach (['update' => fn () => DB::connection('tenant')->table('archive_manifests')
            ->where('id', (string) $manifest['id'])->update(['manifest_hash' => str_repeat('b', 64)]),
            'delete' => fn () => DB::connection('tenant')->table('archive_manifests')
                ->where('id', (string) $manifest['id'])->delete()] as $what => $attempt) {
            try {
                $attempt();
                $violations[] = sprintf(
                    'a sealed manifest was %sd. One that can be changed always matches whatever is in '.
                    'the archive now',
                    $what,
                );
            } catch (\Throwable) {
                // As intended.
            }
        }

        return $violations;
    }

    /**
     * The case the manifest exists for.
     *
     * @return list<string>
     */
    private function removalIsDetected(string $entityId): array
    {
        $archive = app(Archive::class);
        $period = '2026-09';

        $rows = [];

        foreach ([['<keep/>', '2026-09-01'], ['<remove/>', '2010-09-01']] as [$bytes, $at]) {
            $rows[] = $archive->store(
                $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', $bytes,
                app(GenericProfile::class), 'application/xml', Carbon::parse($at), $period,
            );
        }

        $archive->seal($entityId, $period);

        /*
         | The object AND the row, together - after the retention expired.
         |
         | Every remaining artefact's hash is still correct, so nothing that
         | checks the files notices. The row is removable because this
         | artefact was archived in 2010 and its ten years are up, which is
         | the legitimate path: the first version of this check disabled the
         | WORM trigger, which needs the table's owner and tested the wrong
         | thing anyway. The trigger has its own failing case.
         */
        Storage::disk((string) config('archive.disk'))->delete((string) $rows[1]['path']);
        DB::connection('tenant')->table('archived_artefacts')->where('id', (string) $rows[1]['id'])->delete();

        $report = $archive->verify($entityId, $period);

        if ($report['removed'] === []) {
            return ['an artefact deleted from storage and from the table together was not detected. '.
                'Every remaining hash is still correct, which is exactly why the manifest hashes the '.
                'list rather than the files'];
        }

        if ($report['removed'][0] !== (string) $rows[1]['content_hash']) {
            return ['the wrong artefact was reported as removed'];
        }

        return [];
    }

    /** @return list<string> */
    private function divergenceIsReported(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $period = '2026-10';

        $row = $archive->store(
            $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', '<original/>',
            app(GenericProfile::class), 'application/xml', Carbon::parse('2026-10-01'), $period,
        );

        Storage::disk((string) config('archive.disk'))->put((string) $row['path'], '<tampered/>');

        $report = $archive->verify($entityId, $period);

        if ($report['diverged'] === []) {
            $violations[] = 'a changed object is not reported. Storage corrupts and a restore puts back '.
                'an older version, and neither announces itself';
        }

        try {
            $archive->read($entityId, 'invoice', (string) $row['subject_id'], 'sent_xml');
            $violations[] = 'the changed object was served. The copy the recipient holds matches one of '.
                'the two, and it is not this one';
        } catch (ArchiveRefused $e) {
            if (! str_contains($e->getMessage(), 'hashes to')) {
                $violations[] = 'the refusal does not say the hashes differ: '.$e->getMessage();
            }
        }

        Storage::disk((string) config('archive.disk'))->delete((string) $row['path']);
        $missing = $archive->verify($entityId, $period);

        if ($missing['missing'] === []) {
            $violations[] = 'a missing object is not reported';
        }

        return $violations;
    }

    /** @return list<string> */
    private function drill(string $entityId): array
    {
        $violations = [];
        $archive = app(Archive::class);

        for ($i = 0; $i < 4; $i++) {
            $archive->store(
                $entityId, 'invoice', (string) Str::ulid(), 'sent_xml', sprintf('<drill n="%d"/>', $i),
                app(GenericProfile::class), 'application/xml', Carbon::parse('2026-11-01'), '2026-11',
            );
        }

        $drill = $archive->drill($entityId, 3);

        if ((string) $drill['outcome'] !== 'passed') {
            $violations[] = sprintf('a healthy archive drilled as %s: %s', (string) $drill['outcome'], (string) $drill['detail']);
        }

        if ((int) $drill['sampled'] !== 3 || (int) $drill['restored'] !== 3) {
            $violations[] = sprintf(
                'the drill sampled %d and restored %d of a requested three',
                (int) $drill['sampled'],
                (int) $drill['restored'],
            );
        }

        $recorded = DB::connection('tenant')->table('archive_restore_drills')
            ->where('entity_id', $entityId)
            ->count();

        if ($recorded < 1) {
            $violations[] = 'the drill was not recorded. "When did we last prove the archive restores" '.
                'has to have an answer that is not somebody\'s recollection';
        }

        return $violations;
    }

    /** @return list<string> */
    private function failingDrill(string $entityId): array
    {
        $archive = app(Archive::class);
        $other = (string) Str::ulid();

        DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$other]);

        try {
            $row = $archive->store(
                $other, 'invoice', (string) Str::ulid(), 'sent_xml', '<gone/>',
                app(GenericProfile::class), 'application/xml',
            );

            Storage::disk((string) config('archive.disk'))->delete((string) $row['path']);

            $drill = $archive->drill($other, 5);

            if ((string) $drill['outcome'] !== 'failed') {
                return [sprintf(
                    'a drill over a missing object reported "%s". A backup nobody has restored is a '.
                    'hope, and a drill that cannot fail is the same thing with a record',
                    (string) $drill['outcome'],
                )];
            }

            if ((int) $drill['missing'] < 1) {
                return ['the failing drill did not count the missing object'];
            }

            if (trim((string) $drill['detail']) === '') {
                return ['the failing drill recorded no detail, so the record says it failed and not why'];
            }

            return [];
        } finally {
            DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$entityId]);
        }
    }

    /** @return list<string> */
    private function pathShape(string $entityId): array
    {
        $hash = hash('sha256', 'anything');
        $path = app(Archive::class)->pathFor($entityId, 'sent_xml', $hash);
        $violations = [];

        foreach ([$entityId => 'the entity', 'sent_xml' => 'the kind', $hash => 'the hash'] as $needle => $what) {
            if (! str_contains($path, (string) $needle)) {
                $violations[] = sprintf('the path does not carry %s', $what);
            }
        }

        if (! str_contains($path, '/'.substr($hash, 0, 2).'/')) {
            $violations[] = 'the path does not fan out by the first bytes of the hash';
        }

        return $violations;
    }
}
