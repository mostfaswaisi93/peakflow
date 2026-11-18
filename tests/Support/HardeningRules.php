<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Readers\UblReader;
use App\Platform\Models\Tenant;
use App\Platform\Services\PrivilegeMatrix;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Attachments\AttachmentRefused;
use App\Support\Attachments\AttachmentStore;
use App\Support\Attachments\ContentTypeSniffer;
use App\Support\Attachments\UploadFileName;
use App\Support\Hashing\Exceptions\NotCanonicalizable;
use App\Support\Hashing\XmlCanonicalizer;
use App\Support\Http\ContentSecurityPolicy;
use App\Support\Http\SessionCookieName;
use App\Support\Xml\MalformedXml;
use App\Support\Xml\SafeXmlReader;
use App\Tenant\Banking\Exceptions\StatementUnreadable;
use App\Tenant\Banking\Parsers\CamtParser;
use App\Tenant\Banking\Parsers\OfxParser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What the hardening actually refuses - PF-233, PF-235, PF-236, PF-237.
 *
 * ## Every check here fires the attack
 *
 * Not one of them asserts that a flag is set. A flag is a statement about the
 * code and the question is what the parser does, and the two came apart in
 * this repository in the most instructive way possible: `SafeXmlReader`
 * refused a DOCTYPE, had always refused a DOCTYPE, and refused it by reading
 * `$document->doctype` AFTER `loadXML()` had returned. libxml expands an
 * internal general entity while it builds the tree, so a 347-byte
 * billion-laughs payload produced a 1,000,000-character text node and was
 * then refused for having a DOCTYPE. Every flag was right. The defence was
 * being paid for and not received.
 *
 * A check asserting `LIBXML_NOENT` was absent would have passed throughout.
 * The check below asserts how much memory the refusal costs, which is the
 * only form of the question that has the right answer.
 *
 * ## What this file deliberately does NOT check
 *
 * Row-level security presence, the connect matrix, and the application role's
 * DDL in the platform database. {@see TenantEnforcementRules} and
 * {@see DatabaseRoleRules} already prove those, with real connection attempts,
 * and a second copy would be a second thing to keep in step rather than a
 * second line of defence. What PF-233 adds here is the rest of the privilege
 * matrix - TRUNCATE, REFERENCES, TRIGGER and the marker-driven revokes, which
 * nothing asked about - and a drift check that runs on a deploy.
 *
 * ## The checks that prove themselves
 *
 * Two of them grant a privilege the matrix forbids, assert that the matrix
 * reports it, and roll back. `GRANT` and `REVOKE` are transactional in
 * PostgreSQL, so that is safe, and it is the only way to know the matrix can
 * fail: on a correctly provisioned cluster every one of its 2,436 answers is
 * the right one, and a check that only ever sees a clean cluster is a check
 * that has never been run against a dirty one.
 *
 * The same shape appears in PF-078's bloc membership and in the layer rules,
 * for the same reason.
 */
final class HardeningRules
{
    /*
     | The global proof, because this suite issues DELETE against the journal.
     |
     | It posts nothing. What it does is attempt `DELETE FROM journal_entries`
     | and `DELETE FROM journal_lines` as the application role, to prove the
     | privilege refuses them, and each attempt is rolled back.
     |
     | `ArchitectureRules::postingSuitesProveTheLedger()` requires the proof
     | from any suite that writes to `journal_lines` at all, and that is the
     | right requirement here rather than an inconvenience: a probe that
     | deletes from the journal and gets its rollback wrong would leave the
     | ledger short, and every individual check in this file would still pass
     | because none of them looks at the ledger.
     */
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** A file whose contents an XXE payload will try to reach. */
    private string $bait = '';

    /** @var list<string> */
    private array $temporary = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $this->bait = sys_get_temp_dir().'/pf235-bait-'.bin2hex(random_bytes(6)).'.txt';
        file_put_contents($this->bait, self::BAIT);
        $this->temporary[] = $this->bait;

