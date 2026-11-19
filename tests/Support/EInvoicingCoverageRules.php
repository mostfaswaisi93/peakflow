<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Archive\Archive;
use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Pipeline\ValidationPipeline;
use App\Exchange\Profiles\GenericProfile;
use App\Exchange\Profiles\JurisdictionProfile;
use App\Exchange\Profiles\Peppol\EasCodes;
use App\Exchange\Profiles\Peppol\PeppolProfile;
use App\Exchange\Profiles\ProfileRegistry;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\Cii\CiiSerializer;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Exchange\Validation\CategoryRules;
use App\Exchange\Validation\SchemaFailure;
use App\Exchange\Validation\SemanticViolation;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * What the vendored artefacts prove, and what they say is still missing.
 *
 * PF-114, PF-116, PF-117, PF-120, PF-123, PF-129.
 *
 * ## The shape of this suite
 *
 * Every other suite in the Exchange plane checks that the code does what the
 * code says. This one checks the code against something outside it: three
 * artefacts published by CEN/TC 434, OpenPeppol and OpenPeppol's eDEC, with
 * their provenance and checksums in `schematron/PROVENANCE.md`.
 *
 * That changes what a failure means. A failure here is usually not "the
 * code is broken" but "the code and the standard have drifted apart", which
 * is the failure nobody notices: a rule identifier that is almost right, a
 * code list that was complete last year, a tolerance that is defensible and
 * is not the one the recipient applies.
 *
 * ## The two directions
 *
 * Forwards: every rule identifier the `Exchange` sources can emit is one a
 * vendored rule set contains, or is recorded in `config('en16931.divergences')`
 * with the official identifier beside it.
 *
 * Backwards: every rule the vendored EN 16931 set carries is implemented or
 * recorded in `config('en16931.not_implemented')` with the reason. Without
 * this direction the forward one is satisfied by implementing three rules
 * correctly, and the phase's own task file says the difference is to be
 * recorded rather than claimed.
 *
 * ## The schema stage is turned on with a FIXTURE schema, deliberately
 *
 * UBL 2.1 and CII D16B could not be fetched - see the end of
 * `schematron/PROVENANCE.md` - so `resources/schemas` is empty and stage 2
 * reports itself skipped. What can be proved without them is the property
 * PF-114 actually asks for: that **vendoring is the only thing needed**.
 * These checks write a small schema of their own to the exact path
 * `ValidationPipeline::schemaFor()` looks at, and show the stage switching
 * on, resolving a relative import inside the schema directory, refusing an
 * absolute one, and producing a failure that is addressed to a business
 * term. Then they remove it.
 *
 * The fixture schema is not a stand-in for UBL 2.1 and is not treated as
 * one: it accepts anything except the one element each check is about. A
 * hand-written approximation of a standards artefact would pass every check
 * here and fail at a trading partner.
 */
