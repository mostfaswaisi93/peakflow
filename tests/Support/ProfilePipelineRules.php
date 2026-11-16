<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Pipeline\ValidationPipeline;
use App\Exchange\Profiles\GenericProfile;
use App\Exchange\Profiles\JurisdictionProfile;
use App\Exchange\Profiles\ProfileRegistry;
use App\Exchange\Profiles\UnknownProfile;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Validation\SemanticViolation;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Xml\MalformedXml;
use App\Support\Xml\SafeXmlReader;
use Illuminate\Support\Facades\DB;

/**
 * The profile interface and the validation pipeline - PF-114, PF-117, PF-118, PF-119.
 *
 * ## What these are defending
 *
 * Three claims, each of which fails quietly if nobody checks it:
 *
 * 1. **Adding a country changes nothing in `Sales`.** Deptrac proves the
 *    direction of the dependency; a check here counts what a profile is
 *    allowed to touch.
 * 2. **The pipeline stops where it should.** A rule set run over a document
 *    that does not parse reports the wrong problem, and a supplier asked to
 *    fix a CIUS restriction on a document whose totals do not add up spends
 *    an afternoon on the second-largest problem.
 * 3. **A stage that cannot run says so.** The schemas are not vendored here,
 *    and a schema stage that silently passes is a pipeline that calls a
 *    document valid when nobody has checked its syntax.
 */