        try {
            return [
                // PF-235 - XML hardening.
                'an external entity reads no file, at every reader' => $this->externalEntitiesReadNothing(),
                'a billion-laughs payload is refused before it expands' => $this->entityExpansionIsRefusedEarly(),
                'an external DTD is refused, at every reader' => $this->externalDtdsAreRefused(),
                'a document deeper than the configured limit is refused' => $this->tooDeepIsRefused(),
                'the depth limit is one that can actually apply' => $this->theDepthLimitApplies(),
                'an oversized document is refused before it is parsed' => $this->oversizedIsRefusedBeforeParsing(),
                'every XML parse in the application goes through the safe reader' => $this->everyParseIsSafe(),
                'a schema cannot fetch its own references' => $this->schemasCannotFetch(),

                // PF-236 - upload hardening.
                'a right-to-left override in a filename is refused' => $this->bidiOverrideIsRefused(),
                'a confusable name is refused and an Arabic one is not' => $this->confusablesAreRefused(),
                'a name no filesystem can hold is refused' => $this->unusableNamesAreRefused(),
                'an upload with no extension, or an unlisted one, is refused' => $this->extensionAllowlist(),
                'a stored name is normalized to one form' => $this->namesAreNormalized(),
                'nothing renders a stored document server-side' => $this->nothingRendersAnUpload(),

                // PF-237 - HTTP security headers.
                'a real response carries every header' => $this->everyHeaderOnARealResponse(),
                'the policy has no unsafe-eval, and no unsafe-inline that can run' => $this->noUnsafeKeywords(),
                'the nonce in the header is the nonce in the page' => $this->theNonceMatches(),
                'a new request gets a new nonce' => $this->theNonceIsPerRequest(),
                'report-only and enforcing are the same policy under two names' => $this->bothPhasesEmitThePolicy(),
                'a secure session cookie is host-prefixed' => $this->theSessionCookieIsHostPrefixed(),

                // PF-233 - database privilege verification.
                ...$this->privilegeChecks(),

                /*
                 | The global proof, with an entity established.
                 |
                 | The verifier's suspense check reads `AccountRole`, which the
                 | Eloquent scope refuses to query with no entity in scope -
                 | correctly, because returning every entity's rows would look
                 | like data rather than an error. This suite establishes no
                 | entity of its own (it does not post), so one of the
                 | tenant's own entities is put in scope for the proof alone.
                 |
                 | A tenant with no entity at all gets a named skip rather
                 | than a pass: the proof is the thing that catches what the
                 | individual checks did not think to look at, and a proof
                 | that quietly did not run is worse than one that failed.
                 */
                'and the ledger still proves its own invariants' => $this->theLedgerProofInAnEntity(),
            ];
        } finally {
            foreach ($this->temporary as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * `ledger:verify`, run inside one of the tenant's entities.
     *
     * @return list<string>
     */
    private function theLedgerProofInAnEntity(): array
    {
        $tenant = Tenant::query()->with('connection')
            ->whereNotNull('provisioned_at')
            ->orderBy('created_at')
            ->first();

        if ($tenant === null) {
            return [];
        }

        app(TenantConnectionResolver::class)->point($tenant);

        $entity = DB::connection('tenant')->table('entities')->orderBy('id')->first();

        if ($entity === null) {
            return ['this tenant holds no entity, so the global proof could not be run in one - and a '.
                'proof that quietly did not run is worse than one that failed'];
        }

        return app(\App\Tenant\Tenancy\Services\EntityContext::class)->during(
            (string) $entity->id,
            fn (): array => $this->theLedgerProof((string) $entity->id),
        );
    }

    /** The string that must never come back out of a parser. */
    private const BAIT = 'PF235-THE-PARSER-READ-A-LOCAL-FILE';

    /*
    |---------------------------------------------------------------------------
    | PF-235 - XML
    |---------------------------------------------------------------------------
    */

    /**
     * The attack, in the shape each reader expects.
     *
     * Each reader gets a payload whose ROOT is the element that reader looks
     * for, because a reader that refuses the document for being the wrong
     * format has not proved anything about entities. The CAMT payload is a
     * `BkToCstmrStmt`, the OFX one an `OFX`, and so on - so the only reason any
     * of them can refuse is the DOCTYPE.
     *
     * @return array<string, callable(string): mixed>
     */
    private function readers(): array
    {
        return [
            'SafeXmlReader' => static fn (string $xml): mixed => SafeXmlReader::parse($xml)->textContent,
            'UblReader' => static fn (string $xml): mixed => app(UblReader::class)->read($xml),
            'CamtParser' => static fn (string $xml): mixed => (new CamtParser())->parse($xml),
            'OfxParser' => static fn (string $xml): mixed => (new OfxParser())->parse($xml),
            'XmlCanonicalizer' => static fn (string $xml): mixed => XmlCanonicalizer::canonicalize($xml),
        ];
    }

    /**
     * A payload with the given internal subset, wrapped in each reader's root.
     *
     * @return array<string, string>
     */
    private function payloads(string $subset, string $body): array
    {
        $doctype = $subset === '' ? '' : '<!DOCTYPE Invoice ['.$subset.']>';

        return [
            'SafeXmlReader' => '<?xml version="1.0"?>'.$doctype.'<Invoice><Note>'.$body.'</Note></Invoice>',
            'UblReader' => '<?xml version="1.0"?>'.str_replace('Invoice [', 'Invoice [', $doctype)
                .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">'
                .'<ID>'.$body.'</ID></Invoice>',
            'CamtParser' => '<?xml version="1.0"?>'.str_replace('DOCTYPE Invoice', 'DOCTYPE Document', $doctype)
                .'<Document><BkToCstmrStmt><Stmt><Id>'.$body.'</Id></Stmt></BkToCstmrStmt></Document>',
            'OfxParser' => '<?xml version="1.0"?>'.str_replace('DOCTYPE Invoice', 'DOCTYPE OFX', $doctype)
                .'<OFX><STMTRS><CURDEF>'.$body.'</CURDEF></STMTRS></OFX>',
            'XmlCanonicalizer' => '<?xml version="1.0"?>'.$doctype.'<Invoice><Note>'.$body.'</Note></Invoice>',
        ];
    }

    /** @return list<string> */
    private function externalEntitiesReadNothing(): array
    {
        $violations = [];
        $payloads = $this->payloads(
            sprintf('<!ENTITY bait SYSTEM "file://%s">', $this->bait),
            '&bait;',
        );

        foreach ($this->readers() as $name => $read) {
            $outcome = $this->outcomeOf($read, $payloads[$name]);

            if ($outcome['refused'] === false) {
                $violations[] = sprintf(
                    '%s ACCEPTED a document declaring <!ENTITY bait SYSTEM "file://...">. The DOCTYPE is '.
                    'the only way an entity can be declared, so a reader that accepts one is a reader '.
                    'whose next libxml upgrade, or whose next caller passing LIBXML_NOENT, reads the '.
                    'filesystem.',
                    $name,
                );
            }

            if (str_contains($outcome['text'], self::BAIT)) {
                $violations[] = sprintf(
                    '%s RETURNED THE CONTENTS OF A LOCAL FILE. The bait string reached the caller, which '.
                    'means the parser resolved file://%s.',
                    $name,
                    $this->bait,
                );
            }
        }

        return $violations;
    }

    /**
     * Billion laughs, and the proof that the refusal came first.
     *
     * Five levels, not nine. Nine is refused by libxml's own amplification
     * cap, and a payload libxml refuses proves nothing about this code. Five
     * is the largest one libxml 2.9.14 ACCEPTS: measured, it parses without
     * complaint and `textContent` comes back with 1,000,000 characters in it.
     *
     * So the memory the refusal costs is the assertion. A refusal that happens
     * before the parser is handed the bytes allocates a few kilobytes; the one
     * that reads `$document->doctype` after `loadXML()` allocates the megabyte
     * first and refuses afterwards. Both "refuse". Only one of them is a
     * defence, and the only way to tell them apart from outside is to weigh
     * them.
     *
     * @return list<string>
     */
    private function entityExpansionIsRefusedEarly(): array
    {
        $violations = [];

        $subset = '<!ENTITY e0 "'.str_repeat('A', 10).'">';

        for ($level = 1; $level <= 5; $level++) {
            $subset .= sprintf('<!ENTITY e%d "%s">', $level, str_repeat(sprintf('&e%d;', $level - 1), 10));
        }

        $payloads = $this->payloads($subset, '&e5;');

        foreach ($this->readers() as $name => $read) {
            $payload = $payloads[$name];

            gc_collect_cycles();
            $before = memory_get_usage(false);
            $outcome = $this->outcomeOf($read, $payload);
            $spent = memory_get_usage(false) - $before;

            if ($outcome['refused'] === false) {
                $violations[] = sprintf(
                    '%s ACCEPTED a %d-byte document that expands to 1,000,000 characters.',
                    $name,
                    \strlen($payload),
                );

                continue;
            }

            /*
             | The refusal must be the PROLOG one, by name.
             |
             | This is the assertion that "before it expands" is a fact rather
             | than a hope, and it is why {@see MalformedXml} has two
             | differently-worded DOCTYPE refusals. The prolog layer runs
             | before `loadXML()` is called at all, so a refusal carrying its
             | sentence is proof that nothing was parsed and nothing expanded;
             | the post-parse layer's sentence means the megabyte was
             | allocated and then thrown away.
             |
             | Measured, with the prolog layer removed and the post-parse one
             | left in place: the document is still refused, every reader
             | still reports a DOCTYPE, and a check that only asked "was it
             | refused" passes while the expansion is being paid for on every
             | inbound document.
             |
             | The memory figure below cannot carry this on its own.
             | `memory_get_usage()` is read AFTER the exception has unwound,
             | by which point PHP has freed the tree, so the allocation is
             | invisible to it - and `memory_get_peak_usage()` is a
             | process-wide high-water mark that never falls, so it is already
             | at whatever the largest earlier check reached. It is kept as a
             | second signal for the case where a reader returns rather than
             | throws.
             */
            if (! str_contains($outcome['why'], 'before its root element')) {
                $violations[] = sprintf(
                    '%s refused the payload, but not with the pre-parse refusal: "%s". Only the prolog '.
                    'layer runs before loadXML(), so any other refusal means libxml built the tree '.
                    'first - and libxml expands an internal entity while building it whether or not '.
                    'LIBXML_NOENT was passed. The defence is being paid for and not received.',
                    $name,
                    $this->firstSentenceOf($outcome['why']),
                );
            }

            if ($spent > 262144) {
                $violations[] = sprintf(
                    '%s refused the payload only AFTER spending %s bytes on it, which it still held when '.
                    'the refusal was measured.',
                    $name,
                    number_format($spent),
                );
            }

            if (str_contains($outcome['text'], str_repeat('A', 1000))) {
                $violations[] = sprintf('%s returned the expansion to the caller.', $name);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function externalDtdsAreRefused(): array
    {
        $violations = [];

        /*
         | A DOCTYPE with an EXTERNAL identifier and no internal subset.
         |
         | The address is a port nothing listens on, so a reader that tries to
         | fetch it fails rather than hanging - and the distinction being
         | tested is "refused the document" against "attempted the request",
         | which a connection error would blur.
         */
        foreach ($this->readers() as $name => $read) {
            $root = match ($name) {
                'CamtParser' => 'Document',
                'OfxParser' => 'OFX',
                default => 'Invoice',
            };

            $payload = sprintf(
                '<?xml version="1.0"?><!DOCTYPE %s SYSTEM "http://127.0.0.1:1/evil.dtd">%s',
                $root,
                match ($name) {
                    'CamtParser' => '<Document><BkToCstmrStmt><Stmt><Id>x</Id></Stmt></BkToCstmrStmt></Document>',
                    'OfxParser' => '<OFX><STMTRS><CURDEF>SAR</CURDEF></STMTRS></OFX>',
                    'UblReader' => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>x</ID></Invoice>',
                    default => '<Invoice><Note>x</Note></Invoice>',
                },
            );

            if ($this->outcomeOf($read, $payload)['refused'] === false) {
                $violations[] = sprintf(
                    '%s ACCEPTED a document whose DOCTYPE names an external DTD at a URL. Fetching one '.
                    'makes the sender of an invoice decide what outbound requests this application '.
                    'makes, from inside the queue worker.',
                    $name,
                );
            }
        }

        return $violations;
    }

    /**
     * Deeper than the configured limit, and deeper than libxml's own.
     *
     * Both, because they are different claims. A document 300 deep is refused
     * by libxml whatever this application does, so a check that used 300 would
     * pass with every line of our depth code deleted. The one that matters is
     * 250: libxml accepts it, the configuration says 100, and only our own
     * limit can refuse it.
     *
     * @return list<string>
     */
    private function tooDeepIsRefused(): array
    {
        $violations = [];
        $limit = (int) config('exchange.xml.max_depth');

        $cases = [
            'at the limit' => [$limit, false],
            'one past the limit' => [$limit + 1, true],
            'past the limit and inside libxml\'s own 256' => [250, true],
        ];

        foreach ($cases as $description => [$depth, $shouldRefuse]) {
            $xml = '<Invoice>'.str_repeat('<a>', $depth - 1).'x'.str_repeat('</a>', $depth - 1).'</Invoice>';
            $outcome = $this->outcomeOf(
                static fn (string $x): mixed => SafeXmlReader::parse($x)->textContent,
                $xml,
            );

            if ($shouldRefuse && $outcome['refused'] === false) {
                $violations[] = sprintf(
                    'a document %d elements deep was accepted (%s) and the configured limit is %d. '.
                    'libxml refuses deeper than 256 on its own, so a limit of %d that nothing enforces '.
                    'is a number in a configuration file.',
                    $depth,
                    $description,
                    $limit,
                    $limit,
                );
            }

            if (! $shouldRefuse && $outcome['refused'] === true) {
                $violations[] = sprintf(
                    'a document exactly %d deep - the configured limit - was REFUSED. A limit that '.
                    'refuses the value it names is an outage on a legitimate document: %s',
                    $depth,
                    $outcome['why'],
                );
            }

            if ($shouldRefuse && $outcome['refused'] === true && ! str_contains($outcome['why'], 'nests elements')) {
                $violations[] = sprintf(
                    'a document %d deep was refused for the wrong reason, so the depth limit is not what '.
                    'refused it: %s',
                    $depth,
                    $outcome['why'],
                );
            }
        }

        return $violations;
    }

    /**
     * The configured depth must be below the one libxml enforces for itself.
     *
     * libxml refuses deeper than 256 unless `LIBXML_PARSEHUGE` is passed,
     * which it never is. So a configured limit above 256 is a limit that can
     * never fire: every document it would have refused was already refused,
     * by a number nobody chose, and the configuration would be stating an
     * intent it has no mechanism for. That is precisely the state PF-235 was
     * found in.
     *
     * @return list<string>
     */
    private function theDepthLimitApplies(): array
    {
        $limit = (int) config('exchange.xml.max_depth');

        if ($limit > 0 && $limit < 256) {
            return [];
        }

        return [sprintf(
            'exchange.xml.max_depth is %d. libxml refuses deeper than 256 by itself, so this limit can '.
            'never be the one that fires - it would be a declared intent with no mechanism, which is '.
            'what PF-235 existed to fix.',
            $limit,
        )];
    }

    /**
     * Oversized, and refused on the string rather than on the tree.
     *
     * The size is proved the same way the expansion is: by what the refusal
     * costs. A cap checked after parsing is a cap that has already been paid,
     * and the DOM libxml builds is several times the size of the bytes - so a
     * refusal that allocates less than the document itself cannot have parsed
     * it.
     *
     * @return list<string>
     */
    private function oversizedIsRefusedBeforeParsing(): array
    {
        $violations = [];
        $cap = (int) config('exchange.xml.max_bytes');

        $filler = str_repeat('x', $cap);
        $xml = '<Invoice><Note>'.$filler.'</Note></Invoice>';
        unset($filler);

        gc_collect_cycles();
        $before = memory_get_usage(false);
        $outcome = $this->outcomeOf(static fn (string $x): mixed => SafeXmlReader::parse($x)->textContent, $xml);
        $spent = memory_get_usage(false) - $before;

        if ($outcome['refused'] === false) {
            $violations[] = sprintf(
                'a %s-byte document was accepted and the cap is %s.',
                number_format(\strlen($xml)),
                number_format($cap),
            );

            return $violations;
        }

        if (! str_contains($outcome['why'], 'the cap is')) {
            $violations[] = sprintf('an oversized document was refused for another reason: %s', $outcome['why']);
        }

        if ($spent > 1048576) {
            $violations[] = sprintf(
                'the oversized document was refused only after %s bytes were allocated, so it had '.
                'already been parsed. The whole point of a size cap is that it is paid before the '.
                'parser is.',
                number_format($spent),
            );
        }

        return $violations;
    }

    /**
     * Static, and deliberately so.
     *
     * Four of the application's five parsing paths are exercised live above.
     * {@see \App\Tenant\Settlement\Services\PaymentStatuses} and
     * {@see \App\Exchange\Pipeline\ValidationPipeline} are not, because
     * reaching them needs a tenant database and a serialized invoice
     * respectively - and the thing worth asserting about them is not that
     * they refuse today but that nobody can add a SIXTH parser without going
     * through the reader.
     *
     * So the rule is a source rule: one hardened entry point, and every other
     * mention of a libxml parser in `app/` is a violation. It is the same
     * shape as the architecture rule confining `revealIban()` to the
     * disclosure service, and for the same reason - the next caller is the
     * one that gets it wrong.
     *
     * @return list<string>
     */
    private function everyParseIsSafe(): array
    {
        $violations = [];

        /*
         | The reader itself, and the writer, which only ever serializes.
         |
         | `XmlWriter` constructs a DOMDocument and never loads one. It is
         | exempt by path rather than by a comment because a writer that
         | starts loading is a writer that should fail this rule.
         */
        $exempt = [
            'app/Support/Xml/SafeXmlReader.php',
            'app/Support/Xml/XmlWriter.php',
        ];

        $forbidden = [
            'loadXML(' => 'loads a document outside the safe reader',
            'simplexml_load_string(' => 'parses with no entity loader replaced and no DOCTYPE refusal',
            'simplexml_load_file(' => 'parses a file with no hardening at all',
            'new \\XMLReader' => 'a pull parser whose LOADDTD and SUBST_ENTITIES default to libxml\'s, not ours',
            'new XMLReader' => 'a pull parser whose LOADDTD and SUBST_ENTITIES default to libxml\'s, not ours',
            'XMLReader::open' => 'a pull parser opened directly',
            'DOMDocument::load' => 'loads a document outside the safe reader',
        ];

        $base = \dirname(__DIR__, 2);

        foreach ($this->phpFilesIn($base.'/app') as $path) {
            $relative = substr($path, \strlen($base) + 1);

            if (\in_array($relative, $exempt, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($forbidden as $needle => $why) {
                if (! str_contains($source, $needle)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s uses %s, which %s. Every document that came from outside goes through '.
                    'SafeXmlReader::parse(), which is where the size cap, the prolog DOCTYPE refusal '.
                    'and the depth limit are.',
                    $relative,
                    $needle,
                    $why,
                );
            }
        }

        return $violations;
    }

    /**
     * An XSD may not fetch its own imports.
     *
     * `schemaValidate()` is a second parser and it was unguarded: libxml opens
     * the `.xsd` itself, follows every `xs:import` in it, and resolves each one
     * through whatever external entity loader is installed - which, outside
     * the reader's own `try`, is libxml's default. The official UBL and CII
     * distributions carry absolute `http://docs.oasis-open.org/...` locations,
     * so vendoring them unchanged would have turned every validated invoice
     * into an outbound request to a third party.
     *
     * The check writes a schema that imports a URL, validates against it, and
     * asserts that the attempt was refused and REPORTED. Reported matters as
     * much as refused: a loader that silently returns nothing makes a schema
     * whose imports did not load look like a document that passed.
     *
     * @return list<string>
     */
    private function schemasCannotFetch(): array
    {
        /*
         | The probe schema has to live UNDER resources/schemas.
         |
         | That directory is the loader's allowlist root, so a schema outside
         | it would be refused for being outside it - which is the wrong
         | branch. The probe is the allowed main schema importing a forbidden
         | reference, so it has to be inside.
         |
         | Whether the root existed beforehand is remembered, because PF-114
         | has not vendored the schemas yet and leaving an empty
         | `resources/schemas` behind would mean running this suite changes the
         | repository.
         */
        $root = base_path('resources/schemas');
        $rootExisted = is_dir($root);
        $directory = $root.'/pf235-probe';

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return ['the probe schema directory could not be created, so this check checked nothing'];
        }

        $schema = $directory.'/probe.xsd';

        /*
         | Written with no leading whitespace, which is load-bearing.
         |
         | An XML declaration has to be the first thing in the file. An
         | indented heredoc whose closing identifier is less indented than its
         | body leaves spaces in front of `<?xml`, the schema then fails to
         | parse, and libxml never reaches the import - so the check reports
         | "no refused reference" and reads as a defence that is not working.
         | It cost a debugging session: the refusal under test never happened
         | because the payload was never valid enough to attempt it.
         */
        file_put_contents($schema, implode("\n", [
            '<?xml version="1.0"?>',
            '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">',
            '  <xs:import namespace="urn:pf235:probe" schemaLocation="http://127.0.0.1:1/stolen.xsd"/>',
            '  <xs:element name="Invoice" type="xs:string"/>',
            '</xs:schema>',
        ]));

        $this->temporary[] = $schema;

        try {
            $document = SafeXmlReader::parse('<Invoice>x</Invoice>');
            $refused = [];

            $previousLoader = libxml_set_external_entity_loader(
                static function (?string $publicId, string $systemId) use (&$refused) {
                    $root = realpath(base_path('resources/schemas'));
                    $resolved = realpath($systemId);

                    if ($root !== false && $resolved !== false && str_starts_with($resolved, $root.\DIRECTORY_SEPARATOR)) {
                        return fopen($resolved, 'rb') ?: null;
                    }

                    $refused[] = $systemId;

                    return null;
                },
            );

            $previousErrors = libxml_use_internal_errors(true);

            try {
                /*
                 | Suppressed here for the same reason the pipeline suppresses
                 | it: a schema whose import is refused fails to COMPILE, and
                 | `schemaValidate()` raises a PHP warning for that which
                 | `libxml_use_internal_errors()` does not cover. Laravel
                 | turns it into an ErrorException, which took this whole
                 | suite down the first time it ran.
                 */
                @$document->schemaValidate($schema);
            } catch (Throwable) {
                // A schema that will not compile is the expected outcome; what
                // is under test is whether the reference was refused at all.
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
                libxml_set_external_entity_loader(\is_callable($previousLoader) ? $previousLoader : null);
            }

            if ($refused === []) {
                return ['a schema importing http://127.0.0.1:1/stolen.xsd produced no refused reference, '.
                    'so libxml either resolved it or was never asked - either way the loader in '.
                    'ValidationPipeline::validateAgainst() is not the one deciding.'];
            }

            /*
             | And now the PRODUCTION method, through reflection.
             |
             | The loop above proves the mechanism can work; this proves the
             | pipeline is the thing doing it. `validateAgainst()` is private
             | because nothing outside the pipeline should choose a schema, and
             | the alternative to reflection was to drop a probe schema at
             | `resources/schemas/ubl/maindoc.xsd` - the path `schemaFor()`
             | looks at - which would change what the whole exchange suite
             | validates against and would poison it if this check ever died
             | mid-way.
             |
             | A substring search was tried first and it did not bite: the
             | string `libxml_set_external_entity_loader` also appears in the
             | `finally` that restores the loader, so removing the install
             | left the check passing. Which is the lesson this whole file is
             | about - a check that reads the source for a word is a check
             | about the word.
             */
            $pipeline = new \App\Exchange\Pipeline\ValidationPipeline(
                app(\App\Exchange\Validation\CardinalityRules::class),
                app(\App\Exchange\Validation\ConditionalRules::class),
                app(\App\Exchange\Validation\CalculationRules::class),
            );

            $validate = new \ReflectionMethod($pipeline, 'validateAgainst');

            /** @var list<\App\Exchange\Validation\SemanticViolation> $reported */
            $reported = $validate->invoke($pipeline, '<Invoice>x</Invoice>', $schema);

            $named = false;

            foreach ($reported as $violation) {
                if (str_contains($violation->rule, 'SCHEMA-REFERENCE')) {
                    $named = true;

                    break;
                }
            }

            if (! $named) {
                return [sprintf(
                    'ValidationPipeline::validateAgainst() validated against a schema importing '.
                    'http://127.0.0.1:1/stolen.xsd and reported %d violation(s), none of which names the '.
                    'refused reference. A validator whose imports silently did not load reports a '.
                    'document as valid when nobody has checked its syntax - and one whose imports DID '.
                    'load has made every invoice depend on a third party being up, with whoever answers '.
                    'for that host deciding what counts as valid.',
                    \count($reported),
                )];
            }

            return [];
        } finally {
            if (is_file($schema)) {
                @unlink($schema);
            }

            if (is_dir($directory)) {
                @rmdir($directory);
            }

            if (! $rootExisted && is_dir($root)) {
                @rmdir($root);
            }
        }
    }

    /**
     * Run a reader and describe what happened, without letting it throw.
     *
     * @param  callable(string): mixed  $read
     * @return array{refused: bool, why: string, text: string}
     */
    private function outcomeOf(callable $read, string $xml): array
    {
        try {
            $value = $read($xml);

            return [
                'refused' => false,
                'why' => '',
                'text' => \is_string($value) ? $value : $this->flatten($value),
            ];
        } catch (MalformedXml|StatementUnreadable|NotCanonicalizable $e) {
            return ['refused' => true, 'why' => $e->getMessage(), 'text' => $e->getMessage()];
        } catch (Throwable $e) {
            /*
             | Any other exception is a refusal too, and is reported as one
             | with its class in the message.
             |
             | A reader that refuses a payload by throwing a TypeError has
             | still not read the file, which is the property under test - but
             | it has refused for a reason nobody designed, so the class name
             | travels with it.
             */
            return [
                'refused' => true,
                'why' => sprintf('%s: %s', $e::class, $e->getMessage()),
                'text' => $e->getMessage(),
            ];
        }
    }

    /** The first sentence of a refusal, so a failure message stays readable. */
    private function firstSentenceOf(string $message): string
    {
        $end = strpos($message, '. ');

        return $end === false ? $message : substr($message, 0, $end + 1);
    }

    /** Everything a parser returned, as one string to search for the bait in. */
    private function flatten(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if (\is_array($value)) {
            return implode(' ', array_map($this->flatten(...), $value));
        }

        if (\is_object($value)) {
            return implode(' ', array_map($this->flatten(...), (array) $value));
        }

        return '';
    }


    /*
    |---------------------------------------------------------------------------
    | PF-236 - uploads
    |---------------------------------------------------------------------------
    */

    /**
     * The override attack, with the display form written out.
     *
     * `invoice` + U+202E + `sj.nosj` is seven visible characters that render
     * as `invoice json.js` read right-to-left - so a list shows `.js` where
     * the bytes say `.nosj`, or with the characters chosen differently, shows
     * `.pdf` where the bytes say something executable. The person about to
     * click it has been told the truth by nothing on the screen.
     *
     * Each isolate and each zero-width character is fired separately rather
     * than as one example, because a refusal built on a single character is
     * one that a different character walks straight past - and U+2066 does
     * the same job as U+202E with a different code point.
     *
     * @return list<string>
     */
    private function bidiOverrideIsRefused(): array
    {
        $violations = [];

        $attacks = [
            "invoice\u{202E}sj.nosj" => 'RIGHT-TO-LEFT OVERRIDE',
            "invoice\u{202D}fdp.exe" => 'LEFT-TO-RIGHT OVERRIDE',
            "invoice\u{2066}fdp.exe" => 'LEFT-TO-RIGHT ISOLATE',
            "invoice\u{2067}sj.js" => 'RIGHT-TO-LEFT ISOLATE',
            "invoice\u{200B}.pdf" => 'ZERO WIDTH SPACE',
            "invoice\u{FEFF}.pdf" => 'a byte-order mark mid-string',
            "invo\u{00AD}ice.pdf" => 'SOFT HYPHEN',
            "invoice\u{200E}.pdf" => 'LEFT-TO-RIGHT MARK',
        ];

        foreach ($attacks as $name => $description) {
            if (UploadFileName::refusal($name) === null) {
                $violations[] = sprintf(
                    'a filename containing %s was accepted. It renders as something other than what it '.
                    'is, so the extension an approver reads is not the extension the file has.',
                    $description,
                );
            }
        }

        /*
         | And Arabic must still pass, with no control characters in it.
         |
         | This is the half that makes the rule usable. Most attachment names
         | in this product are Arabic; a rule that refused right-to-left TEXT
         | rather than right-to-left CONTROLS would refuse almost every real
         | upload, and the person who then turned it off would be right.
         */
        foreach ([
            'فاتورة.pdf',
            'كشف-حساب-يناير.pdf',
            'عقد الإيجار ٢٠٢٦.pdf',
            'invoice-فاتورة.pdf',
        ] as $legitimate) {
            $refusal = UploadFileName::refusal($legitimate);

            if ($refusal !== null) {
                $violations[] = sprintf(
                    'the Arabic filename "%s" was REFUSED: %s. Arabic letters carry their own direction '.
                    'and need no control character to render, so refusing one is refusing the ordinary '.
                    'case this product was built for.',
                    $legitimate,
                    $refusal,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function confusablesAreRefused(): array
    {
        $violations = [];

        /*
         | `invoiсe.pdf` with a Cyrillic `с` (U+0441), and the Greek omicron
         | standing in for an `o`. Both sit in a list beside the genuine
         | document and are indistinguishable from it in every font.
         */
        $attacks = [
            "invoi\u{0441}e.pdf" => 'a Cyrillic es standing in for a Latin c',
            "inv\u{03BF}ice.pdf" => 'a Greek omicron standing in for a Latin o',
            "p\u{0430}yment-advice.pdf" => 'a Cyrillic a standing in for a Latin a',
        ];

        foreach ($attacks as $name => $description) {
            if (UploadFileName::refusal($name) === null) {
                $violations[] = sprintf(
                    'a filename with %s was accepted. Two documents whose names differ only in that '.
                    'character look identical, and one of them is the one somebody is about to approve.',
                    $description,
                );
            }
        }

        /*
         | A name written ENTIRELY in one script is not a confusable.
         |
         | A Russian supplier's invoice is in Cyrillic throughout. There is
         | nothing for it to be confused with, and refusing it would make the
         | rule a refusal of foreign names rather than of forged ones.
         */
        /*
         | A name written ENTIRELY in one script, with a Latin extension.
         |
         | Which is every such name, because the extension allowlist is Latin
         | by construction - `.pdf` is `.pdf` in every language. So the
         | mixed-script rule has to be applied to the STEM alone, and the
         | first version of it was not: it refused `отчет.pdf` for mixing
         | Cyrillic with the Latin letters in its own extension, which would
         | have refused every Russian and Greek supplier's invoice while
         | reporting a confusable-name attack.
         */
        foreach ([
            'отчет.pdf' => 'Cyrillic',
            'λογαριασμός.pdf' => 'Greek',
            /*
             | Arabic belongs in THIS check as well as in the bidi one.
             |
             | The rule being guarded is the mixed-script rule, so its own
             | false-positive case has to live beside it: widening the script
             | list to include Arabic was caught only by the neighbouring
             | check, which meant the check that owned the rule did not own
             | the way the rule goes wrong.
             */
            'فاتورة.pdf' => 'Arabic',
            'invoice-فاتورة.pdf' => 'Arabic beside Latin, which nobody confuses',
        ] as $name => $script) {
            if (UploadFileName::refusal($name) === null) {
                continue;
            }

            $violations[] = sprintf(
                'the %s filename "%s" was refused. A name written entirely in one script is confusable '.
                'with nothing; only a MIXTURE is.',
                $script,
                $name,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function unusableNamesAreRefused(): array
    {
        $violations = [];

        $attacks = [
            '../../.env' => 'a path traversal',
            '..\\..\\windows\\system32\\config\\sam' => 'a Windows path traversal',
            "invoice.pdf\0.html" => 'a NUL, which truncates the name for anything written in C',
            "invoice\r\n.pdf" => 'a CRLF, which ends one HTTP header and starts another',
            '.htaccess' => 'a leading dot, which makes it a hidden file nobody can find afterwards',
            'report.pdf.' => 'a trailing dot, which Windows strips when it opens the path',
            'report.html.pdf ' => 'a trailing space, stripped the same way',
            'NUL.pdf' => 'an MS-DOS device name, which opens the device rather than a file',
            'con.pdf' => 'the same, lowercase',
            '..' => 'the parent directory',
            '.' => 'the current directory',
            '' => 'nothing at all',
        ];

        foreach ($attacks as $name => $description) {
            if (UploadFileName::refusal($name) === null) {
                $violations[] = sprintf('a filename that is %s was accepted: %s', $description, var_export($name, true));
            }
        }

        $long = str_repeat('a', 300).'.pdf';

        if (UploadFileName::refusal($long) === null) {
            $violations[] = 'a 304-byte filename was accepted. 255 bytes is the per-component limit on '.
                'every filesystem this runs on, so the download would fail after the file had been '.
                'stored, scanned and listed.';
        }

        /*
         | Invalid UTF-8, which is the case every other rule depends on.
         |
         | `mb_*` and `preg_*` with the `u` modifier both give up on invalid
         | UTF-8 - `preg_match` returns false, which is not 1, which reads as
         | "no match" to every check above. So a name that is not text has to
         | be refused first or the refusals that follow it are advisory.
         */
        if (UploadFileName::refusal("inv\xC3\x28oice.pdf") === null) {
            $violations[] = 'a filename containing invalid UTF-8 was accepted. Every rule after the '.
                'encoding check uses preg_match with the u modifier, which returns false rather than 0 '.
                'on invalid input - so the checks that follow would all read as "no match".';
        }

        return $violations;
    }

    /** @return list<string> */
    private function extensionAllowlist(): array
    {
        $violations = [];
        $sniffer = new ContentTypeSniffer();

        foreach ([
            'invoice' => 'no extension at all',
            'invoice.exe' => 'an executable',
            'invoice.svg' => 'an SVG, which executes script in a browser',
            'invoice.html' => 'an HTML document',
            'invoice.js' => 'a script',
            'invoice.phar' => 'a PHP archive',
        ] as $name => $description) {
            if ($sniffer->extensionRefusal($name) === null) {
                $violations[] = sprintf(
                    'the name "%s" - %s - passed the extension allowlist. PF-236 asks for an allowlist, '.
                    'and one that admits the empty string or an unlisted extension is not one.',
                    $name,
                    $description,
                );
            }
        }

        foreach (['invoice.pdf', 'statement.csv', 'book.xlsx', 'فاتورة.pdf'] as $legitimate) {
            $refusal = $sniffer->extensionRefusal($legitimate);

            if ($refusal !== null) {
                $violations[] = sprintf('the ordinary name "%s" was refused: %s', $legitimate, $refusal);
            }
        }

        /*
         | And the store must actually call it.
         |
         | The sniffer answering correctly proves nothing if the upload path
         | never asks, which is exactly the state the empty extension was in:
         | `isPermitted()` cleared the bytes, `mismatch()` was silent because
         | there was nothing to compare, and the file was stored.
         */
        $file = $this->fileContaining("%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        foreach (['invoice-with-no-extension', "invoice\u{202E}fdp.exe"] as $name) {
            try {
                app(AttachmentStore::class)->attach(
                    'pf236-probe-entity',
                    'probe',
                    'probe',
                    $file,
                    $name,
                );

                $violations[] = sprintf(
                    'AttachmentStore::attach() accepted the name %s. The sniffer refuses it and the '.
                    'store did not ask.',
                    var_export($name, true),
                );
            } catch (AttachmentRefused) {
                // Refused, as it must be - before any database work.
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    'AttachmentStore::attach() failed on %s with %s rather than refusing it: %s',
                    var_export($name, true),
                    $e::class,
                    $e->getMessage(),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function namesAreNormalized(): array
    {
        $violations = [];

        /*
         | `é` as one code point, and as `e` + U+0301.
         |
         | The same name to every person who reads it and two different
         | strings to every comparison. Both are accepted - neither is an
         | attack - and both must come out of the store as the same bytes, or
         | the attachment list shows two rows that cannot be told apart.
         */
        $composed = "r\u{00E9}sum\u{00E9}.pdf";
        $decomposed = "re\u{0301}sume\u{0301}.pdf";

        if (UploadFileName::refusal($composed) !== null || UploadFileName::refusal($decomposed) !== null) {
            $violations[] = 'a name carrying a diacritic was refused. Normalization is not a refusal: an '.
                'accented filename is the ordinary case in half of Europe.';
        }

        if (UploadFileName::normalize($composed) !== UploadFileName::normalize($decomposed)) {
            $violations[] = sprintf(
                'the composed and decomposed spellings of "résumé.pdf" normalize differently (%s against '.
                '%s). They are the same name to every person who reads them, so storing both produces '.
                'two rows in the attachment list that nobody can distinguish.',
                bin2hex(UploadFileName::normalize($composed)),
                bin2hex(UploadFileName::normalize($decomposed)),
            );
        }

        $store = (string) file_get_contents(base_path('app/Support/Attachments/AttachmentStore.php'));

        if (! str_contains($store, 'UploadFileName::normalize(')) {
            $violations[] = 'AttachmentStore::attach() does not normalize the declared name, so the form '.
                'that reaches the database is whichever one the uploader\'s operating system happened to '.
                'produce - and macOS produces the decomposed one while every other system produces the '.
                'composed one.';
        }

        return $violations;
    }

    /**
     * Nothing renders an upload, and the download cannot be rendered either.
     *
     * PF-236's last criterion: an Office or PDF document is never rendered
     * server-side in a way that resolves external references. The way to keep
     * that true is for nothing server-side to render an upload at all, so this
     * asserts the absence - no local PDF engine, no Office converter, no
     * ImageMagick - and then asserts the two properties of the download that
     * stop the browser rendering it either.
     *
     * The one thing that DOES read an uploaded PDF is
     * {@see \App\Exchange\Extraction\EmbeddedXml}, which walks the object
     * table for an embedded file stream. It renders nothing, follows no
     * reference and caps what a stream may inflate to, and what it hands on
     * goes through the safe reader.
     *
     * @return list<string>
     */
    private function nothingRendersAnUpload(): array
    {
        $violations = [];
        $base = \dirname(__DIR__, 2);

        $renderers = [
            'Dompdf' => 'a PDF renderer that follows remote stylesheets and images by default',
            'wkhtmltopdf' => 'a headless browser in a PDF renderer\'s clothing',
            'new \\Imagick' => 'ImageMagick, whose PDF and SVG delegates execute Ghostscript',
            'new Imagick' => 'ImageMagick, whose PDF and SVG delegates execute Ghostscript',
            'soffice' => 'LibreOffice, which resolves an Office document\'s external links',
            'libreoffice' => 'LibreOffice, which resolves an Office document\'s external links',
            'XSLTProcessor' => 'an XSLT engine, which has a document() function that reads files',
        ];

        foreach ($this->phpFilesIn($base.'/app') as $path) {
            $source = (string) file_get_contents($path);
            $relative = substr($path, \strlen($base) + 1);

            foreach ($renderers as $needle => $why) {
                if (str_contains($source, $needle)) {
                    $violations[] = sprintf(
                        '%s mentions %s - %s. An uploaded document is a file a stranger wrote, and '.
                        'rendering one server-side is how its external references become this '.
                        'application\'s outbound requests.',
                        $relative,
                        $needle,
                        $why,
                    );
                }
            }
        }

        /*
         | The download's own two properties.
         |
         | `Content-Disposition: attachment` and a Content-Security-Policy of
         | `sandbox` are what stop a stored HTML or SVG being rendered by the
         | browser instead. They are read from the controller because serving
         | a real attachment needs a provisioned tenant, an entity and a clean
         | scan verdict - and what matters is that the headers are there for
         | every file rather than for the one this check could arrange.
         */
        $controller = (string) file_get_contents($base.'/app/Http/Controllers/AttachmentDownloadController.php');

        foreach ([
            "'Content-Disposition' => \$this->disposition" => 'Content-Disposition',
            "default-src 'none'; sandbox" => 'a sandboxing Content-Security-Policy',
            "'X-Content-Type-Options' => 'nosniff'" => 'nosniff',
        ] as $needle => $what) {
            if (! str_contains($controller, $needle)) {
                $violations[] = sprintf(
                    'the attachment download no longer sets %s. Without it a stored HTML or SVG file is '.
                    'rendered rather than downloaded.',
                    $what,
                );
            }
        }

        if (! str_contains($controller, 'attachment; filename=')) {
            $violations[] = 'the download\'s Content-Disposition is not "attachment". "inline" renders '.
                'the file in the browser, which is the whole thing the separate origin exists to make '.
                'survivable.';
        }

        /*
         | And the separate origin and the short link, from the config the
         | production guard enforces rather than from a live URL - minting one
         | needs a provisioned tenant and a stored blob.
         */
        $guard = (string) file_get_contents($base.'/app/Support/Config/ProductionConfiguration.php');

        foreach (['attachments.origin', 'attachments.url_ttl_seconds'] as $key) {
            if (! str_contains($guard, $key)) {
                $violations[] = sprintf(
                    'production no longer refuses to boot on a wrong %s. A download origin that is '.
                    'empty, or a link that never expires, is how a stored file comes to execute where '.
                    'the session cookie is.',
                    $key,
                );
            }
        }

        return $violations;
    }

    /*
    |---------------------------------------------------------------------------
    | PF-233 - database privilege verification
    |---------------------------------------------------------------------------
    */

    /**
     * The privilege checks, or an honest skip.
     *
     * A skip rather than a pass when no tenant is provisioned. A check that
     * silently returns no violations when it could not run is a check that
     * reports green on a machine where the thing under test does not exist,
     * which is how a suite comes to prove nothing on the one machine it
     * matters on.
     *
     * @return array<string, list<string>>
     */
    private function privilegeChecks(): array
    {
        $tenant = Tenant::query()->with('connection')
            ->whereNotNull('provisioned_at')
            ->orderBy('created_at')
            ->first();

        if ($tenant === null) {
            return ['the privilege matrix (no tenant provisioned - SKIPPED)' => []];
        }

        /*
         | Every later check re-points the connection, and that is not
         | belt-and-braces.
         |
         | `theMatrixIsClean()` walks every provisioned tenant, so when it
         | returns the tenant connection is pointed at whichever tenant came
         | LAST in slug order - not at the one chosen here. The three checks
         | after it were therefore interrogating a different database from the
         | one they named.
         |
         | Measured while proving the DELETE check bites: a real
         | `GRANT DELETE ON journal_entries` was made on the first tenant, the
         | matrix reported it, and the DELETE check passed - because it was
         | attempting the DELETE against a tenant nobody had granted anything
         | on. A check that silently measures the wrong database is a check
         | that passes.
         */
        $pointAt = static function () use ($tenant): void {
            app(TenantConnectionResolver::class)->point($tenant);
        };

        $pointAt();

        $checks = [
            'the live privilege matrix matches the declared intent' => fn (): array => $this->theMatrixIsClean(),
            'the matrix bites, proved on every run' => fn (): array => $this->theMatrixBites(),
            'the application role cannot delete a posted entry' => fn (): array => $this->aPostedEntryCannotBeDeleted(),
            'the application role cannot ALTER anything' => fn (): array => $this->theApplicationRoleCannotAlter(),
            'the drift check is a command an operator can run' => fn (): array => $this->theDriftCheckIsWired(),
            'the hardening sees every marked table' => fn (): array => $this->hardeningSeesEveryMarkedTable(),
        ];

        $results = [];

        foreach ($checks as $name => $check) {
            $pointAt();

            $results[$name] = $check();
        }

        return $results;
    }

    /**
     * Every tenant's grants, read from the catalog.
     *
     * Every tenant rather than one. The drift this exists to catch is made by
     * hand on one database during an incident, so a check that samples the
     * first tenant alphabetically is a check that finds it one time in nine
     * hundred.
     *
     * Row-level security is deliberately not asked about here -
     * {@see TenantEnforcementRules::everyScopedTableHasAPolicy()} already
     * proves it on every tenant, and `privileges:verify` carries it for the
     * deploy.
     *
     * @return list<string>
     */
    private function theMatrixIsClean(): array
    {
        $matrix = new PrivilegeMatrix();
        $violations = [];
        $resolver = app(TenantConnectionResolver::class);

        $violations = [...$violations, ...array_map(
            static fn (string $drift): string => '[platform] '.$drift,
            $matrix->platformDrift(DB::connection('platform')),
        )];

        $tenants = Tenant::query()->with('connection')->whereNotNull('provisioned_at')->orderBy('slug')->get();

        if ($tenants->isEmpty()) {
            return [...$violations, 'no provisioned tenant was found, so the tenant half of the matrix '.
                'checked nothing'];
        }

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach ($matrix->tenantDrift(DB::connection('tenant')) as $drift) {
                $violations[] = sprintf('[%s] %s', (string) $tenant->slug, $drift);
            }
        }

        return $violations;
    }

    /**
     * Grant what the matrix forbids, confirm it is reported, roll back.
     *
     * On a correctly provisioned cluster every answer the matrix gives is the
     * right one, so a check that only ever reads a clean cluster has never
     * seen the code under test do its job. This makes the cluster dirty on
     * purpose, four ways, inside a transaction.
     *
     * `GRANT` and `REVOKE` are transactional in PostgreSQL, so the rollback is
     * complete - and the grants are made as the MIGRATOR, which owns the
     * tables, because the application role cannot grant itself anything. That
     * is the point of the arrangement and it is why this check needs the
     * migrator's credentials to be able to fail at all.
     *
     * The DELETE case is the one that found the real bug. `harden()` matched
     * its marker comment with `LIKE 'peakflow:no-delete%'`, anchored to the
     * start of the comment, and `journal_entry_fingerprints` writes its two
     * markers the other way round - so the table holding the SHA-256 of every
     * posted entry's substance kept DELETE on every tenant, and `harden()`
     * reported success.
     *
     * @return list<string>
     */
    private function theMatrixBites(): array
    {
        $violations = [];
        $matrix = new PrivilegeMatrix();
        $application = DB::connection('tenant');
        $role = (string) $application->selectOne('SELECT current_user AS role')->role;

        $migrator = $this->migratorOnTheTenantDatabase();

        if ($migrator === null) {
            return ['the migrator could not reach this tenant\'s database, so the matrix could not be '.
                'made to fail - and a check that cannot fail is worse than no check'];
        }

        $grammar = $migrator->getQueryGrammar();
        $wrapped = $grammar->wrap($role);

        $cases = [
            'TRUNCATE on the journal' => [
                'GRANT TRUNCATE ON journal_entries TO '.$wrapped,
                'holds TRUNCATE on journal_entries',
            ],
            'REFERENCES on the journal' => [
                'GRANT REFERENCES ON journal_entries TO '.$wrapped,
                'holds REFERENCES on journal_entries',
            ],
            'TRIGGER on the journal' => [
                'GRANT TRIGGER ON journal_entries TO '.$wrapped,
                'holds TRIGGER on journal_entries',
            ],
            'DELETE on the fingerprints, which is the bug this found' => [
                'GRANT DELETE ON journal_entry_fingerprints TO '.$wrapped,
                'holds DELETE on journal_entry_fingerprints',
            ],
            'UPDATE on the audit log' => [
                'GRANT UPDATE ON audit_log TO '.$wrapped,
                'holds UPDATE on audit_log',
            ],
        ];

        foreach ($cases as $description => [$grant, $expected]) {
            $migrator->beginTransaction();

            try {
                $migrator->statement($grant);

                /*
                 | Read on the MIGRATOR's connection, not the application's.
                 |
                 | The grant is inside this transaction and another session
                 | cannot see it, so asking the application's connection
                 | would report the clean state and the check would pass
                 | while proving nothing. That mistake is invisible: it looks
                 | like the matrix found nothing because there was nothing
                 | wrong.
                 */
                $drift = $matrix->tenantDrift($migrator, $role);
                $found = false;

                foreach ($drift as $finding) {
                    if (str_contains($finding, $expected)) {
                        $found = true;

                        break;
                    }
                }

                if (! $found) {
                    $violations[] = sprintf(
                        'a deliberate %s was NOT reported by the matrix. The grant was made and the '.
                        'matrix found %d other thing(s), so this privilege is one the check does not '.
                        'look at.',
                        $description,
                        \count($drift),
                    );
                }
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    'the probe for %s could not be set up: %s: %s',
                    $description,
                    $e::class,
                    $e->getMessage(),
                );
            } finally {
                $migrator->rollBack();
            }
        }

        /*
         | And the cluster is as it was, asked about the APPLICATION role.
         |
         | Not about `current_user` on this connection, which is the migrator
         | and legitimately holds everything on every table it owns. The first
         | version asked that question and reported 1,586 "leftover grants"
         | that were simply the owner's own privileges.
         */
        foreach ($matrix->tenantDrift($migrator, $role) as $leftover) {
            $violations[] = sprintf(
                'a probe grant survived its rollback: %s. GRANT is transactional in PostgreSQL, so this '.
                'means a transaction was not rolled back - and this check has just loosened the '.
                'database it was measuring.',
                $leftover,
            );
        }

        return $violations;
    }

    /**
     * The criterion, attempted rather than looked up.
     *
     * PF-233 asks that the application role be proven unable to DELETE a
     * posted row. The catalog answer is above; this is the statement. They are
     * different claims - `has_table_privilege` says what the catalog holds and
     * a DELETE says what the server does - and the two came apart in this
     * schema once already, when a privilege revoked on a partitioned table
     * left all 25 of its partitions writable through their own names.
     *
     * ## No fixture is needed, and that is not a shortcut
     *
     * PostgreSQL checks the table privilege when it plans the statement,
     * before any row is examined, so `DELETE FROM journal_entries` is refused
     * with `permission denied for table journal_entries` whether the table
     * holds a million rows or none. An earlier version of this check demanded
     * a seeded row and reported "seed the tenant before trusting this check"
     * on a fresh database - which is a check that declines to run on exactly
     * the machine a new engineer has.
     *
     * ## The positive control is the half that makes it mean something
     *
     * A role that can delete from nothing would pass every refusal above and
     * tell you nothing: the refusals might be about the role rather than
     * about the marker. So an UNMARKED table is deleted from as well, and it
     * has to succeed. Both halves run inside a transaction that is rolled
     * back.
     *
     * @return list<string>
     */
    private function aPostedEntryCannotBeDeleted(): array
    {
        $violations = [];
        $application = DB::connection('tenant');

        $refused = [
            'journal_entries' => 'a posted journal entry, which is corrected by reversal and never removed',
            'journal_lines' => 'the lines of one',
            'journal_entry_fingerprints' => 'the hash that proves an entry has not been altered - the table '.
                'whose DELETE survived harden() for a marker written in the other order',
            'audit_log' => 'an audit record',
            'bank_transactions' => 'a third party\'s assertion about what happened to the money',
            'fx_rates' => 'the rate a translated amount was posted at',
        ];

        foreach ($refused as $table => $what) {
            $application->beginTransaction();

            try {
                $deleted = $application->delete(sprintf('DELETE FROM %s', $table));

                $violations[] = sprintf(
                    'the application role was ALLOWED to DELETE from %s (%d row(s) matched) - %s. '.
                    'PostgreSQL checks the privilege before it looks at a single row, so this is the '.
                    'grant and not a property of the data.',
                    $table,
                    $deleted,
                    $what,
                );
            } catch (Throwable $e) {
                $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage();

                /*
                 | It must be refused for the RIGHT reason.
                 |
                 | A refusal naming a foreign key or a missing table would be
                 | the database declining for its own reasons on this
                 | particular schema, and would say nothing about what the
                 | role may do. Only a privilege error, or the append-only
                 | trigger, is the control PF-233 asks about.
                 */
                if (! str_contains($message, 'permission denied') && ! str_contains($message, 'append-only')) {
                    $violations[] = sprintf(
                        'DELETE FROM %s was refused, but not by a privilege and not by the append-only '.
                        'trigger: %s',
                        $table,
                        $message,
                    );
                }
            } finally {
                $application->rollBack();
            }
        }

        /*
         | The positive control.
         |
         | `entity_settings` carries no marker: a tenant changes a setting and
         | may remove one. If this is refused too then the role can delete
         | from nothing, every refusal above is explained by something other
         | than the markers, and this whole check has been passing for the
         | wrong reason.
         */
        $application->beginTransaction();

        try {
            $application->delete('DELETE FROM entity_settings WHERE key = ?', ['pf233-no-such-setting']);
        } catch (Throwable $e) {
            $violations[] = sprintf(
                'the application role could not DELETE from entity_settings either, which carries no '.
                'marker: %s. If the role can delete from nothing then the refusals above are not '.
                'evidence about the markers - they are evidence that the role is broken.',
                preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage(),
            );
        } finally {
            $application->rollBack();
        }

        return $violations;
    }

    /**
     * No ALTER, and no granting itself anything.
     *
     * {@see TenantEnforcementRules::applicationRoleHasNoDdl()} attempts
     * `CREATE TABLE`, `ALTER TABLE ... ADD COLUMN` and `DROP TABLE`. What is
     * added here is the forms that REMOVE A CONTROL rather than change the
     * schema: `DISABLE ROW LEVEL SECURITY` is one statement away from every
     * entity's rows, `DISABLE TRIGGER` turns off the append-only guard on the
     * audit log, and `DROP POLICY` does the first of those without the word
     * "ALTER" in it.
     *
     * ## Two ways this check lies if it is written the obvious way
     *
     * **`IF EXISTS` makes a refusal look like a success.** `DROP POLICY IF
     * EXISTS accounts_entity_scope ON accounts` returns successfully when
     * there is no policy of that name - without an ownership check, because
     * there is nothing to check ownership of. The policy here is actually
     * named something else, so the first version of this check reported that
     * the application role could drop a policy. It cannot. So the name is
     * read out of `pg_policies` and the statement carries no `IF EXISTS`.
     *
     * **A refused GRANT does not fail.** `GRANT TRUNCATE ON journal_entries
     * TO CURRENT_USER` issued by a role with no grant option emits a WARNING
     * - "no privileges were granted" - and returns success. Asserting that
     * the statement threw reports a hole that is not there; the honest
     * assertion is that the privilege is still absent afterwards.
     *
     * @return list<string>
     */
    private function theApplicationRoleCannotAlter(): array
    {
        $violations = [];
        $connection = DB::connection('tenant');

        $attempts = [
            'disable row-level security on accounts' => 'ALTER TABLE accounts DISABLE ROW LEVEL SECURITY',
            'disable the append-only trigger on the audit log' => 'ALTER TABLE audit_log DISABLE TRIGGER audit_log_append_only',
            'drop a primary key' => 'ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_pkey',
            'rename a table' => 'ALTER TABLE accounts RENAME TO accounts_pf233',
            'create a table of its own' => 'CREATE TABLE pf233_probe (id int)',
            'take ownership of a table' => 'ALTER TABLE accounts OWNER TO CURRENT_USER',
        ];

        $policy = $connection->selectOne(
            "SELECT policyname, tablename FROM pg_policies WHERE schemaname = 'public' ORDER BY tablename LIMIT 1",
        );

        if ($policy === null) {
            $violations[] = 'there are no row-level security policies in this tenant database at all, so '.
                'the DROP POLICY probe had nothing to aim at - which is a far larger finding than the '.
                'one this check was looking for.';
        } else {
            $attempts['drop a row-level security policy'] = sprintf(
                'DROP POLICY %s ON %s',
                $connection->getQueryGrammar()->wrap((string) $policy->policyname),
                $connection->getQueryGrammar()->wrapTable((string) $policy->tablename),
            );
        }

        foreach ($attempts as $description => $statement) {
            $connection->beginTransaction();

            try {
                $connection->statement($statement);

                $violations[] = sprintf(
                    'the application role could %s. Every grant in the privilege matrix is decorative '.
                    'next to a role that can take the controls off: `%s` succeeded.',
                    $description,
                    $statement,
                );
            } catch (Throwable) {
                // Refused, as it must be.
            } finally {
                $connection->rollBack();
            }
        }

        /*
         | And granting itself something has to have no EFFECT, which is a
         | different question from whether the statement failed.
         */
        $connection->beginTransaction();

        try {
            try {
                $connection->statement('GRANT TRUNCATE ON journal_entries TO CURRENT_USER');
            } catch (Throwable) {
                // Refused outright, which is also fine.
            }

            $granted = (bool) $connection->selectOne(
                "SELECT has_table_privilege(current_user, 'journal_entries', 'TRUNCATE') AS granted",
            )->granted;

            if ($granted) {
                $violations[] = 'the application role granted itself TRUNCATE on journal_entries. A role '.
                    'that can widen its own grants makes every revoke in the provisioner advisory, and '.
                    'TRUNCATE empties the ledger for every entity in the tenant past the policy that '.
                    'keeps one entity out of another\'s rows.';
            }
        } finally {
            $connection->rollBack();
        }

        return $violations;
    }

    /**
     * A drift check that is not on a deploy is a drift check nobody runs.
     *
     * Which is the third criterion. What can be asserted here is that the
     * command exists, is registered, reports a non-zero exit when it finds
     * drift, and names the repair - the deploy pipeline itself is not
     * something this suite can reach into, and a check that pretended
     * otherwise would be asserting the contents of a YAML file.
     *
     * @return list<string>
     */
    private function theDriftCheckIsWired(): array
    {
        $violations = [];

        $commands = \Illuminate\Support\Facades\Artisan::all();

        if (! isset($commands['privileges:verify'])) {
            return ['there is no `privileges:verify` command, so the matrix is only ever checked by '.
                'whoever remembers to run the test suite - and a grant made during an incident is made '.
                'by somebody who is not running tests.'];
        }

        $source = (string) file_get_contents(base_path('app/Console/Commands/PrivilegesVerify.php'));

        if (! str_contains($source, 'self::FAILURE')) {
            $violations[] = 'privileges:verify cannot exit non-zero, so a deploy step that runs it '.
                'cannot fail on drift - which makes it a report nobody reads.';
        }

        if (! str_contains($source, '--repair')) {
            $violations[] = 'privileges:verify offers no repair, so the operator who finds drift at 3am '.
                'has a finding and no remedy.';
        }

        /*
         | And the remedy has to be the provisioner's own hardening.
         |
         | A command with its own copy of the revokes would be a second
         | implementation of the privilege matrix, and the two would disagree
         | the first time a marker was added.
         */
        /*
         | The CALL, not the word.
         |
         | `str_contains($source, 'harden()')` was the first spelling and it
         | did not bite: the docblock above the method says
         | "re-runs TenantProvisioner::harden()", so replacing the actual call
         | left the check passing. Twice now in this file a substring search
         | has turned out to be a check about the substring.
         */
        if (! str_contains($source, '$provisioner->harden()')) {
            $violations[] = 'the repair does not go through TenantProvisioner::harden(), so there are '.
                'two implementations of the same revokes and they will disagree.';
        }

        return $violations;
    }

    /**
     * `harden()` must find every table that carries a marker.
     *
     * This is the check that would have caught the bug, and it is the only
     * one of the five that is about the PROVISIONER rather than about the
     * cluster. The matrix above reads the live grants, so it reports a table
     * whose DELETE was never revoked - but only once somebody has granted it
     * back, or on a tenant provisioned after the regression. On a cluster
     * where the privileges happen to be right already, a provisioner that has
     * stopped seeing a marked table looks exactly like one that is working,
     * and the next tenant provisioned is the one that finds out.
     *
     * So the provisioner's own marker query is run through reflection and
     * compared against the catalog. Reflection because `tablesMarked()` is
     * private, which is correct - nothing outside the provisioner should
     * choose which tables to revoke on - and because the alternative was to
     * assert the SQL text, which is a check about the SQL text.
     *
     * The regression it catches: the pattern was `LIKE 'peakflow:no-delete%'`,
     * anchored to the start of the comment, and
     * `journal_entry_fingerprints` is commented
     * `peakflow:tenant-scoped peakflow:no-delete - SHA-256 of each entry's
     * substance`. Fourteen tables carry the marker; thirteen were hardened;
     * `harden()` reported success.
     *
     * @return list<string>
     */
    private function hardeningSeesEveryMarkedTable(): array
    {
        $violations = [];
        $migrator = $this->migratorOnTheTenantDatabase();

        if ($migrator === null) {
            return ['the migrator could not reach this tenant\'s database, so what the provisioner sees '.
                'could not be compared with what is there'];
        }

        $tablesMarked = new \ReflectionMethod(\App\Platform\Services\TenantProvisioner::class, 'tablesMarked');

        foreach (['peakflow:append-only', 'peakflow:no-delete'] as $marker) {
            /*
             | The catalog's own answer, with the marker unanchored.
             |
             | `%marker%` is what "carries this marker" means: the markers are
             | a set, a table may carry two, and the order a migration writes
             | them in carries no meaning.
             */
            $expected = array_map(
                static fn (object $row): string => (string) $row->relname,
                $migrator->select(<<<'SQL'
                    WITH marked AS (
                        SELECT c.oid, c.relname
                        FROM pg_class c
                        JOIN pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = 'public'
                          AND c.relkind IN ('r', 'p')
                          AND coalesce(obj_description(c.oid, 'pg_class'), '') LIKE ?
                    )
                    SELECT relname FROM marked
                    UNION
                    SELECT child.relname
                    FROM pg_inherits i
                    JOIN marked m ON m.oid = i.inhparent
                    JOIN pg_class child ON child.oid = i.inhrelid
                SQL, ['%'.$marker.'%']),
            );

            if ($expected === []) {
                $violations[] = sprintf(
                    'no table in this tenant carries %s, so this check compared two empty lists.',
                    $marker,
                );

                continue;
            }

            /** @var list<string> $seen */
            $seen = $tablesMarked->invoke(app(\App\Platform\Services\TenantProvisioner::class), $migrator, $marker);

            $missed = array_values(array_diff($expected, $seen));

            if ($missed !== []) {
                $violations[] = sprintf(
                    'the provisioner sees %d of the %d tables carrying %s and misses %s. A table the '.
                    'hardening cannot see is a table whose privileges are whatever the default grants '.
                    'gave it - and harden() reports success, because from its point of view the table '.
                    'is not marked.',
                    \count($seen),
                    \count($expected),
                    $marker,
                    implode(', ', $missed),
                );
            }
        }

        return $violations;
    }

    /**
     * The migrator's credentials against the tenant currently pointed at.
     *
     * The application's own connection cannot grant itself anything, which is
     * the point of the whole arrangement - so a check that needs to make the
     * cluster dirty needs the owner. Built the same way
     * {@see \App\Platform\Services\TenantProvisioner} builds it: the tenant
     * connection's host and database with the migrator's username and
     * password.
     */
    private function migratorOnTheTenantDatabase(): ?\Illuminate\Database\Connection
    {
        try {
            $tenant = (array) config('database.connections.tenant');
            $migrator = (array) config('database.connections.migrator');

            config(['database.connections.pf233_migrator' => array_merge($tenant, [
                'username' => $migrator['username'],
                'password' => $migrator['password'],
            ])]);

            DB::purge('pf233_migrator');

            $connection = DB::connection('pf233_migrator');
            $connection->getPdo();

            return $connection;
        } catch (Throwable) {
            return null;
        }
    }

    /*
    |---------------------------------------------------------------------------
    | PF-237 - HTTP security headers
    |---------------------------------------------------------------------------
    */

    /**
     * A real response, because a header in a config file nobody loads is not
     * a header.
     *
     * Which is the criterion, word for word. Three responses are driven
     * through the real kernel: a route that exists, a URL that matches
     * nothing, and the same route over HTTPS. The 404 is the one that earns
     * its place - a response that never reaches a route does not pass through
     * the `web` middleware group, so a header registered there would be
     * absent from exactly the responses an attacker is most likely to be
     * looking at.
     *
     * @return list<string>
     */
    private function everyHeaderOnARealResponse(): array
    {
        $violations = [];

        $required = [
            'content-security-policy' => "default-src 'none'",
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer',
            'x-frame-options' => 'DENY',
            'cross-origin-opener-policy' => 'same-origin',
            'cross-origin-embedder-policy' => 'require-corp',
            'cross-origin-resource-policy' => 'same-origin',
            'permissions-policy' => 'camera=()',
        ];

        foreach ([
            'a route that exists' => 'http://peakflow.test/health',
            'a URL that matches no route' => 'http://peakflow.test/pf237-no-such-route',
        ] as $description => $url) {
            $response = $this->respondTo($url);

            if ($response === null) {
                $violations[] = sprintf('%s could not be requested at all', $description);

                continue;
            }

            foreach ($required as $header => $expected) {
                $actual = (string) $response->headers->get($header);

                if ($actual === '') {
                    $violations[] = sprintf('%s came back with no %s header', $description, $header);

                    continue;
                }

                if (! str_contains($actual, $expected)) {
                    $violations[] = sprintf(
                        '%s came back with %s: %s, which does not contain %s',
                        $description,
                        $header,
                        $actual,
                        $expected,
                    );
                }
            }

            /*
             | HSTS must be ABSENT over plain HTTP.
             |
             | A browser ignores it there, so sending it is not a hole - it is
             | a lie: a scan of a development machine would report the
             | protection as present.
             */
            if ($response->headers->has('strict-transport-security')) {
                $violations[] = sprintf(
                    '%s was served over http and carried Strict-Transport-Security anyway. A browser '.
                    'ignores it on a plain response, so the only thing it does is make a scan report a '.
                    'protection that is not there.',
                    $description,
                );
            }
        }

        $secure = $this->respondTo('https://peakflow.test/health');
        $hsts = $secure === null ? '' : (string) $secure->headers->get('strict-transport-security');

        if ($hsts === '') {
            $violations[] = 'an HTTPS response carried no Strict-Transport-Security header';
        } else {
            if (! str_contains($hsts, 'includeSubDomains')) {
                $violations[] = sprintf(
                    'Strict-Transport-Security is "%s" and does not include subdomains. A sibling host '.
                    'served over http is where a cookie set for the parent domain is stripped.',
                    $hsts,
                );
            }

            if (! str_contains($hsts, 'preload')) {
                $violations[] = sprintf('Strict-Transport-Security is "%s" and does not ask for preload.', $hsts);
            }

            if (preg_match('/max-age=(\d+)/', $hsts, $m) !== 1 || (int) $m[1] < 31536000) {
                $violations[] = sprintf(
                    'Strict-Transport-Security is "%s". The preload list refuses a max-age under a year, '.
                    'so this header asks for preload and will not get it - and it works perfectly in '.
                    'every browser, so nobody finds out.',
                    $hsts,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function noUnsafeKeywords(): array
    {
        $violations = [];
        $policy = app(ContentSecurityPolicy::class)->header();

        if (str_contains($policy, 'unsafe-eval')) {
            $violations[] = sprintf('the policy contains unsafe-eval: %s', $policy);
        }

        /*
         | `unsafe-inline` is permitted in exactly one directive, and the
         | directive is named here rather than in a comment.
         |
         | `style-src-attr` governs the `style=""` attribute and nothing else.
         | Six lines across two templates carry a computed one - a grid
         | column's width, a progress bar's share - and an attribute cannot
         | carry a nonce, because a nonce belongs to an element.
         |
         | What must stay out is every directive through which `unsafe-inline`
         | lets something RUN or LOAD: `script-src`, `script-src-attr`,
         | `script-src-elem`, `default-src`, and `style-src` itself, which
         | governs `<style>` elements and stylesheets.
         */
        foreach (explode(';', $policy) as $directive) {
            $directive = trim($directive);

            if (! str_contains($directive, "'unsafe-inline'")) {
                continue;
            }

            if (! str_starts_with($directive, 'style-src-attr ')) {
                $violations[] = sprintf(
                    'the directive "%s" carries unsafe-inline. The only directive allowed to is '.
                    'style-src-attr, which governs the style="" attribute and cannot run or load '.
                    'anything; in any other directive the keyword also tells the browser to ignore the '.
                    'nonce, which turns the whole policy off for scripts.',
                    $directive,
                );
            }
        }

        foreach (["script-src 'nonce-", "script-src-attr 'none'", "object-src 'none'", "base-uri 'none'", "frame-ancestors 'none'"] as $required) {
            if (! str_contains($policy, $required)) {
                $violations[] = sprintf('the policy does not contain "%s": %s', $required, $policy);
            }
        }

        /*
         | `script-src` must be a nonce and nothing else.
         |
         | `'self'` there would admit any script served from the
         | application's own origin, and 'strict-dynamic' would let a nonced
         | script load any other - which is a reasonable policy for an
         | application with a bundler graph and is not what this one has.
         */
        if (preg_match('/script-src ([^;]+)/', $policy, $m) === 1) {
            $value = trim($m[1]);

            if (preg_match("/^'nonce-[A-Za-z0-9+\/=]+'$/", $value) !== 1) {
                $violations[] = sprintf(
                    'script-src is "%s" and should be a nonce and nothing else. Every extra source is a '.
                    'place an injected script can be served from.',
                    $value,
                );
            }
        }

        return $violations;
    }

    /**
     * The nonce the header advertises must be the nonce in the markup.
     *
     * If they differ the policy blocks the script it was written to allow,
     * and the only symptom is the first paint happening in the wrong theme -
     * which the partial carrying that script exists to prevent, and which
     * nobody would attribute to a header. The failure is silent in both
     * directions, so it is asserted directly.
     *
     * @return list<string>
     */
    private function theNonceMatches(): array
    {
        $policy = app(ContentSecurityPolicy::class);
        $nonce = $policy->nonce();

        $violations = [];

        if (! str_contains($policy->header(), sprintf("'nonce-%s'", $nonce))) {
            $violations[] = 'the policy header does not carry the nonce the same instance hands to a view.';
        }

        $attribute = $policy->nonceAttribute();

        if ($attribute !== sprintf('nonce="%s"', $nonce)) {
            $violations[] = sprintf('the Blade attribute is %s and the nonce is %s', $attribute, $nonce);
        }

        /*
         | And the view uses the directive rather than a second lookup.
         |
         | A template that resolved the policy itself would be one nonce; a
         | template that generated its own would be a second. The directive
         | is the only spelling, so there is one answer per request.
         */
        $partial = (string) file_get_contents(base_path('resources/views/partials/theme-boot.blade.php'));

        if (! str_contains($partial, '<script @cspNonce>')) {
            $violations[] = 'the inline theme resolver does not carry @cspNonce, so a policy with no '.
                'unsafe-inline blocks it - and the symptom is one frame painted in the wrong theme, '.
                'which is exactly what that partial exists to prevent.';
        }

        /*
         | The one inline <style> in the application, on the error layout.
         |
         | It cannot use the compiled stylesheet, because the failures that
         | page explains include the build manifest being unreadable.
         */
        $errorLayout = (string) file_get_contents(base_path('resources/views/layouts/error.blade.php'));

        if (! str_contains($errorLayout, '<style @cspNonce>')) {
            $violations[] = 'the error layout\'s inline <style> carries no nonce, so every error page '.
                'renders unstyled under an enforcing policy - on the pages a user reaches when '.
                'something has already gone wrong.';
        }

        foreach (['app', 'ops', 'plain'] as $layout) {
            $source = (string) file_get_contents(base_path('resources/views/layouts/'.$layout.'.blade.php'));

            if (preg_match('/<script(?![^>]*@cspNonce)[^>]*>/', $source) === 1) {
                $violations[] = sprintf(
                    'layouts/%s.blade.php has a <script> tag with no @cspNonce.',
                    $layout,
                );
            }
        }

        return $violations;
    }

    /**
     * A different request gets a different nonce.
     *
     * The container's scoped instances are discarded explicitly here, because
     * that is what a request boundary IS in the two runtimes where a process
     * serves more than one: Octane and the queue worker both call
     * `forgetScopedInstances()` between requests, and PHP-FPM gets a fresh
     * container per process. A `singleton` would survive all three and serve
     * one nonce for the lifetime of the worker - which an attacker reads off
     * any page and reuses on every other.
     *
     * @return list<string>
     */
    private function theNonceIsPerRequest(): array
    {
        $first = app(ContentSecurityPolicy::class)->nonce();

        app()->forgetScopedInstances();

        $second = app(ContentSecurityPolicy::class)->nonce();

        if ($first === $second) {
            return ['the nonce survived forgetScopedInstances(), so the policy is bound as a singleton '.
                'rather than scoped. In Octane and in a queue worker that means one nonce for the '.
                'lifetime of the process: an attacker reads it off any page and uses it on every other, '.
                'which is the same as having no nonce.'];
        }

        if (\strlen(base64_decode($first, true) ?: '') < 16) {
            return [sprintf(
                'the nonce decodes to fewer than 16 bytes (%s). The specification asks for 128 bits '.
                'because a nonce guessable within the life of one page turns a reflected-input bug back '.
                'into a working injection.',
                $first,
            )];
        }

        return [];
    }

    /**
     * Report-only first, then enforcing - and the same policy either way.
     *
     * The phase must change the header's NAME and nothing else. A report-only
     * phase that reports against a weaker policy than the one that will be
     * enforced teaches nothing: every violation the enforcing policy would
     * raise goes unreported, and the switch to enforcing is the first time
     * anybody sees them.
     *
     * @return list<string>
     */
    private function bothPhasesEmitThePolicy(): array
    {
        $violations = [];
        $was = config('security.csp.enforce');

        try {
            config(['security.csp.enforce' => false]);
            $reportOnly = app(ContentSecurityPolicy::class);
            $reportOnlyName = $reportOnly->headerName();
            $reportOnlyPolicy = $reportOnly->header();

            config(['security.csp.enforce' => true]);
            $enforcingName = $reportOnly->headerName();
            $enforcingPolicy = $reportOnly->header();

            if ($reportOnlyName !== 'Content-Security-Policy-Report-Only') {
                $violations[] = sprintf(
                    'with enforcement off the header is named %s. A browser enforces anything called '.
                    'Content-Security-Policy, so a report-only phase under that name is an enforcing '.
                    'phase nobody meant to start.',
                    $reportOnlyName,
                );
            }

            if ($enforcingName !== 'Content-Security-Policy') {
                $violations[] = sprintf('with enforcement on the header is named %s', $enforcingName);
            }

            if ($reportOnlyPolicy !== $enforcingPolicy) {
                $violations[] = 'the report-only policy differs from the enforcing one, so the phase '.
                    'reports violations of a policy that is not the one about to be turned on.';
            }

            $response = $this->respondTo('http://peakflow.test/health');

            if ($response !== null && ! $response->headers->has('content-security-policy')) {
                $violations[] = 'an enforcing deployment served a response with no '.
                    'Content-Security-Policy header.';
            }
        } finally {
            config(['security.csp.enforce' => $was]);
        }

        return $violations;
    }

    /** @return list<string> */
    private function theSessionCookieIsHostPrefixed(): array
    {
        $violations = [];

        if (! str_starts_with(SessionCookieName::for(true, 'PeakFlow'), '__Host-')) {
            $violations[] = 'a secure deployment\'s session cookie is not host-prefixed. Without the '.
                'prefix a browser accepts a cookie of that name set by ANY sibling host and scoped to '.
                'the parent domain, and the Cookie header says nothing about which host set which '.
                'value - so the application cannot tell the attacker\'s session cookie from the real '.
                'one.';
        }

        if (str_starts_with(SessionCookieName::for(false, 'PeakFlow'), '__Host-')) {
            $violations[] = 'an insecure deployment\'s session cookie is host-prefixed. A browser drops '.
                'a `__Host-` cookie that is not Secure, and the symptom is a login form that reloads '.
                'for ever with nothing in any log mentioning a cookie - which is what a development '.
                'machine over plain HTTP would get.';
        }

        $config = (string) file_get_contents(base_path('config/session.php'));

        if (! str_contains($config, 'SessionCookieName::for(')) {
            $violations[] = 'config/session.php no longer derives the cookie name, so the prefix is '.
                'whatever an operator typed - and the name they copy from the development file is the '.
                'unprefixed one.';
        }

        /*
         | And a real secure response must actually carry it.
         |
         | The name is derived at boot from this machine's environment, which
         | is a development one, so the production branch is configured here
         | and a real response driven through the kernel. Restored in a
         | `finally`, because a leaked session cookie name would make every
         | later check in this process sign in to a different session.
         */
        $wasCookie = config('session.cookie');
        $wasSecure = config('session.secure');

        try {
            config([
                'session.cookie' => SessionCookieName::for(true, (string) config('app.name')),
                'session.secure' => true,
            ]);

            $this->discardTheSessionSingletons();

            $response = $this->respondTo('https://peakflow.test/health');
            $names = [];

            foreach ($response?->headers->getCookies() ?? [] as $cookie) {
                $names[] = $cookie->getName();

                if (! str_starts_with($cookie->getName(), '__Host-')) {
                    continue;
                }

                if (! $cookie->isSecure()) {
                    $violations[] = sprintf('%s was sent without Secure, so the browser drops it.', $cookie->getName());
                }

                if ($cookie->getPath() !== '/') {
                    $violations[] = sprintf('%s was sent with path %s; a `__Host-` cookie must be scoped to /.', $cookie->getName(), $cookie->getPath());
                }

                /*
                 | Both spellings of "no domain".
                 |
                 | Symfony's Cookie returns `null` for an unset domain and `''`
                 | for one set to the empty string, and only one of those is
                 | the default. Comparing against `''` alone reported a cookie
                 | with no Domain attribute as carrying one, with the value
                 | printed as nothing - a failure message that named the
                 | problem and showed an empty string where the cause was.
                 */
                if (($cookie->getDomain() ?? '') !== '') {
                    $violations[] = sprintf(
                        '%s was sent with Domain=%s; a `__Host-` cookie must carry none, and a browser '.
                        'drops one that does.',
                        $cookie->getName(),
                        (string) $cookie->getDomain(),
                    );
                }

                if (! $cookie->isHttpOnly()) {
                    $violations[] = sprintf('%s is readable from JavaScript.', $cookie->getName());
                }

                if (strtolower((string) $cookie->getSameSite()) !== 'lax') {
                    $violations[] = sprintf('%s has SameSite=%s and should be Lax.', $cookie->getName(), (string) $cookie->getSameSite());
                }
            }

            if ($names !== [] && ! \in_array(SessionCookieName::for(true, (string) config('app.name')), $names, true)) {
                $violations[] = sprintf(
                    'a secure response set %s and not the host-prefixed session cookie.',
                    implode(', ', $names),
                );
            }
        } finally {
            config(['session.cookie' => $wasCookie, 'session.secure' => $wasSecure]);

            // And the singletons go too, so nothing after this check is still
            // holding a store that writes a `__Host-` cookie over plain HTTP.
            $this->discardTheSessionSingletons();
        }

        return $violations;
    }

    /**
     * Make the session layer read the configuration again.
     *
     * Four singletons, and all four are needed. Each one was found by the
     * response coming back with the old cookie name on it:
     *
     * - `session` is the manager, and it caches each driver it builds.
     * - `session.store` is the driver it already built.
     * - `SessionManager::class` is a SEPARATE singleton from `session`.
     *   `app('session')` and `app(SessionManager::class)` are two different
     *   objects, and forgetting one leaves the other.
     * - `StartSession::class` is a singleton too, holding whichever manager
     *   it was constructed with - so the middleware keeps writing the old
     *   name even once the container is handing out a new manager to
     *   everybody else.
     *
     * A store's cookie name is read once, when it is constructed. Everything
     * else about the cookie - secure, path, domain, SameSite - is read per
     * request from the live configuration, which is why `secure` took effect
     * immediately and the name did not. That asymmetry is what made this take
     * four attempts to find.
     */
    private function discardTheSessionSingletons(): void
    {
        foreach ([
            'session',
            'session.store',
            \Illuminate\Session\SessionManager::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    private function respondTo(string $url): ?\Symfony\Component\HttpFoundation\Response
    {
        try {
            return app(Kernel::class)->handle(Request::create($url, 'GET'));
        } catch (Throwable) {
            return null;
        }
    }

    private function fileContaining(string $contents): string
    {
        $path = sys_get_temp_dir().'/pf236-'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->temporary[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