final class EInvoicingCoverageRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** Where `ValidationPipeline::schemaFor()` looks. */
    private const SCHEMA_DIRECTORY = 'resources/schemas/ubl';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $static = [
            'the vendored artefacts are the bytes their checksums name' => $this->artefactsAreIntact(),
            'every identifier the code can emit is official or a recorded divergence' => $this->forwards(),
            'every rule the vendored set carries is implemented or recorded' => $this->backwards(),
            'each category family behaves the way the vendored set words it' => $this->familiesMatchTheWording(),
            'the configured validation stages are the stages the pipeline runs' => $this->stages(),
            'the EAS set is the one the official Peppol rules test against' => $this->easIsDerived(),
            'the vendored EAS code list is the published one' => $this->easIsPublished(),
            'the ZATCA profile stays off because nothing can clear a document' => $this->zatcaStaysOff(),
            'an archive kind with no call site names the task that will write it' => $this->archiveRegister(),
        ];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$static, 'e-invoicing coverage (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return [
                ...$static,
                ...$this->withTheLedgerProof($this->runEachInASavepoint([
                    'vendoring a schema is the only thing that turns stage 2 on' => fn (): array => $this->stageTurnsOn(),
                    'a schema failure names the element, the rule and the term' => fn (): array => $this->schemaFailureIsAddressed(),
                    'validating a document opens no socket to the schema\'s host' => fn (): array => $this->noOutboundRequest(),
                    'the element-to-term table is what the serializers emit' => fn (): array => $this->termTable(),
                    'a standard-rated base that disagrees with its lines is refused' => fn (): array => $this->brS08(),
                    'a standard-rated tax that is not the base times the rate is refused' => fn (): array => $this->brS09(),
                    'a standard-rated line at a zero rate is refused' => fn (): array => $this->brS05(),
                    'a breakdown with no rate at all is refused' => fn (): array => $this->br48(),
                    'and a supply outside VAT may omit it, which is the rule\'s own exception' => fn (): array => $this->br48Exception(),
                    'a zero-rated category that states VAT is refused' => fn (): array => $this->brZ09(),
                    'an out-of-scope line that states a rate is refused' => fn (): array => $this->brO05(),
                    'the per-category rules are reported at stage 3' => fn (): array => $this->atStageThree(),
                    'an electronic address with an unknown scheme is refused' => fn (): array => $this->unknownScheme(),
                    'a withdrawn scheme is refused and named' => fn (): array => $this->withdrawnScheme(),
                    'every archive kind the archive declares round-trips' => fn (): array => $this->everyKindRoundTrips(),
                    'the archive\'s kinds and the database\'s are one list' => fn (): array => $this->kindsAgree(),
                ]), $this->entityId),
            ];
        } finally {
            $this->connection->rollBack();
        }
    }

    /*
     |--------------------------------------------------------------------------
     | PF-117: the two directions, against the vendored rule sets
     |--------------------------------------------------------------------------
     */

    /** @return list<string> */
    private function artefactsAreIntact(): array
    {
        $violations = [];
        $sums = $this->artefactPath('SHA256SUMS');

        if (! is_file($sums)) {
            return ['schematron/SHA256SUMS is missing, so no vendored artefact can be re-verified'];
        }

        $listed = 0;

        foreach (preg_split('/\R/', (string) file_get_contents($sums)) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+(\S.*)$/', trim($line), $found) !== 1) {
                continue;
            }

            $listed++;
            $path = $this->artefactPath($found[2]);

            if (! is_file($path)) {
                $violations[] = sprintf('%s is in SHA256SUMS and is not on disk', $found[2]);

                continue;
            }

            $actual = hash_file('sha256', $path);

            if ($actual !== $found[1]) {
                $violations[] = sprintf(
                    '%s hashes to %s and SHA256SUMS says %s. A vendored standards artefact that has '.
                    'been edited is no longer the standard, and every check that reads it is now '.
                    'checking somebody\'s opinion',
                    $found[2],
                    substr((string) $actual, 0, 16),
                    substr($found[1], 0, 16),
                );
            }
        }

        if ($listed < 3) {
            $violations[] = sprintf(
                'SHA256SUMS lists %d artefact(s) and the vendored set is the EN 16931 rules, the Peppol '.
                'rules and the EAS code list',
                $listed,
            );
        }

        foreach (['en16931' => 'BR-CO-26', 'peppol' => 'PEPPOL-EN16931-CL008'] as $set => $marker) {
            if (! str_contains($this->ruleSet($set), $marker)) {
                $violations[] = sprintf('the vendored %s rule set does not contain %s', $set, $marker);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function forwards(): array
    {
        $violations = [];
        $divergences = (array) config('en16931.divergences', []);
        $official = [
            'BR' => $this->identifiersIn($this->ruleSet('en16931')),
            'PEPPOL' => $this->identifiersIn($this->ruleSet('peppol')),
        ];

        if (\count($official['BR']) < 150 || \count($official['PEPPOL']) < 50) {
            return ['the vendored rule sets yielded too few identifiers to check anything against'];
        }

        foreach ($this->emitted() as $rule) {
            // `EN16931-*` and `ZATCA-*` are PeakFlow's own namespaces: the
            // first for failures that are not EN 16931 rules at all, the
            // second for a regime whose artefacts are not public.
            $family = str_starts_with($rule, 'BR-') ? 'BR' : (str_starts_with($rule, 'PEPPOL-') ? 'PEPPOL' : null);

            if ($family === null) {
                continue;
            }

            if (\in_array($rule, $official[$family], true)) {
                if (isset($divergences[$rule])) {
                    $violations[] = sprintf(
                        '%s is recorded as a divergence and the vendored rule set contains it, so the '.
                        'record is stale and is now describing a problem that has been fixed',
                        $rule,
                    );
                }

                continue;
            }

            if (! isset($divergences[$rule])) {
                $violations[] = sprintf(
                    '%s can be reported by the Exchange sources and no vendored rule set contains it. A '.
                    'rule that fires with an identifier the recipient\'s validator does not use sends '.
                    'whoever looks it up to a different rule. Either use the official identifier or '.
                    'record the divergence in config/en16931.php with the reason',
                    $rule,
                );

                continue;
            }

            $entry = (array) $divergences[$rule];
            $expected = $entry['official'] ?? null;

            if (trim((string) ($entry['reason'] ?? '')) === '') {
                $violations[] = sprintf('%s is recorded as a divergence with no reason', $rule);
            }

            if ($expected !== null && ! \in_array((string) $expected, $official[$family], true)) {
                $violations[] = sprintf(
                    '%s is recorded as diverging from %s, and %s is not in the vendored rule set either',
                    $rule,
                    (string) $expected,
                    (string) $expected,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function backwards(): array
    {
        $violations = [];
        $official = $this->identifiersIn($this->ruleSet('en16931'));
        $official = array_values(array_filter($official, static fn (string $id): bool => str_starts_with($id, 'BR-')));

        if (\count($official) < 150) {
            return ['the vendored EN 16931 rule set yielded too few identifiers to check against'];
        }

        $divergences = (array) config('en16931.divergences', []);
        $implemented = [];

        foreach ($this->emitted() as $rule) {
            if (! str_starts_with($rule, 'BR-')) {
                continue;
            }

            $implemented[] = $rule;

            // A rule implemented under a divergent spelling still counts as
            // implemented, under the official spelling.
            $under = $divergences[$rule]['official'] ?? null;

            if ($under !== null) {
                $implemented[] = (string) $under;
            }
        }

        $recorded = [];

        foreach ((array) config('en16931.not_implemented', []) as $reason => $rules) {
            if (trim((string) $reason) === '' || ! \is_array($rules)) {
                $violations[] = 'an entry in config(\'en16931.not_implemented\') has no reason or no rules';

                continue;
            }

            foreach ($rules as $rule) {
                $rule = (string) $rule;

                if (\in_array($rule, $recorded, true)) {
                    $violations[] = sprintf('%s is listed twice in the not-implemented register', $rule);
                }

                $recorded[] = $rule;

                if (! \in_array($rule, $official, true)) {
                    $violations[] = sprintf(
                        '%s is recorded as not implemented and the vendored rule set does not contain '.
                        'it, so the register is describing a rule that does not exist',
                        $rule,
                    );
                }

                if (\in_array($rule, $implemented, true)) {
                    $violations[] = sprintf(
                        '%s is recorded as not implemented and the Exchange sources can report it. '.
                        'Delete the line: a register that lists what has since been built is read as '.
                        'a gap that is not there',
                        $rule,
                    );
                }
            }
        }

        foreach ($official as $rule) {
            if (\in_array($rule, $implemented, true) || \in_array($rule, $recorded, true)) {
                continue;
            }

            $violations[] = sprintf(
                '%s is in the vendored EN 16931 rule set, nothing in Exchange reports it, and it is not '.
                'in config(\'en16931.not_implemented\'). PF-117 asks for the difference to be recorded '.
                'rather than claimed, and an unlisted rule is a claim',
                $rule,
            );
        }

        return $violations;
    }

    /*
     |--------------------------------------------------------------------------
     | PF-116: the per-category families
     |--------------------------------------------------------------------------
     */

    /**
     * Each family's rate rule against the vendored set's own sentence.
     *
     * The table in `CategoryRules` says `S` needs a rate above zero and `O`
     * needs none at all. This reads the assertion text for each `-05` rule
     * out of the vendored artefact and requires the declared shape to be
     * the one the sentence describes - so a family whose shape was copied
     * from its neighbour fails here rather than on a customer's document.
     *
     * @return list<string>
     */
    private function familiesMatchTheWording(): array
    {
        $violations = [];
        $set = $this->ruleSet('en16931');
        $expected = [
            'positive' => 'shall be greater than zero',
            'zero' => 'shall be 0 (zero)',
            'absent' => 'shall not contain',
            'zero_or_positive' => 'shall be 0 (zero) or greater than zero',
        ];

        $probe = new \ReflectionClass(CategoryRules::class);
        /** @var array<string, array<string, mixed>> $families */
        $families = $probe->getConstant('FAMILIES');

        if ($families === false || $families === []) {
            return ['CategoryRules declares no families at all'];
        }

        foreach ($families as $code => $family) {
            $rule = (string) $family['line'];
            $shape = (string) $family['rate'];

            if (preg_match('/\['.preg_quote($rule, '/').'\]-([^<]*)/', $set, $found) !== 1) {
                $violations[] = sprintf('%s is not in the vendored rule set', $rule);

                continue;
            }

            $text = preg_replace('/\s+/', ' ', $found[1]) ?? $found[1];
            $phrase = $expected[$shape] ?? null;

            if ($phrase === null) {
                $violations[] = sprintf('%s declares an unknown rate shape "%s"', $code, $shape);

                continue;
            }

            if (! str_contains($text, $phrase)) {
                $violations[] = sprintf(
                    '%s declares the rate shape "%s", whose sentence would read "%s", and %s reads: %s',
                    $code,
                    $shape,
                    $phrase,
                    $rule,
                    trim($text),
                );
            }

            // And the grouped families are the ones whose tax is a product.
            $taxRule = (string) $family['tax'];
            $grouped = $family['grouped'] === true;

            if (preg_match('/\['.preg_quote($taxRule, '/').'\]-([^<]*)/', $set, $taxFound) !== 1) {
                $violations[] = sprintf('%s is not in the vendored rule set', $taxRule);

                continue;
            }

            $taxText = preg_replace('/\s+/', ' ', $taxFound[1]) ?? $taxFound[1];
            $multiplies = str_contains($taxText, 'multiplied by');

            if ($multiplies !== $grouped) {
                $violations[] = sprintf(
                    '%s is declared %s and %s reads: %s',
                    $code,
                    $grouped ? 'as a family whose tax is a product' : 'as a family whose tax is zero',
                    $taxRule,
                    trim($taxText),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function brS08(): array
    {
        return $this->expect('BR-S-08', static function (SemanticInvoice $model): void {
            // 100.00 out, which is beyond the one-unit window the official
            // binding allows and well beyond any rounding.
            $model->root->children(BG::VatBreakdown)[0]->set(BT::VatCategoryTaxableAmount, '900.00');
        }, ['BR-S-09', 'BR-CO-17']);
    }

    /** @return list<string> */
    private function brS09(): array
    {
        return $this->expect('BR-S-09', static function (SemanticInvoice $model): void {
            // 900.00 of VAT on a base of 1,000.00 at 15%.
            $model->root->children(BG::VatBreakdown)[0]->set(BT::VatCategoryTaxAmount, '900.00');
        }, ['BR-CO-14', 'BR-CO-17']);
    }

    /**
     * `BR-48`: a VAT breakdown that states no rate.
     *
     * Named by `CalculationRules::vatPerGroup()` and by
     * `CategoryRules::taxAmount()` as the rule that should have refused
     * this, and implemented by neither until 2026-11-19 - so a breakdown
     * with no rate was refused by nothing. Found by the backward
     * completeness check against the vendored rule set, which is the whole
     * argument for asking that question in both directions.
     *
     * `BR-CO-17` and the category's own `-09` are allowed alongside, and
     * their presence is the reason this mattered: both of them skip a group
     * with no rate, so the two arithmetic checks that would have caught a
     * wrong tax amount stood down together, and the figure that ties them
     * to each other was the missing one.
     *
     * @return list<string>
     */
    private function br48(): array
    {
        return $this->expect('BR-48', static function (SemanticInvoice $model): void {
            $model->root->children(BG::VatBreakdown)[0]->set(BT::VatCategoryRate, null);
        }, ['BR-CO-17', 'BR-S-09']);
    }

    /**
     * `BR-48`'s exception: a supply not subject to VAT states no rate.
     *
     * > …except if the Invoice is not subject to VAT.
     *
     * The negative half, and it is here because the positive half alone did
     * not need it: removing the exception from the implementation changed
     * nothing and no check failed, because no fixture in this repository
     * produces an out-of-scope breakdown. An exception nothing exercises is
     * a clause that can be deleted without anybody noticing - and deleting
     * this one would refuse every out-of-scope document the product ever
     * issues.
     *
     * The document is expected to carry OTHER violations: the fixture's
     * figures are standard-rated at 15%, so moving the category to `O`
     * leaves a tax amount the `O` family forbids. That is not what this
     * asks. It asks only that `BR-48` is not among them, which is the
     * narrow claim the exception makes.
     *
     * @return list<string>
     */
    private function br48Exception(): array
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());

        $breakdown = $model->root->children(BG::VatBreakdown)[0];
        $breakdown->set(BT::VatCategoryCode, 'O');
        $breakdown->set(BT::VatCategoryRate, null);

        $reported = array_map(
            static fn (object $violation): string => (string) $violation->rule,
            app(ValidationPipeline::class)->run($model, app(GenericProfile::class))->all(),
        );

        if (! \in_array('BR-48', $reported, true)) {
            return [];
        }

        return [sprintf(
            'an out-of-scope breakdown stating no rate was refused under BR-48. The rule\'s own '.
            'sentence excepts a supply that is not subject to VAT, and "%s" is that category - so '.
            'this refuses every out-of-scope document the product can issue. Reported: %s',
            'Not subject to VAT',
            implode(', ', $reported),
        )];
    }

    /** @return list<string> */
    private function brS05(): array
    {
        return $this->expect('BR-S-05', static function (SemanticInvoice $model): void {
            $model->root->children(BG::InvoiceLine)[0]
                ->first(BG::LineVatInformation)
                ?->set(BT::LineVatRate, '0');
        }, ['BR-S-08', 'BR-S-09', 'BR-CO-17']);
    }

    /** @return list<string> */
    private function brZ09(): array
    {
        return $this->expect('BR-Z-09', static function (SemanticInvoice $model): void {
            $model->root->children(BG::VatBreakdown)[0]->set(BT::VatCategoryCode, 'Z');
        }, ['BR-Z-08', 'BR-Z-05', 'BR-CO-17', 'BR-S-08', 'BR-S-05']);
    }

    /** @return list<string> */
    private function brO05(): array
    {
        return $this->expect('BR-O-05', static function (SemanticInvoice $model): void {
            $model->root->children(BG::InvoiceLine)[0]
                ->first(BG::LineVatInformation)
                ?->set(BT::LineVatCategoryCode, 'O')
                ->set(BT::LineVatRate, '0');
        }, ['BR-S-08', 'BR-S-09', 'BR-O-08', 'BR-O-09', 'BR-CO-17']);
    }

    /**
     * The per-category rules are stage 3, not an afterthought.
     *
     * A rule that fires somewhere the report does not name is a rule the
     * API surfaces under the wrong heading, and PF-117 asks for the stage.
     *
     * @return list<string>
     */
    private function atStageThree(): array
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(GenericProfile::class);
        $pipeline = app(ValidationPipeline::class);

        $model->root->children(BG::VatBreakdown)[0]->set(BT::VatCategoryTaxableAmount, '900.00');

        $report = $pipeline->run($model, $profile);
        $stage = $report->failedAt();

        if ($stage !== 'en16931') {
            return [sprintf(
                'a per-category failure was reported at the "%s" stage and the EN 16931 rules are stage 3',
                (string) $stage,
            )];
        }

        foreach ($report->all() as $violation) {
            if ($violation->rule === 'BR-S-08') {
                return $violation->isInternal()
                    ? []
                    : ['BR-S-08 is reported as a customer-facing failure. The breakdown was built from '.
                        'the same stored rows as the lines, so a disagreement between them is ours'];
            }
        }

        return ['BR-S-08 did not fire at all'];
    }

    /*
     |--------------------------------------------------------------------------
     | PF-114: the schema stage
     |--------------------------------------------------------------------------
     */

    /** @return list<string> */
    private function stageTurnsOn(): array
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(GenericProfile::class);
        $pipeline = app(ValidationPipeline::class);

        $before = $pipeline->run($model, $profile);

        if (($before->skipped['schema'] ?? null) === null) {
            return ['stage 2 is not reporting itself skipped, so this check cannot show it turning on - '.
                'either the schemas have been vendored (in which case this check is obsolete and PF-114 '.
                'is closed) or the stage has stopped saying it could not run'];
        }

        return $this->withSchema($this->laxSchema('xs:decimal'), static function () use ($pipeline, $model, $profile): array {
            $report = $pipeline->run($model, $profile);
            $violations = [];

            if (($report->skipped['schema'] ?? null) !== null) {
                $violations[] = sprintf(
                    'a schema is on disk at resources/schemas/ubl/maindoc.xsd and stage 2 still reports '.
                    'itself skipped: %s',
                    (string) $report->skipped['schema'],
                );
            }

            if (! \in_array('schema', $report->ran, true)) {
                $violations[] = 'stage 2 did not run';
            }

            if (($report->byStage['schema'] ?? []) !== []) {
                $violations[] = sprintf(
                    'the document failed a schema that accepts everything but one element type: %s',
                    implode('; ', array_map(
                        static fn (SemanticViolation $v): string => (string) $v->detail,
                        $report->byStage['schema'],
                    )),
                );
            }

            return $violations;
        });
    }

    /** @return list<string> */
    private function schemaFailureIsAddressed(): array
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(GenericProfile::class);
        $pipeline = app(ValidationPipeline::class);

        // `xs:int` refuses `1150.00`, so the one element the fixture schema
        // declares is the one that fails.
        return $this->withSchema($this->laxSchema('xs:int'), static function () use ($pipeline, $model, $profile): array {
            $report = $pipeline->run($model, $profile);
            $schema = $report->byStage['schema'] ?? [];

            if ($schema === []) {
                return ['the document passed a schema that declares cbc:TaxInclusiveAmount as xs:int, '.
                    'so either the stage did not run or the import of the element declaration did not '.
                    'resolve inside resources/schemas'];
            }

            $violations = [];
            $failure = $schema[0];

            if ($failure->rule !== 'EN16931-SCHEMA') {
                $violations[] = sprintf('the failure is reported as %s', $failure->rule);
            }

            if ($failure->term !== BT::TotalWithVat) {
                $violations[] = sprintf(
                    'the failure is addressed to %s and cbc:TaxInclusiveAmount is BT-112. Users do not '.
                    'read XPath, and a schema failure with no business term is a message nobody can act on',
                    $failure->term?->value ?? 'no business term at all',
                );
            }

            if ($failure->path !== 'cbc:TaxInclusiveAmount') {
                $violations[] = sprintf(
                    'the syntax path is %s, and the engineer who has to fix the serializer needs the '.
                    'element',
                    $failure->path ?? 'absent',
                );
            }

            foreach (['xs:int', 'libxml 1824'] as $expected) {
                if (! str_contains((string) $failure->detail, $expected)) {
                    $violations[] = sprintf(
                        'the detail does not carry "%s", so the rule that was broken is not reported: %s',
                        $expected,
                        (string) $failure->detail,
                    );
                }
            }

            /*
             | And the line, because "the document could not be validated" is
             | not something the sender of a 4,000-line invoice can act on.
             */
            if (preg_match('/at line [1-9]\d*, column \d+/', (string) $failure->detail) !== 1) {
                $violations[] = sprintf(
                    'the detail names no line and column: %s',
                    (string) $failure->detail,
                );
            }

            return $violations;
        });
    }

    /**
     * Nothing connects to the host the schema names.
     *
     * The strongest form this can be proved in: a real listening socket on
     * loopback, a schema that imports from it, and an assertion that nothing
     * ever arrived. Measured with the loader removed from
     * `ValidationPipeline::validateAgainst()`, libxml connects, waits sixty
     * seconds for a reply and then skips the import - so the document
     * VALIDATES, the connection is in the accept queue, and the only thing
     * that noticed was this check.
     *
     * @return list<string>
     */
    private function noOutboundRequest(): array
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(GenericProfile::class);
        $pipeline = app(ValidationPipeline::class);

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            return [sprintf('a loopback listener could not be opened (%s), so nothing was proved', $errstr)];
        }

        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        try {
            $schema = $this->importingSchema(sprintf('http://127.0.0.1:%d/stolen.xsd', $port));

            $result = $this->withSchema($schema, static function () use ($pipeline, $model, $profile, $port): array {
                $report = $pipeline->run($model, $profile);
                $violations = [];
                $named = false;

                foreach ($report->all() as $violation) {
                    if ($violation->rule === 'EN16931-SCHEMA-REFERENCE'
                        && str_contains((string) $violation->detail, (string) $port)) {
                        $named = true;
                    }

                    if (str_contains((string) $violation->detail, 'libxml 1549')) {
                        $violations[] = sprintf(
                            'libxml reported its own I/O load error (1549) for the schema reference, '.
                            'which is the trace of an attempted fetch: %s',
                            (string) $violation->detail,
                        );
                    }
                }

                if (! $named) {
                    $violations[] = sprintf(
                        'the schema imported http://127.0.0.1:%d/stolen.xsd and no violation named it. '.
                        'A validator whose imports silently did not load calls a document valid when '.
                        'nobody checked its syntax',
                        $port,
                    );
                }

                return $violations;
            });

            $connection = @stream_socket_accept($server, 0);

            if ($connection !== false) {
                fclose($connection);

                $result[] = sprintf(
                    'something connected to 127.0.0.1:%d while a document was being validated. Every '.
                    'validated invoice is now an outbound request from a queue worker, and whoever '.
                    'answers for that host decides what counts as valid',
                    $port,
                );
            }

            return $result;
        } finally {
            fclose($server);
        }
    }

    /**
     * The element-to-term table, against what the serializers actually write.
     *
     * @return list<string>
     */
    private function termTable(): array
    {
        $violations = [];
        $model = $this->fullyPopulated();

        $documents = [
            'ubl' => app(UblSerializer::class)->serialize($model),
            'cii' => app(CiiSerializer::class)->serialize($model),
        ];

        foreach ($documents as $syntax => $xml) {
            $table = SchemaFailure::termsFor($syntax);

            if ($table === []) {
                $violations[] = sprintf('there is no element-to-term table for %s', $syntax);

                continue;
            }

            $document = new \DOMDocument();
            $document->loadXML($xml);

            foreach ($table as $element => $id) {
                $term = BT::tryFrom($id);

                if ($term === null) {
                    $violations[] = sprintf('%s maps %s to %s, which is not a business term', $syntax, $element, $id);

                    continue;
                }

                $found = $document->getElementsByTagNameNS('*', $element);

                if ($found->length === 0) {
                    $violations[] = sprintf(
                        '%s maps <%s> to %s and the serialized document carries no such element, so the '.
                        'entry is unverifiable',
                        $syntax,
                        $element,
                        $id,
                    );

                    continue;
                }

                if ($found->length > 1) {
                    $violations[] = sprintf(
                        '%s maps <%s> to %s and the document carries %d of them. An ambiguous element '.
                        'name addresses a failure to whichever term the table happened to pick',
                        $syntax,
                        $element,
                        $id,
                        $found->length,
                    );

                    continue;
                }

                $written = trim((string) $found->item(0)?->textContent);
                $held = $this->valueOf($model, $term);

                if ($held === null) {
                    $violations[] = sprintf('the fixture does not populate %s, so <%s> is unverifiable', $id, $element);

                    continue;
                }

                if (! $this->sameValue($written, $held)) {
                    $violations[] = sprintf(
                        '%s maps <%s> to %s; the element says "%s" and %s is "%s"',
                        $syntax,
                        $element,
                        $id,
                        $written,
                        $id,
                        $held,
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function stages(): array
    {
        $violations = [];
        $configured = array_map('strval', (array) config('jurisdictions.validation.stages', []));

        if ($configured === []) {
            return ['config(\'jurisdictions.validation.stages\') names no stages'];
        }

        if (config('jurisdictions.validation.fetch_at_runtime') !== false) {
            $violations[] = 'config(\'jurisdictions.validation.fetch_at_runtime\') is not false. A '.
                'government updating its Schematron on a Tuesday must not change what PeakFlow accepts '.
                'on Wednesday';
        }

        $registry = app(ProfileRegistry::class);
        $declared = [];

        foreach (['generic', 'peppol', 'zatca'] as $key) {
            if (! $registry->has($key)) {
                continue;
            }

            foreach ($registry->get($key)->validatorChain() as $stage) {
                $declared[$stage] = true;
            }
        }

        foreach (array_keys($declared) as $stage) {
            if (! \in_array($stage, $configured, true)) {
                $violations[] = sprintf(
                    'a profile runs the "%s" stage and the configured list does not name it, so the '.
                    'configuration describes a pipeline that is not the one running',
                    $stage,
                );
            }
        }

        foreach ($configured as $stage) {
            if (isset($declared[$stage])) {
                continue;
            }

            $violations[] = sprintf(
                'the configuration names a "%s" stage and no profile runs it. The pipeline refuses an '.
                'unknown stage, so a profile that believed this list would not validate at all',
                $stage,
            );
        }

        return $violations;
    }

    /*
     |--------------------------------------------------------------------------
     | PF-120: the EAS code list
     |--------------------------------------------------------------------------
     */

    /** @return list<string> */
    private function easIsDerived(): array
    {
        $set = $this->ruleSet('peppol');

        if (preg_match('/name="eaid"\s+value="tokenize\(\'([^\']+)\'/', $set, $found) !== 1) {
            return ['the vendored Peppol rule set carries no $eaid list, so the membership set cannot '.
                'be checked against the rule that uses it'];
        }

        $official = preg_split('/\s+/', trim($found[1])) ?: [];
        sort($official);
        $derived = EasCodes::cl008();

        if ($derived === $official) {
            return [];
        }

        return [sprintf(
            'EasCodes::cl008() yields %d codes and PEPPOL-EN16931-CL008 is evaluated against %d. '.
            'Missing: %s. Extra: %s. The membership set is derived from the published code list as '.
            '"active and registrable" precisely so the two official artefacts check each other - if '.
            'they disagree, one of them has been replaced without the other',
            \count($derived),
            \count($official),
            implode(', ', array_slice(array_diff($official, $derived), 0, 10)) ?: 'none',
            implode(', ', array_slice(array_diff($derived, $official), 0, 10)) ?: 'none',
        )];
    }

    /** @return list<string> */
    private function easIsPublished(): array
    {
        $violations = [];
        $path = EasCodes::path();

        if (! is_file($path)) {
            return [sprintf('the vendored EAS code list is not at %s', $path)];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        $declared = \is_array($raw) ? (int) ($raw['entry-count'] ?? 0) : 0;
        $codes = EasCodes::codes();

        if (\count($codes) !== $declared || $declared === 0) {
            $violations[] = sprintf(
                'the file declares %d entries and %d were read',
                $declared,
                \count($codes),
            );
        }

        if (EasCodes::version() !== '9.7') {
            $violations[] = sprintf(
                'the vendored list is version %s and config names v9.7. A code list version is pinned '.
                'in the file name for exactly this reason',
                EasCodes::version(),
            );
        }

        foreach ($codes as $code) {
            if (preg_match('/^\d{4}$/', $code) !== 1) {
                $violations[] = sprintf('"%s" is not a four-digit EAS code', $code);
            }
        }

        /*
         | And every registrable code is a member, which is the property the
         | strict membership test depends on. PHP casts a numeric array key
         | to an integer, and the first version of this class kept the list
         | keyed by code - so `has('9910')` was false for every code without
         | a leading zero while `has('0002')` worked.
         */
        foreach ($codes as $code) {
            if (EasCodes::state($code) === 'active' && ! EasCodes::has($code) && EasCodes::find($code)['registrable']) {
                $violations[] = sprintf('EAS %s is active and registrable and is not a member of the set', $code);
            }
        }

        /*
         | Three codes read back by name.
         |
         | The point is not that these three are right: it is that the
         | vendored file is the published list and not a set of plausible
         | numbers somebody typed. A hand-made list would have the codes and
         | not the registries.
         */
        foreach (['0002' => 'FR:SIRENE', '0088' => 'GLN', '0151' => 'AU:ABN'] as $code => $scheme) {
            if (EasCodes::scheme((string) $code) !== $scheme) {
                $violations[] = sprintf(
                    'EAS %s is %s in the vendored list and the published list has %s',
                    (string) $code,
                    EasCodes::scheme((string) $code) ?? 'absent',
                    $scheme,
                );
            }
        }

        if (EasCodes::has('9999')) {
            $violations[] = '9999 is four digits and is not a registry, and the membership set accepts it';
        }

        if (! EasCodes::knows('9909') || EasCodes::has('9909')) {
            $violations[] = 'EAS 9909 (NO:VAT) is withdrawn: the list has to know it, for a document '.
                'issued while it was current, and must not let a new document be written under it';
        }

        return $violations;
    }

    /** @return list<string> */
    private function unknownScheme(): array
    {
        return $this->expectPeppol('PEPPOL-EN16931-CL008', static function (SemanticInvoice $model): void {
            $model->root->first(BG::Buyer)?->qualify(BT::BuyerElectronicAddress, '9999');
        });
    }

    /** @return list<string> */
    private function withdrawnScheme(): array
    {
        $result = $this->expectPeppol('PEPPOL-EN16931-CL008', static function (SemanticInvoice $model): void {
            $model->root->first(BG::Buyer)?->qualify(BT::BuyerElectronicAddress, '9909');
        });

        if ($result !== []) {
            return $result;
        }

        $model = $this->peppolReady();
        $model->root->first(BG::Buyer)?->qualify(BT::BuyerElectronicAddress, '9909');

        foreach (app(ValidationPipeline::class)->run($model, app(PeppolProfile::class))->all() as $violation) {
            if ($violation->rule !== 'PEPPOL-EN16931-CL008') {
                continue;
            }

            return str_contains((string) $violation->detail, 'NO:VAT')
                ? []
                : ['the refusal does not name the scheme the code used to be, so the reader cannot tell '.
                    'a withdrawn registry from a typo: '.(string) $violation->detail];
        }

        return ['PEPPOL-EN16931-CL008 did not fire for a withdrawn scheme'];
    }

    /*
     |--------------------------------------------------------------------------
     | PF-123: the gate
     |--------------------------------------------------------------------------
     */

    /**
     * The ZATCA profile is off, and the reason it is off still holds.
     *
     * A check that only asserts `jurisdictions.enabled.zatca === false`
     * cannot tell whether the reason is still true: the day PF-124 lands it
     * would keep failing a deployment that is now correct, and somebody
     * would delete it. So it asserts both halves - the profile declares a
     * cryptographic stamp and an API submission, and the Exchange plane
     * contains nothing that could make either call.
     *
     * @return list<string>
     */
    private function zatcaStaysOff(): array
    {
        $violations = [];
        $registry = app(ProfileRegistry::class);

        if (! $registry->has('zatca')) {
            return ['the ZATCA profile is not registered at all'];
        }

        $profile = $registry->get('zatca');

        if ($registry->isEnabled('zatca')) {
            $violations[] = 'ZATCA is selectable. A standard invoice has to be CLEARED before it is '.
                'delivered, and nothing here can clear one: a tenant would issue, number, post and '.
                'chain a document the authority has never seen, and the buyer would receive a copy '.
                'that is not the legal one';
        }

        if ($profile->sealing() !== 'cryptographic_stamp' || $profile->submission() !== 'clearance_and_reporting') {
            $violations[] = sprintf(
                'the profile now declares sealing "%s" and submission "%s". This check is about a '.
                'profile that needs a stamp and a clearance call it does not have - if the '.
                'declarations have changed, the gate it keeps has changed too',
                $profile->sealing(),
                $profile->submission(),
            );
        }

        /*
         | And the reason, measured rather than recalled.
         |
         | Clearance is an HTTP call to the authority and the stamp needs a
         | key from a KMS. The Exchange plane holds neither - no client, no
         | key handling, nothing that opens a socket - so the declarations
         | above have no implementation whatever the configuration says.
         */
        $callers = [];

        foreach ($this->sourcesIn('app/Exchange') as $file) {
            $source = (string) file_get_contents($file);

            foreach (['Facades\\Http', 'GuzzleHttp', 'curl_init', 'KmsClient', 'openssl_sign'] as $needle) {
                if (str_contains($source, $needle)) {
                    $callers[] = sprintf('%s uses %s', $this->relative($file), $needle);
                }
            }
        }

        if ($callers !== []) {
            $violations[] = sprintf(
                'the Exchange plane can now sign or call out (%s), so PF-124 may have landed. This '.
                'check keeps the profile off BECAUSE it cannot clear a document; re-read PF-123 and '.
                'PF-124 and decide whether the gate still belongs here',
                implode('; ', $callers),
            );
        }

        return $violations;
    }

    /*
     |--------------------------------------------------------------------------
     | PF-129: the kinds the archive holds
     |--------------------------------------------------------------------------
     */

    /** @return list<string> */
    private function archiveRegister(): array
    {
        $violations = [];
        $register = Archive::AWAITING_A_CALL_SITE;
        $written = [];

        foreach ($this->sourcesIn('app') as $file) {
            if (str_starts_with($this->relative($file), 'app/Exchange/Archive/')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'Archive')) {
                continue;
            }

            foreach (Archive::KINDS as $kind) {
                if (preg_match('/kind:\s*\''.preg_quote($kind, '/').'\'/', $source) === 1) {
                    $written[$kind] = $this->relative($file);
                }
            }
        }

        foreach (Archive::KINDS as $kind) {
            $hasCallSite = isset($written[$kind]);
            $isRegistered = isset($register[$kind]);

            if ($hasCallSite && $isRegistered) {
                $violations[] = sprintf(
                    '%s is archived by %s and is still listed in Archive::AWAITING_A_CALL_SITE. Delete '.
                    'the line: a register that lists what has been built is read as a gap that is not '.
                    'there',
                    $kind,
                    $written[$kind],
                );
            }

            if (! $hasCallSite && ! $isRegistered) {
                $violations[] = sprintf(
                    '%s is an archive kind with retention, a WORM trigger and a manifest, and nothing '.
                    'in app/ ever writes one. That reads as finished work. Name the task that will '.
                    'write it in Archive::AWAITING_A_CALL_SITE',
                    $kind,
                );
            }

            if ($isRegistered && ! preg_match('/PF-\d{3}/', (string) $register[$kind])) {
                $violations[] = sprintf('%s is registered as awaiting a call site and names no task', $kind);
            }
        }

        foreach (array_keys($register) as $kind) {
            if (! \in_array((string) $kind, Archive::KINDS, true)) {
                $violations[] = sprintf(
                    '%s is awaiting a call site and is not a kind the archive holds',
                    (string) $kind,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function everyKindRoundTrips(): array
    {
        $violations = [];
        $archive = app(Archive::class);
        $profile = app(GenericProfile::class);
        $invoice = $this->issue();

        foreach (Archive::KINDS as $kind) {
            $bytes = sprintf('<%s>%s</%s>', $kind, (string) $invoice->number, $kind);

            try {
                $row = $archive->store(
                    $this->entityId,
                    'invoice',
                    (string) $invoice->getKey(),
                    $kind,
                    $bytes,
                    $profile,
                    'application/xml',
                );
            } catch (\Throwable $e) {
                $violations[] = sprintf('%s could not be archived: %s', $kind, $e->getMessage());

                continue;
            }

            $back = $archive->read($this->entityId, 'invoice', (string) $invoice->getKey(), $kind);

            if ($back !== $bytes) {
                $violations[] = sprintf('%s did not come back as the bytes that went in', $kind);
            }

            if ((string) ($row['content_hash'] ?? '') !== hash('sha256', $bytes)) {
                $violations[] = sprintf('%s stored a hash that is not the hash of its bytes', $kind);
            }

            $retainUntil = (string) ($row['retain_until'] ?? '');

            if (! str_starts_with($retainUntil, (string) (2026 + $profile->retentionYears()))) {
                $violations[] = sprintf(
                    '%s is retained until %s and the generic profile declares %d years from 2026',
                    $kind,
                    $retainUntil,
                    $profile->retentionYears(),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function kindsAgree(): array
    {
        $definition = DB::connection('tenant')->selectOne(
            'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
            ['archived_artefacts_kind_is_known'],
        );

        if ($definition === null) {
            return ['the archived_artefacts table has no kind constraint, so the application\'s list is '.
                'the only defence and a direct insert can put any string in the archive'];
        }

        preg_match_all("/'([a-z_]+)'/", (string) $definition->def, $found);
        $inDatabase = $found[1] ?? [];
        sort($inDatabase);

        $inCode = Archive::KINDS;
        sort($inCode);

        if ($inCode === $inDatabase) {
            return [];
        }

        return [sprintf(
            'Archive::KINDS is [%s] and the database CHECK allows [%s]. Two declarations of one list: '.
            'a kind in the code and not in the database fails at the moment of archiving with a '.
            'constraint error, and a kind in the database and not in the code is a row the archive '.
            'will not read back',
            implode(', ', $inCode),
            implode(', ', $inDatabase),
        )];
    }

    /*
     |--------------------------------------------------------------------------
     | Helpers
     |--------------------------------------------------------------------------
     */

    /**
     * Tamper the model, run the generic chain, require the named rule.
     *
     * @param  callable(SemanticInvoice): void  $break
     * @param  list<string>  $alsoAllow
     * @return list<string>
     */
    private function expect(string $rule, callable $break, array $alsoAllow = []): array
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(GenericProfile::class);
        $pipeline = app(ValidationPipeline::class);

        if (! $pipeline->run($model, $profile)->passed()) {
            return ['the untampered document does not pass, so this check proves nothing'];
        }

        $break($model);

        $rules = array_values(array_unique(array_map(
            static fn (SemanticViolation $v): string => $v->rule,
            $pipeline->run($model, $profile)->all(),
        )));

        if (! \in_array($rule, $rules, true)) {
            return [sprintf(
                '%s did not fire. Reported instead: %s',
                $rule,
                $rules === [] ? 'nothing at all' : implode(', ', $rules),
            )];
        }

        $unexpected = array_diff($rules, [$rule], $alsoAllow);

        return $unexpected === []
            ? []
            : [sprintf('%s fired, and so did %s', $rule, implode(', ', $unexpected))];
    }

    /**
     * The same, through the Peppol chain.
     *
     * @param  callable(SemanticInvoice): void  $break
     * @return list<string>
     */
    private function expectPeppol(string $rule, callable $break): array
    {
        $model = $this->peppolReady();
        $profile = app(PeppolProfile::class);
        $pipeline = app(ValidationPipeline::class);

        if (! $pipeline->run($model, $profile)->passed()) {
            return ['the untampered Peppol document does not pass, so this check proves nothing'];
        }

        $break($model);

        $rules = array_values(array_unique(array_map(
            static fn (SemanticViolation $v): string => $v->rule,
            $pipeline->run($model, $profile)->all(),
        )));

        return \in_array($rule, $rules, true)
            ? []
            : [sprintf(
                '%s did not fire. Reported instead: %s',
                $rule,
                $rules === [] ? 'nothing at all' : implode(', ', $rules),
            )];
    }

    private function peppolReady(): SemanticInvoice
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(PeppolProfile::class);

        $model->root->first(BG::ProcessControl)
            ?->set(BT::BusinessProcessType, $profile->businessProcess())
            ->set(BT::SpecificationIdentifier, $profile->specificationIdentifier());

        $model->root->set(BT::BuyerReference, 'PO-2026-0041');

        return $model;
    }

    /**
     * A model carrying every term the element-to-term table maps.
     *
     * Several of them - the accounting currency, the business process, a
     * rounding amount - are absent from a plain document, and an entry that
     * is never emitted is an entry nothing verifies.
     */
    private function fullyPopulated(): SemanticInvoice
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());

        $model->root->set(BT::BuyerReference, 'PO-2026-0041')
            ->set(BT::PaymentDueDate, '2026-06-17')
            ->set(BT::TaxPointDate, '2026-05-18')
            ->set(BT::VatAccountingCurrencyCode, 'EUR');

        $model->root->first(BG::ProcessControl)
            ?->set(BT::BusinessProcessType, 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');

        $model->root->first(BG::DocumentTotals)
            ?->set(BT::SumOfAllowances, '0.00')
            ->set(BT::SumOfCharges, '0.00')
            ->set(BT::PaidAmount, '0.00')
            ->set(BT::RoundingAmount, '0.00');

        return $model;
    }

    private function valueOf(SemanticInvoice $model, BT $term): ?string
    {
        foreach ([$model->root, $model->root->first(BG::ProcessControl), $model->root->first(BG::DocumentTotals)] as $node) {
            $value = $node?->get($term);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function sameValue(string $written, string $held): bool
    {
        try {
            return BigDecimal::of($written)->isEqualTo(BigDecimal::of($held));
        } catch (\Throwable) {
            return $written === $held;
        }
    }

    /**
     * Run a closure with a fixture schema at the path the pipeline looks at.
     *
     * The files go exactly where production would look, because a check
     * that pointed the pipeline somewhere else would be checking a
     * different pipeline. They are removed in a `finally`, and the removal
     * is itself asserted: a leftover `maindoc.xsd` would silently change
     * what every other suite in this plane validates against.
     *
     * @param  array<string, string>  $files
     * @param  callable(): list<string>  $run
     * @return list<string>
     */
    private function withSchema(array $files, callable $run): array
    {
        $directory = base_path(self::SCHEMA_DIRECTORY);
        $root = \dirname($directory);
        $rootExisted = is_dir($root);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return [sprintf('%s could not be created, so stage 2 could not be turned on', $directory)];
        }

        try {
            foreach ($files as $name => $contents) {
                file_put_contents($directory.'/'.$name, $contents);
            }

            $violations = $run();
        } finally {
            foreach (array_keys($files) as $name) {
                @unlink($directory.'/'.$name);
            }

            @rmdir($directory);

            if (! $rootExisted) {
                @rmdir($root);
            }
        }

        if (is_file($directory.'/maindoc.xsd')) {
            $violations[] = 'the fixture schema is still on disk, so every other check in this plane is '.
                'now validating against it';
        }

        return $violations;
    }

    /**
     * A schema that accepts a UBL invoice except for one element's type.
     *
     * `processContents="lax"` is what makes it usable: an element with no
     * declaration in the schema set is skipped, and `cbc:TaxInclusiveAmount`
     * - declared in the imported file - is not. So one element is checked
     * and the other four hundred are waved through, which is what a fixture
     * schema should do and is the opposite of what UBL 2.1 does.
     *
     * The import is a RELATIVE path inside `resources/schemas`, so resolving
     * it at all is the other half of what these checks show: the confined
     * entity loader has to let a vendored neighbour through.
     *
     * @return array<string, string>
     */
    private function laxSchema(string $type): array
    {
        return [
            'cbc.xsd' => <<<XSD
                <?xml version="1.0" encoding="UTF-8"?>
                <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                           targetNamespace="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
                           elementFormDefault="qualified">
                  <xs:element name="TaxInclusiveAmount">
                    <xs:complexType>
                      <xs:simpleContent>
                        <xs:extension base="{$type}">
                          <xs:anyAttribute processContents="skip"/>
                        </xs:extension>
                      </xs:simpleContent>
                    </xs:complexType>
                  </xs:element>
                </xs:schema>
                XSD,
            'maindoc.xsd' => <<<'XSD'
                <?xml version="1.0" encoding="UTF-8"?>
                <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                           targetNamespace="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                           elementFormDefault="qualified">
                  <xs:import namespace="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
                             schemaLocation="cbc.xsd"/>
                  <xs:element name="Invoice">
                    <xs:complexType>
                      <xs:sequence>
                        <xs:any namespace="##other" processContents="lax" minOccurs="0" maxOccurs="unbounded"/>
                      </xs:sequence>
                      <xs:anyAttribute processContents="skip"/>
                    </xs:complexType>
                  </xs:element>
                </xs:schema>
                XSD,
        ];
    }

    /**
     * The same, importing from somewhere that is not a vendored neighbour.
     *
     * @return array<string, string>
     */
    private function importingSchema(string $location): array
    {
        return [
            'maindoc.xsd' => <<<XSD
                <?xml version="1.0" encoding="UTF-8"?>
                <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                           targetNamespace="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                           elementFormDefault="qualified">
                  <xs:import namespace="urn:example:stolen" schemaLocation="{$location}"/>
                  <xs:element name="Invoice">
                    <xs:complexType>
                      <xs:sequence>
                        <xs:any namespace="##other" processContents="lax" minOccurs="0" maxOccurs="unbounded"/>
                      </xs:sequence>
                      <xs:anyAttribute processContents="skip"/>
                    </xs:complexType>
                  </xs:element>
                </xs:schema>
                XSD,
        ];
    }

    private function artefactPath(string $name): string
    {
        return sprintf('%s/%s', (string) config('jurisdictions.validation.artefact_path'), $name);
    }

    private function ruleSet(string $key): string
    {
        $name = (string) config('jurisdictions.validation.rulesets.'.$key, '');

        if ($name === '') {
            return '';
        }

        $path = $this->artefactPath($name);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return list<string> */
    private function identifiersIn(string $schematron): array
    {
        preg_match_all('/id="([A-Z][A-Za-z0-9-]*)"/', $schematron, $found);

        $ids = array_values(array_unique($found[1] ?? []));
        sort($ids);

        return $ids;
    }

    /**
     * Every rule identifier the Exchange sources can report.
     *
     * The same expression `ExchangeRules` uses for the explanation table, so
     * the two checks cannot disagree about what "the code can emit" means.
     *
     * @return list<string>
     */
    private function emitted(): array
    {
        $found = [];

        foreach ($this->sourcesIn('app/Exchange') as $file) {
            preg_match_all(
                "/'((?:BR|EN16931|PEPPOL|ZATCA)-[A-Z0-9-]+)'/",
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] ?? [] as $rule) {
                $found[$rule] = true;
            }
        }

        $rules = array_keys($found);
        sort($rules);

        return $rules;
    }

    /** @return list<string> */
    private function sourcesIn(string $directory): array
    {
        $files = [];
        $base = base_path($directory);

        if (! is_dir($base)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