final class ProfilePipelineRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $staticChecks = [
            'the registry resolves only what is implemented' => $this->registry(),
            'a disabled profile is still resolvable' => $this->disabledIsStillResolvable(),
            'the generic profile needs no credentials' => $this->genericNeedsNothing(),
            'a profile declares every decision the interface asks for' => $this->interfaceIsAnswered(),
            'adding a profile touches nothing outside Exchange' => $this->profilesAreSelfContained(),
            'an unknown validation stage is refused' => $this->unknownStageIsRefused(),
            'a DOCTYPE is refused and a parser reads no files' => $this->xxe(),
        ];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$staticChecks, 'the pipeline (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return [
                ...$staticChecks,
                ...$this->withTheLedgerProof($this->runEachInASavepoint([
                    'a real document passes every stage that can run' => fn (): array => $this->passes(),
                    'the schema stage says it could not run' => fn (): array => $this->schemaSkipped(),
                    'the pipeline stops at the first stage that fails' => fn (): array => $this->stopsEarly(),
                    'a failure names the stage, not just the rule' => fn (): array => $this->namesTheStage(),
                    'our arithmetic is never put in front of a customer' => fn (): array => $this->internalIsSeparated(),
                    'the problem document is keyed by business term' => fn (): array => $this->problemDocument(),
                    'a document that fails stage 1 is never serialized' => fn (): array => $this->noSerializationOnFailure(),
                    'and the ledger still proves its own invariants' => fn (): array => [],
                ]), $this->entityId),
            ];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function registry(): array
    {
        $violations = [];
        $registry = app(ProfileRegistry::class);

        if (! $registry->has('generic')) {
            $violations[] = 'the generic profile is not registered, and every other profile specializes it';
        }

        try {
            $registry->get('atlantis');
            $violations[] = 'a profile nothing implements was resolved';
        } catch (UnknownProfile $e) {
            if (! str_contains($e->getMessage(), 'generic')) {
                $violations[] = 'the refusal does not list what is implemented, so the reader cannot '.
                    'tell a typo from a removed profile';
            }
        }

        /*
         | A key that is enabled and not built is reported.
         |
         | `config/jurisdictions.php` lists peppol, zatca, eta and jofotara
         | as future work. If one of them were switched on, a tenant could
         | select a profile that does not exist - and the first they would
         | know is a rejection on a real invoice.
         */
        foreach ($registry->declaredButNotBuilt() as $key) {
            if ($registry->isEnabled($key)) {
                $violations[] = sprintf(
                    'the %s profile is enabled in configuration and has no implementation. A tenant '.
                    'could select it and issue documents nothing can serialize',
                    $key,
                );
            }
        }

        $selectable = array_map(
            static fn (JurisdictionProfile $p): string => $p->key(),
            $registry->selectable(),
        );

        if ($selectable !== ['generic']) {
            $violations[] = sprintf('the selectable profiles are %s', implode(', ', $selectable) ?: 'none');
        }

        return $violations;
    }

    /** @return list<string> */
    private function disabledIsStillResolvable(): array
    {
        /*
         | A document issued last year under a profile since turned off has
         | to stay readable: it still serializes, still validates, and is
         | still reprintable from the archive. A registry that refused a
         | disabled profile would make those documents unreadable the day it
         | was disabled.
         */
        $registry = app(ProfileRegistry::class);
        $violations = [];

        config(['jurisdictions.enabled.generic' => false]);

        try {
            $profile = $registry->get('generic');

            if ($profile->key() !== 'generic') {
                $violations[] = 'the disabled profile resolved to something else';
            }

            if ($registry->selectable() !== []) {
                $violations[] = 'a disabled profile is still offered to a tenant';
            }

            if ($registry->forDocument('generic')->key() !== 'generic') {
                $violations[] = 'a document frozen under a now-disabled profile cannot be reserialized, '.
                    'and the archive copy is what the recipient holds';
            }
        } finally {
            config(['jurisdictions.enabled.generic' => true]);
        }

        return $violations;
    }

    /** @return list<string> */
    private function genericNeedsNothing(): array
    {
        $profile = app(GenericProfile::class);
        $violations = [];

        if ($profile->transport() !== 'file') {
            $violations[] = sprintf(
                'the baseline transport is "%s". It has to work with no access point and no '.
                'credentials, or the first time anybody sees the pipeline run is after they arrive',
                $profile->transport(),
            );
        }

        if ($profile->sealing() !== 'none' || $profile->submission() !== 'none') {
            $violations[] = 'the baseline profile requires sealing or submission, so a fresh clone '.
                'cannot issue a document';
        }

        $empty = SemanticInvoice::build(\App\Exchange\Semantics\SemanticNode::root(), 'generic', '380');

        if ($profile->check($empty) !== []) {
            $violations[] = 'the baseline invented a restriction of its own. A CIUS is what a '.
                'jurisdiction adds; a baseline that rejects what the standard permits is not a baseline';
        }

        return $violations;
    }

    /** @return list<string> */
    private function interfaceIsAnswered(): array
    {
        $violations = [];
        $profile = app(GenericProfile::class);

        $answers = [
            'permittedDocumentTypes' => $profile->permittedDocumentTypes(),
            'specificationIdentifier' => $profile->specificationIdentifier(),
            'validatorChain' => $profile->validatorChain(),
            'transport' => $profile->transport(),
            'sealing' => $profile->sealing(),
            'submission' => $profile->submission(),
            'retentionYears' => $profile->retentionYears(),
        ];

        foreach ($answers as $name => $value) {
            if ($value === [] || $value === '' || $value === 0) {
                $violations[] = sprintf('%s() answers nothing', $name);
            }
        }

        if (! \in_array('380', $profile->permittedDocumentTypes(), true)) {
            $violations[] = 'the baseline does not permit an invoice';
        }

        if ($profile->retentionYears() < 5) {
            $violations[] = sprintf(
                'the retention period is %d years and most jurisdictions require five to eleven',
                $profile->retentionYears(),
            );
        }

        if ($profile->validatorChain() === []) {
            $violations[] = 'the profile validates nothing';
        }

        return $violations;
    }

    /**
     * A profile is self-contained.
     *
     * @return list<string>
     */
    private function profilesAreSelfContained(): array
    {
        $violations = [];
        $directory = app_path('Exchange/Profiles');

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            /*
             | A profile may not reach into Sales, Ledger or Tax.
             |
             | Deptrac already forbids the direction, and this says WHY in
             | the terms of the task: adding a country is a new class plus
             | fixtures, and never a change to `Sales`. If a profile needs
             | something the document does not carry, the thing is added to
             | the document and frozen at issue - otherwise the second
             | country's requirements appear as conditionals in the issuer,
             | and by the fourth there is no issuer any more.
             */
            foreach (['Tenant\\Sales', 'Tenant\\Ledger', 'Tenant\\Tax', 'Tenant\\Parties'] as $forbidden) {
                if (str_contains($source, 'App\\'.$forbidden)) {
                    $violations[] = sprintf(
                        '%s reaches into %s. A profile reads the semantic model and nothing else',
                        basename($file),
                        $forbidden,
                    );
                }
            }

            if (str_contains($source, 'DB::') || str_contains($source, 'Illuminate\\Database')) {
                $violations[] = sprintf('%s touches the database', basename($file));
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function unknownStageIsRefused(): array
    {
        $profile = new class extends GenericProfile
        {
            /**
             * The invented stage FIRST, so the pipeline reaches it.
             *
             * With `['model', 'invented']` the empty document fails stage 1
             * and the pipeline stops - correctly - before the invented
             * stage, and the check proved nothing. Measured: it passed with
             * the refusal deleted.
             *
             * @return list<string>
             */
            public function validatorChain(): array
            {
                return ['invented'];
            }
        };

        $model = SemanticInvoice::build(
            \App\Exchange\Semantics\SemanticNode::root(),
            'generic',
            '380',
        );

        try {
            app(ValidationPipeline::class)->run($model, $profile);
        } catch (\LogicException $e) {
            return str_contains($e->getMessage(), 'invented')
                ? []
                : ['the refusal does not name the stage: '.$e->getMessage()];
        }

        return ['a chain naming a stage nothing implements ran without complaint, so a profile can '.
            'declare validation it does not get'];
    }

    /** @return list<string> */
    private function xxe(): array
    {
        $violations = [];

        $doctype = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/hostname">]>
            <foo>&xxe;</foo>
            XML;

        try {
            SafeXmlReader::parse($doctype);
            $violations[] = 'a document declaring an external entity was parsed. That is how an XML '.
                'reader becomes a file reader';
        } catch (MalformedXml $e) {
            if (! str_contains($e->getMessage(), 'DOCTYPE')) {
                $violations[] = 'the refusal does not say it was the DOCTYPE: '.$e->getMessage();
            }
        }

        $laughs = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE lolz [
              <!ENTITY lol "lol">
              <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
            ]>
            <lolz>&lol2;</lolz>
            XML;

        try {
            SafeXmlReader::parse($laughs);
            $violations[] = 'an entity expansion document was parsed. It needs no external reference, '.
                'so refusing the network does not stop it';
        } catch (MalformedXml) {
            // As intended.
        }

        try {
            SafeXmlReader::parse('<a><b></a>');
            $violations[] = 'malformed XML was accepted';
        } catch (MalformedXml $e) {
            if ($e->atLine === null) {
                $violations[] = 'the parse failure carries no line number, and the sender of a '.
                    '4,000-line invoice needs to be told where';
            }
        }

        try {
            SafeXmlReader::parse('');
            $violations[] = 'an empty document was accepted';
        } catch (MalformedXml) {
            // As intended.
        }

        // And a legitimate document still parses.
        try {
            $document = SafeXmlReader::parse('<Invoice><ID>INV-1</ID></Invoice>');

            if ($document->documentElement->localName !== 'Invoice') {
                $violations[] = 'a well-formed document did not parse correctly';
            }
        } catch (MalformedXml $e) {
            $violations[] = 'a well-formed document was refused: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    private function passes(): array
    {
        $report = $this->report();

        if ($report->passed()) {
            return [];
        }

        return array_map(
            static fn (SemanticViolation $v): string => sprintf(
                'a document issued through the real path fails %s at %s: %s',
                $v->rule,
                $v->address(),
                (string) $v->detail,
            ),
            $report->all(),
        );
    }

    /** @return list<string> */
    private function schemaSkipped(): array
    {
        $report = $this->report();
        $violations = [];

        if (! \in_array('schema', $report->ran, true)) {
            $violations[] = 'the schema stage did not run at all';
        }

        $reason = $report->skipped['schema'] ?? null;

        if ($reason === null) {
            /*
             | If the schemas HAVE been vendored, the stage ran for real and
             | there is nothing to skip - which is the state this check is
             | waiting for rather than failing on.
             */
            if (is_file(base_path('resources/schemas/ubl/maindoc.xsd'))) {
                return $violations;
            }

            $violations[] = 'the schema stage neither validated nor said why it could not. A stage '.
                'that silently passes is a pipeline calling a document valid when nobody checked its '.
                'syntax, and the first to find out is the recipient';
        } elseif (! str_contains((string) $reason, 'vendored')) {
            $violations[] = 'the skip reason does not say what is missing: '.$reason;
        }

        return $violations;
    }

    /** @return list<string> */
    private function stopsEarly(): array
    {
        $violations = [];
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());

        // Break stage 1: the buyer loses its name.
        $model->group(BG::Buyer)?->set(BT::BuyerName, null);

        $report = app(ValidationPipeline::class)->run($model, app(GenericProfile::class));

        if ($report->failedAt() !== 'model') {
            $violations[] = sprintf('the failure is reported at the %s stage', (string) $report->failedAt());
        }

        if (\in_array('en16931', $report->ran, true)) {
            $violations[] = 'the EN 16931 stage ran over a document the model had already rejected. '.
                'A rule set over a document with a hole in it reports the wrong problem';
        }

        $rules = array_map(static fn (SemanticViolation $v): string => $v->rule, $report->all());

        if (! \in_array('BR-07', $rules, true)) {
            $violations[] = sprintf('the missing buyer name reported %s and not BR-07', implode(', ', $rules));
        }

        return $violations;
    }

    /** @return list<string> */
    private function namesTheStage(): array
    {
        $violations = [];
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $model->totals()?->set(BT::TotalVatAmount, '1.00');

        $report = app(ValidationPipeline::class)->run($model, app(GenericProfile::class));

        if ($report->failedAt() !== 'en16931') {
            $violations[] = sprintf(
                'a calculation failure is reported at the %s stage. BR-CO-14 at stage 3 is a bug in '.
                'Tax; the same figure refused by a CIUS at stage 4 is a jurisdiction restriction, and '.
                'three different people fix those',
                (string) $report->failedAt(),
            );
        }

        if (($report->byStage['model'] ?? null) !== []) {
            $violations[] = 'the model stage reported something on a document whose only defect is '.
                'arithmetic';
        }

        return $violations;
    }

    /** @return list<string> */
    private function internalIsSeparated(): array
    {
        $violations = [];
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $model->totals()?->set(BT::TotalVatAmount, '1.00');

        $report = app(ValidationPipeline::class)->run($model, app(GenericProfile::class));

        if ($report->internal() === []) {
            $violations[] = 'a calculation failure is not marked internal';
        }

        if ($report->forTheUser() !== []) {
            $violations[] = 'a calculation failure reached the customer-facing list. The document was '.
                'built from stored figures the ledger has already posted: asking the customer to '.
                'correct it is asking them to fix our arithmetic';
        }

        return $violations;
    }

    /** @return list<string> */
    private function problemDocument(): array
    {
        $violations = [];
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());

        // A user-facing failure: the seller loses every identifier.
        $seller = $model->group(BG::Seller);
        $seller?->set(BT::SellerVatIdentifier, null)
            ->set(BT::SellerLegalRegistrationIdentifier, null)
            ->set(BT::SellerTaxRegistrationIdentifier, null);

        $report = app(ValidationPipeline::class)->run($model, app(GenericProfile::class));
        $problem = $report->toProblemDocument('https://peakflow.app/problems/invalid-document', '/documents/1');

        foreach (['type', 'title', 'status', 'detail', 'instance', 'errors'] as $key) {
            if (! isset($problem[$key])) {
                $violations[] = sprintf('the problem document has no "%s" - RFC 9457', $key);
            }
        }

        if (($problem['status'] ?? null) !== 422) {
            $violations[] = 'the status is not 422';
        }

        $errors = $problem['errors'] ?? [];

        if ($errors === []) {
            return [...$violations, 'the problem document carries no errors'];
        }

        foreach ($errors as $error) {
            if (! isset($error['term'])) {
                $violations[] = 'an error is not keyed by business term. Users do not read XPath';
            }

            if (preg_match('/^(BT|BG)-\d+$/', (string) ($error['term'] ?? '')) !== 1) {
                $violations[] = sprintf('an error is keyed by "%s"', (string) ($error['term'] ?? ''));
            }

            foreach (['en', 'ar'] as $locale) {
                if (trim((string) ($error[$locale] ?? '')) === '') {
                    $violations[] = sprintf('an error carries no %s explanation', $locale);
                }
            }

            if (! isset($error['rule'])) {
                $violations[] = 'an error does not carry the rule identifier the recipient would quote';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function noSerializationOnFailure(): array
    {
        /*
         | A profile whose serializer explodes if it is called.
         |
         | The document fails stage 1, so a pipeline that serialized before
         | validating would throw - which is the point: there is no reason to
         | render a document nobody can send.
         */
        $profile = new class extends GenericProfile
        {
            public function serializer(): \App\Exchange\Serializers\DocumentSerializer
            {
                return new class implements \App\Exchange\Serializers\DocumentSerializer
                {
                    public function syntax(): string
                    {
                        return 'exploding';
                    }

                    public function mediaType(): string
                    {
                        return 'application/xml';
                    }

                    public function serialize(SemanticInvoice $invoice): string
                    {
                        throw new \RuntimeException('the serializer was called');
                    }
                };
            }
        };

        $model = SemanticInvoice::build(\App\Exchange\Semantics\SemanticNode::root(), 'generic', '380');

        try {
            $report = app(ValidationPipeline::class)->run($model, $profile);
        } catch (\RuntimeException $e) {
            return ['an empty document was serialized before it was validated: '.$e->getMessage()];
        }

        return $report->failedAt() === 'model'
            ? []
            : [sprintf('the empty document failed at %s', (string) $report->failedAt())];
    }

    private function report(): \App\Exchange\Pipeline\ValidationReport
    {
        return app(ValidationPipeline::class)->run(
            app(SemanticInvoiceBuilder::class)->build($this->issue()),
            app(GenericProfile::class),
        );
    }
}
