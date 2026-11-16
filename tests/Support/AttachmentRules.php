<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Attachments\AttachmentRefused;
use App\Support\Attachments\AttachmentStore;
use App\Support\Attachments\ContentTypeSniffer;
use App\Support\Attachments\ScanVerdict;
use App\Support\Attachments\UnavailableScanner;
use App\Support\Attachments\VirusScanner;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Attachments - PF-073.
 *
 * ## The three claims
 *
 * 1. **The content type comes from the bytes.** A browser's type is whatever
 *    the uploader's client said, and an uploader who renames `payload.html`
 *    to `invoice.pdf` gets `application/pdf` from every client there is.
 * 2. **A file is not readable until it has been found clean.** Not scanned
 *    afterwards on a schedule, which tells you what you have been serving.
 *    And "the scanner could not run" is not "clean".
 * 3. **The path never comes from the name.** `../../.env` is a name.
 *
 * Each has a dedicated failing case, because each is the kind of property
 * that is easy to assert and easy to lose: the sniffing survives one
 * refactor that "simplified" the upload path, and nobody notices until a
 * stored SVG runs against the application's origin.
 */
final class AttachmentRules
{
    use \Tests\Support\Concerns\IssuesInvoices;

    /** @var list<string> */
    private array $temporary = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $static = [
            'the type is sniffed from the bytes, not the name' => $this->sniffing(),
            'the permitted list is an allowlist and SVG is not on it' => $this->allowlist(),
            'a missing scanner says so rather than passing' => $this->unavailableScanner(),
            'a scanner that could not run has not said clean' => $this->failedIsNotClean(),
        ];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$static, 'attachments (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        Storage::fake('attachments');

        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return [
                ...$static,
                ...$this->runEachInASavepoint([
                    'a clean file is stored, hashed and readable' => fn (): array => $this->storesAndReads(),
                    'the same bytes twice are one object and two claims' => fn (): array => $this->deduplicates(),
                    'one claim released leaves the other\'s copy alone' => fn (): array => $this->referenceCounting(),
                    'an unscanned file is stored and not readable' => fn (): array => $this->unreadableUntilScanned(),
                    'an infected file is stored and refused' => fn (): array => $this->infectedIsRefused(),
                    'a file renamed to look like a PDF is refused' => fn (): array => $this->renamedIsRefused(),
                    'a type that is not on the list is refused' => fn (): array => $this->unknownTypeIsRefused(),
                    'a file over the cap is refused' => fn (): array => $this->tooLargeIsRefused(),
                    'a name with a path separator is refused' => fn (): array => $this->pathTraversalIsRefused(),
                    'the stored path is derived from the hash and the entity' => fn (): array => $this->pathShape(),
                    'a signed link carries the tenant and expires' => fn (): array => $this->signedLink(),
                    'a stored object that changed is refused on read' => fn (): array => $this->divergenceIsRefused(),
                    'attachments:verify reports a missing object' => fn (): array => $this->verifyReports(),
                    'another entity cannot see these attachments' => fn (): array => $this->entityScope(),
                ]),
            ];
        } finally {
            $this->connection->rollBack();

            foreach ($this->temporary as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /** @return list<string> */
    private function sniffing(): array
    {
        $sniffer = new ContentTypeSniffer();
        $violations = [];

        $html = $this->file("<html><script>alert(1)</script></html>", 'invoice.pdf');
        $sniffed = $sniffer->sniff($html);

        if (str_contains($sniffed, 'pdf')) {
            $violations[] = sprintf(
                'an HTML file named invoice.pdf sniffed as %s. The browser would have said '.
                'application/pdf, which is why the type comes from the bytes',
                $sniffed,
            );
        }

        $pdf = $this->file("%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'real.pdf');

        if ($sniffer->sniff($pdf) !== 'application/pdf') {
            $violations[] = sprintf('a real PDF sniffed as %s', $sniffer->sniff($pdf));
        }

        /*
         | The mismatch case is a PERMITTED type under the wrong name.
         |
         | Measured: this used to pass an HTML file named invoice.pdf, and
         | `mismatch()` correctly said nothing - `text/html` is not on the
         | allowlist at all, so there is no set of extensions to disagree
         | with and the upload is refused by type rather than by name. The
         | case the mismatch message is for is two permitted types: a PNG
         | called invoice.pdf, where both are storable and the name lies
         | about which.
         */
        $png = $this->file(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwADhgGAWjR9awAAAABJRU5ErkJggg==', true), 'invoice.pdf');
        $mismatch = $sniffer->mismatch('invoice.pdf', $sniffer->sniff($png));

        if ($mismatch === null) {
            $violations[] = sprintf(
                'a %s named invoice.pdf is not reported as a mismatch',
                $sniffer->sniff($png),
            );
        } elseif (! str_contains((string) $mismatch, '.pdf')) {
            $violations[] = 'the mismatch message does not name the extension, which is the part that '.
                'tells somebody they have been sent something odd';
        }

        /*
         | An Office file IS a zip, and that is not a mismatch.
         |
         | `finfo` reports .xlsx as application/zip on most builds because
         | that is exactly what it is, and a check that called it a mismatch
         | would refuse every spreadsheet anybody attaches.
         */
        if ($sniffer->mismatch('book.xlsx', 'application/zip') !== null) {
            $violations[] = 'a spreadsheet was called a mismatch for being a zip, which every .xlsx is';
        }

        return $violations;
    }

    /** @return list<string> */
    private function allowlist(): array
    {
        $sniffer = new ContentTypeSniffer();
        $violations = [];

        foreach (['image/svg+xml', 'text/html', 'application/x-httpd-php', 'application/octet-stream'] as $type) {
            if ($sniffer->isPermitted($type)) {
                $violations[] = sprintf(
                    '%s is permitted. SVG and HTML execute script in a browser, and no accounting '.
                    'document needs either',
                    $type,
                );
            }
        }

        foreach (['application/pdf', 'image/png', 'text/csv'] as $type) {
            if (! $sniffer->isPermitted($type)) {
                $violations[] = sprintf('%s is not permitted and an accounting system needs it', $type);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function unavailableScanner(): array
    {
        $verdict = (new UnavailableScanner())->scan(__FILE__);
        $violations = [];

        if ($verdict->isClean()) {
            $violations[] = 'the scanner that is not installed returned clean. That is the one decision '.
                'that makes a virus scan decorative: every environment where the scanner is missing '.
                'becomes one where the scan passes';
        }

        if ($verdict->result !== 'failed') {
            $violations[] = sprintf('a missing scanner returned "%s"', $verdict->result);
        }

        if ($verdict->detail === null || ! str_contains((string) $verdict->detail, 'not been looked at')) {
            $violations[] = 'the verdict does not say why';
        }

        return $violations;
    }

    /** @return list<string> */
    private function failedIsNotClean(): array
    {
        $failed = ScanVerdict::failed('clamav', 'the daemon is not running');

        return $failed->isClean()
            ? ['a failed scan reports itself clean. A scanner that could not reach a verdict has told '.
                'you nothing, and treating nothing as fine is the whole defect']
            : [];
    }

    /** @return list<string> */
    private function storesAndReads(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        $contents = "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        $attachment = $store->attach(
            $this->entityId,
            'invoice',
            (string) \Illuminate\Support\Str::ulid(),
            $this->file($contents, 'contract.pdf'),
            'contract.pdf',
            'application/pdf',
        );

        $blob = $attachment['blob'];

        if ((string) $blob['content_hash'] !== hash('sha256', $contents)) {
            $violations[] = 'the stored hash is not the hash of the bytes';
        }

        if ((string) $blob['content_type'] !== 'application/pdf') {
            $violations[] = sprintf('the sniffed type is %s', (string) $blob['content_type']);
        }

        if ((int) $blob['byte_size'] !== \strlen($contents)) {
            $violations[] = 'the stored size is not the size of the bytes';
        }

        if ($store->read($this->entityId, (string) $attachment['id']) !== $contents) {
            $violations[] = 'the bytes that came back are not the bytes that went in';
        }

        if (! Storage::disk('attachments')->exists((string) $blob['path'])) {
            $violations[] = 'the object is not in storage at the path the row names';
        }

        $references = DB::connection('tenant')->table('attachment_blobs')
            ->where('id', (string) $blob['id'])
            ->value('reference_count');

        if ((int) $references !== 1) {
            $violations[] = sprintf('the reference count is %d after one attachment', (int) $references);
        }

        return $violations;
    }

    /** @return list<string> */
    private function deduplicates(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));
        $contents = "%PDF-1.7 the same contract\n%%EOF\n";

        $first = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file($contents, 'contract.pdf'), 'contract.pdf',
        );

        $second = $store->attach(
            $this->entityId, 'bill', (string) \Illuminate\Support\Str::ulid(),
            $this->file($contents, 'the-same-contract.pdf'), 'the-same-contract.pdf',
        );

        if ((string) $first['blob']['id'] !== (string) $second['blob']['id']) {
            $violations[] = 'the same bytes were stored twice. One contract attached to four bills is '.
                'one object and four claims';
        }

        $blobs = DB::connection('tenant')->table('attachment_blobs')
            ->where('entity_id', $this->entityId)
            ->where('content_hash', hash('sha256', $contents))
            ->count();

        if ($blobs !== 1) {
            $violations[] = sprintf('%d objects exist for one hash', $blobs);
        }

        $references = DB::connection('tenant')->table('attachment_blobs')
            ->where('id', (string) $first['blob']['id'])
            ->value('reference_count');

        if ((int) $references !== 2) {
            $violations[] = sprintf('the reference count is %d after two claims', (int) $references);
        }

        // And each claim keeps the name it was given.
        if ((string) $second['declared_name'] !== 'the-same-contract.pdf') {
            $violations[] = 'the second claim lost its own name, so a download would be called the '.
                'first one';
        }

        return $violations;
    }

    /** @return list<string> */
    private function referenceCounting(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));
        $contents = "%PDF-1.7 shared\n%%EOF\n";

        $first = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file($contents, 'a.pdf'), 'a.pdf',
        );
        $second = $store->attach(
            $this->entityId, 'bill', (string) \Illuminate\Support\Str::ulid(),
            $this->file($contents, 'b.pdf'), 'b.pdf',
        );

        $path = (string) $first['blob']['path'];

        $store->detach($this->entityId, (string) $first['id']);

        if (! Storage::disk('attachments')->exists($path)) {
            $violations[] = 'releasing one claim deleted the object while another claim still held it. '.
                'The reference count is what makes a delete safe';
        }

        if ($store->read($this->entityId, (string) $second['id']) !== $contents) {
            $violations[] = 'the surviving claim can no longer read its file';
        }

        $store->detach($this->entityId, (string) $second['id']);

        if (Storage::disk('attachments')->exists($path)) {
            $violations[] = 'the object survived the last claim, so storage grows forever';
        }

        $blobs = DB::connection('tenant')->table('attachment_blobs')
            ->where('entity_id', $this->entityId)
            ->where('content_hash', hash('sha256', $contents))
            ->count();

        if ($blobs !== 0) {
            $violations[] = 'the row survived the last claim';
        }

        return $violations;
    }

    /** @return list<string> */
    private function unreadableUntilScanned(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::failed('a fixture', 'the daemon is down'));

        $attachment = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file("%PDF-1.7 unscanned\n%%EOF\n", 'statement.pdf'), 'statement.pdf',
        );

        if (! Storage::disk('attachments')->exists((string) $attachment['blob']['path'])) {
            $violations[] = 'the file was not stored at all. It should be: the upload succeeded and the '.
                'scan did not, and discarding it would lose the document';
        }

        try {
            $store->read($this->entityId, (string) $attachment['id']);
            $violations[] = 'an unscanned file was readable. A scan that runs after the file is '.
                'downloadable is a scan that tells you what you already served';
        } catch (AttachmentRefused $e) {
            if (! str_contains($e->getMessage(), 'not been found clean')) {
                $violations[] = 'the refusal does not say the file has not been found clean: '.$e->getMessage();
            }
        }

        try {
            $store->signedUrl($this->entityId, (string) $attachment['id']);
            $violations[] = 'a link was minted for a file that has not been found clean';
        } catch (AttachmentRefused) {
            // As intended.
        }

        return $violations;
    }

    /** @return list<string> */
    private function infectedIsRefused(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::infected('a fixture', 'Eicar-Test-Signature'));

        $attachment = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file("%PDF-1.7 infected\n%%EOF\n", 'bad.pdf'), 'bad.pdf',
        );

        try {
            $store->read($this->entityId, (string) $attachment['id']);
            $violations[] = 'an infected file was readable';
        } catch (AttachmentRefused $e) {
            if (! str_contains($e->getMessage(), 'infected')) {
                $violations[] = 'the refusal does not say it was infected: '.$e->getMessage();
            }

            if (! str_contains($e->getMessage(), 'Eicar-Test-Signature')) {
                $violations[] = 'the refusal does not carry what the scanner found, which is what an '.
                    'operator needs to decide whether it is a false positive';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function renamedIsRefused(): array
    {
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        try {
            $store->attach(
                $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
                $this->file('<html><script>alert(1)</script></html>', 'invoice.pdf'),
                'invoice.pdf',
                'application/pdf',
            );
        } catch (AttachmentRefused $e) {
            return str_contains($e->getMessage(), '.pdf') || str_contains($e->getMessage(), 'not a type')
                ? []
                : ['the refusal does not explain what is wrong with the file: '.$e->getMessage()];
        }

        return ['an HTML file named invoice.pdf was stored, with the type the uploader declared. Serving '.
            'it later would execute it'];
    }

    /** @return list<string> */
    private function unknownTypeIsRefused(): array
    {
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        try {
            $store->attach(
                $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
                $this->file("\x7fELF\x02\x01\x01\x00binary", 'tool'),
                'tool',
            );
        } catch (AttachmentRefused $e) {
            return str_contains($e->getMessage(), 'allowlist')
                ? []
                : ['the refusal does not say the list is an allowlist: '.$e->getMessage()];
        }

        return ['an executable was stored'];
    }

    /** @return list<string> */
    private function tooLargeIsRefused(): array
    {
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        config(['attachments.max_bytes' => 64]);

        try {
            $store->attach(
                $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
                $this->file(str_repeat('a', 500), 'big.txt'), 'big.txt',
            );

            return ['a file over the cap was stored. An upload path with no cap is a way to fill the '.
                'disk, and a full disk stops the ledger accepting postings'];
        } catch (AttachmentRefused $e) {
            return str_contains($e->getMessage(), 'limit')
                ? []
                : ['the refusal does not state the limit: '.$e->getMessage()];
        } finally {
            config(['attachments.max_bytes' => 25 * 1024 * 1024]);
        }
    }

    /** @return list<string> */
    private function pathTraversalIsRefused(): array
    {
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));
        $violations = [];

        foreach (['../../.env', 'a/b.txt', "null\0byte.txt", '..'] as $name) {
            try {
                $store->attach(
                    $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
                    $this->file('plain text', 'safe.txt'), $name,
                );

                $violations[] = sprintf(
                    '"%s" was accepted as a name. A name is user input, and a path built from user '.
                    'input is a path traversal',
                    $name,
                );
            } catch (AttachmentRefused) {
                // As intended.
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function pathShape(): array
    {
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));
        $hash = hash('sha256', 'anything');
        $path = $store->pathFor($this->entityId, $hash);
        $violations = [];

        if (! str_contains($path, $this->entityId)) {
            $violations[] = 'the path carries no entity prefix, so one tenant\'s objects sit beside '.
                'another\'s';
        }

        if (! str_ends_with($path, $hash)) {
            $violations[] = 'the path does not end in the hash';
        }

        if (! str_contains($path, '/'.substr($hash, 0, 2).'/')) {
            $violations[] = 'the path does not fan out by the first bytes of the hash, so one directory '.
                'holds every object';
        }

        return $violations;
    }

    /** @return list<string> */
    private function signedLink(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        $attachment = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file("%PDF-1.7 link\n%%EOF\n", 'c.pdf'), 'c.pdf',
        );

        $url = $store->signedUrl($this->entityId, (string) $attachment['id'], 300, 'a-tenant');

        foreach (['signature=', 'expires=', 'a-tenant', $this->entityId, (string) $attachment['id']] as $needle) {
            if (! str_contains($url, (string) $needle)) {
                $violations[] = sprintf('the link does not carry "%s"', (string) $needle);
            }
        }

        /*
         | The host is swapped and the signature still holds.
         |
         | Laravel signs the path and the query, not the host - which is
         | what makes serving from a separate origin possible at all.
         */
        config(['attachments.origin' => 'https://files.example']);
        $rehomed = $store->signedUrl($this->entityId, (string) $attachment['id'], 300, 'a-tenant');
        config(['attachments.origin' => '']);

        if (! str_starts_with($rehomed, 'https://files.example/')) {
            $violations[] = 'the link is not re-homed onto the download origin, so a stored file is '.
                'served where the session cookie is';
        }

        if (! str_contains($rehomed, 'signature=')) {
            $violations[] = 're-homing the link dropped the signature';
        }

        return $violations;
    }

    /** @return list<string> */
    private function divergenceIsRefused(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        $attachment = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file("%PDF-1.7 original\n%%EOF\n", 'd.pdf'), 'd.pdf',
        );

        // Storage corrupts, a restore puts back the wrong version, and a
        // scan verdict applies to bytes rather than to a path.
        Storage::disk('attachments')->put((string) $attachment['blob']['path'], 'something else entirely');

        try {
            $store->read($this->entityId, (string) $attachment['id']);
            $violations[] = 'a stored object that no longer hashes to its row was served. The scan '.
                'verdict applies to the bytes that were scanned, and these are not them';
        } catch (AttachmentRefused $e) {
            if (! str_contains($e->getMessage(), 'hashes to')) {
                $violations[] = 'the refusal does not say the hashes differ: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function verifyReports(): array
    {
        $violations = [];
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        $attachment = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file("%PDF-1.7 verify\n%%EOF\n", 'e.pdf'), 'e.pdf',
        );

        $clean = $store->verify($this->entityId);

        if ($clean['missing'] !== [] || $clean['diverged'] !== []) {
            $violations[] = 'a healthy store reports divergence';
        }

        if ($clean['checked'] < 1) {
            $violations[] = 'the verification checked nothing';
        }

        Storage::disk('attachments')->delete((string) $attachment['blob']['path']);

        $after = $store->verify($this->entityId);

        if ($after['missing'] === []) {
            $violations[] = 'a deleted object is not reported missing. Storage loses objects and a '.
                'restore puts back an older version, and neither announces itself - the first symptom '.
                'is an inspector asking for a contract';
        }

        Storage::disk('attachments')->put((string) $attachment['blob']['path'], 'tampered');
        $diverged = $store->verify($this->entityId);

        if ($diverged['diverged'] === []) {
            $violations[] = 'a changed object is not reported as diverged';
        }

        return $violations;
    }

    /** @return list<string> */
    private function entityScope(): array
    {
        $store = $this->storeWith(fn (): ScanVerdict => ScanVerdict::clean('a fixture'));

        $attachment = $store->attach(
            $this->entityId, 'invoice', (string) \Illuminate\Support\Str::ulid(),
            $this->file("%PDF-1.7 scoped\n%%EOF\n", 'f.pdf'), 'f.pdf',
        );

        $other = (string) \Illuminate\Support\Str::ulid();

        DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$other]);

        $visible = DB::connection('tenant')->table('attachments')->count();

        DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$this->entityId]);

        return $visible === 0
            ? []
            : [sprintf(
                'another entity sees %d of this one\'s attachments. Row-level security is what makes a '.
                'shared database safe, and an attachment is a bank statement',
                $visible,
            )];
    }

    /** A store whose scanner returns whatever the check wants. */
    private function storeWith(callable $verdict): AttachmentStore
    {
        $scanner = new class(\Closure::fromCallable($verdict)) implements VirusScanner
        {
            public function __construct(private readonly \Closure $verdict) {}

            public function name(): string
            {
                return 'a fixture';
            }

            public function scan(string $absolutePath): ScanVerdict
            {
                return ($this->verdict)();
            }
        };

        return new AttachmentStore(
            new ContentTypeSniffer(),
            $scanner,
            app(\App\Support\Audit\AuditTrail::class),
        );
    }

    private function file(string $contents, string $name): string
    {
        $path = sys_get_temp_dir().'/pf-'.\Illuminate\Support\Str::random(10).'-'.$name;
        file_put_contents($path, $contents);
        $this->temporary[] = $path;

        return $path;
    }
}
