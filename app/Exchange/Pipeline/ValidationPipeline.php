<?php

declare(strict_types=1);

namespace App\Exchange\Pipeline;

use App\Exchange\Profiles\JurisdictionProfile;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Validation\CalculationRules;
use App\Exchange\Validation\CardinalityRules;
use App\Exchange\Validation\ConditionalRules;
use App\Exchange\Validation\SemanticViolation;
use App\Support\Xml\MalformedXml;
use App\Support\Xml\SafeXmlReader;

/**
 * The ordered validation stages - PF-114, PF-116, PF-117, PF-118.
 *
 * ## Four stages, and the order is the design
 *
 * | Stage | What it is | Who fixes a failure |
 * |-------|-----------|---------------------|
 * | 1 `model` | Cardinality and conditionality, before any XML exists | Whoever filled the document in |
 * | 2 `schema` | XSD, against the vendored UBL and CII schemas | Whoever wrote the serializer |
 * | 3 `en16931` | The `BR-*` and `BR-CO-*` rules | `Tax`, usually - a calculation rule here is our bug |
 * | 4 `cius` | The jurisdiction's own restrictions | The supplier, or the profile |
 *
 * It **stops at the first stage that fails**, and not to save time: running
 * a rule set over a document that does not parse produces messages about the
 * wrong thing, and asking a supplier to fix a CIUS restriction on a document
 * whose totals do not add up wastes their afternoon on the second-largest
 * problem.
 *
 * ## Stage 2 reports that it could not run
 *
 * The UBL 2.1 and CII schemas are large vendored artefacts, and this
 * environment cannot fetch them. The stage therefore reports itself as
 * **skipped, with the reason**, rather than silently passing. A pipeline
 * whose schema stage quietly does nothing is a pipeline that says a document
 * is valid when nobody has checked its syntax - and the first person to find
 * out is the recipient.
 *
 * What the stage does do today is parse the bytes through
 * {@see SafeXmlReader}, which is a real check: a serializer that produced
 * malformed XML, or a document carrying a DOCTYPE, fails here.
 */
final class ValidationPipeline
{
    public function __construct(
        private readonly CardinalityRules $cardinality,
        private readonly ConditionalRules $conditional,
        private readonly CalculationRules $calculation,
    ) {}

    /**
     * Run the profile's chain over a document.
     *
     * The serialized bytes are produced only when a stage needs them, so a
     * document that fails stage 1 is never serialized - which is the point
     * of having stage 1.
     */
    public function run(SemanticInvoice $invoice, JurisdictionProfile $profile): ValidationReport
    {
        $byStage = [];
        $ran = [];
        $skipped = [];
        $xml = null;

        foreach ($profile->validatorChain() as $stage) {
            $ran[] = $stage;

            $result = match ($stage) {
                'model' => ['violations' => $this->model($invoice)],
                'schema' => $this->schema($invoice, $profile, $xml),
                'en16931' => ['violations' => $this->en16931($invoice)],
                'cius' => ['violations' => $profile->check($invoice)],
                default => throw new \LogicException(sprintf(
                    'The %s profile names a validation stage "%s" that does not exist. A chain with a '.
                    'stage nobody implements is a chain with a hole in it.',
                    $profile->key(),
                    $stage,
                )),
            };

            if (($result['skipped'] ?? null) !== null) {
                $skipped[$stage] = (string) $result['skipped'];
            }

            if (($result['xml'] ?? null) !== null) {
                $xml = (string) $result['xml'];
            }

            $violations = $result['violations'] ?? [];
            $byStage[$stage] = $violations;

            if ($violations !== []) {
                // Stop here: a later stage over a document this one rejected
                // reports the wrong problem.
                break;
            }
        }

        /*
         | Stage 4 runs even when the chain does not name it.
         |
         | A profile whose `validatorChain()` omits `cius` while its
         | `check()` holds rules would silently not enforce them, which is
         | the quietest possible compliance failure. So the omission is made
         | visible: the stage runs, and if it finds nothing the omission was
         | honest.
         */
        if (! \in_array('cius', $profile->validatorChain(), true) && $byStage !== [] && $this->allPassed($byStage)) {
            $cius = $profile->check($invoice);

            if ($cius !== []) {
                $byStage['cius'] = $cius;
                $ran[] = 'cius';
            }
        }

        return new ValidationReport($profile->key(), $byStage, $ran, $skipped);
    }

    /**
     * Stage 1: the document's own shape.
     *
     * @return list<SemanticViolation>
     */
    private function model(SemanticInvoice $invoice): array
    {
        return array_map(
            static fn (SemanticViolation $v): SemanticViolation => $v,
            $this->cardinality->check($invoice->root),
        );
    }

    /**
     * Stage 2: the syntax.
     *
     * @return array{violations: list<SemanticViolation>, skipped?: string, xml?: string}
     */
    private function schema(SemanticInvoice $invoice, JurisdictionProfile $profile, ?string $xml): array
    {
        $serializer = $profile->serializer();

        try {
            $xml ??= $serializer->serialize($invoice);
        } catch (\Throwable $e) {
            return ['violations' => [SemanticViolation::internal(
                'EN16931-SERIALIZATION',
                detail: sprintf('The %s serializer failed: %s', $serializer->syntax(), $e->getMessage()),
            )]];
        }

        try {
            SafeXmlReader::parse($xml);
        } catch (MalformedXml $e) {
            return ['violations' => [SemanticViolation::internal(
                'EN16931-WELLFORMED',
                detail: $e->getMessage(),
            )], 'xml' => $xml];
        }

        $schema = $this->schemaFor($serializer->syntax());

        if ($schema === null) {
            return [
                'violations' => [],
                'xml' => $xml,
                'skipped' => sprintf(
                    'the %s schemas are not vendored, so the document was parsed but not schema '.
                    'validated - PF-114 vendors them',
                    $serializer->syntax(),
                ),
            ];
        }

        return ['violations' => $this->validateAgainst($xml, $schema), 'xml' => $xml];
    }

    /**
     * Stage 3: EN 16931.
     *
     * @return list<SemanticViolation>
     */
    private function en16931(SemanticInvoice $invoice): array
    {
        return [
            ...$this->conditional->check($invoice->root),
            ...$this->calculation->check($invoice->root),
        ];
    }

    /**
     * The vendored schema for a syntax, or null when it is not there.
     *
     * Looked up on disk rather than assumed, so vendoring them is the only
     * thing needed to turn the stage on - no code change, no flag, and no
     * possibility of the schemas being present and unused.
     */
    private function schemaFor(string $syntax): ?string
    {
        $path = base_path(sprintf('resources/schemas/%s/maindoc.xsd', $syntax));

        return is_file($path) ? $path : null;
    }

    /**
     * Validate against an XSD, with the schema's own doors closed too.
     *
     * ## `schemaValidate()` is a second parser, and it was unguarded
     *
     * {@see SafeXmlReader} hardens the reading of the DOCUMENT. It cannot
     * harden the reading of the SCHEMA, because libxml loads that itself:
     * `schemaValidate()` opens the `.xsd`, follows every `xs:import` and
     * `xs:include` in it, and resolves each one through whatever external
     * entity loader happens to be installed - which, outside the reader's
     * `try`, is libxml's default.
     *
     * UBL 2.1 and CII D16B are exactly the schemas that do this: `maindoc.xsd`
     * imports a dozen common schema modules, and the official distributions
     * carry absolute `http://docs.oasis-open.org/...` locations in some of
     * them. So the first thing vendoring the schemas would have done is turn
     * every validated invoice into an outbound request to a third party, on a
     * queue worker, with the document's own content deciding nothing and the
     * schema deciding everything. A network hiccup at OASIS would have become
     * "invoices stopped validating".
     *
     * ## The loader allows the schema directory and nothing else
     *
     * Every resolution is a path under `resources/schemas`, canonicalized
     * with `realpath()` so `../../.env` is not a path under it, and every
     * refusal is RECORDED and reported as a violation. Returning null
     * silently would make a schema whose imports did not load look like a
     * document that passed validation, which is the one outcome worse than
     * the request.
     *
     * @return list<SemanticViolation>
     */
    private function validateAgainst(string $xml, string $schema): array
    {
        $document = SafeXmlReader::parse($xml);

        $root = realpath(base_path('resources/schemas'));
        $refused = [];

        /*
         | PHP 8.4 returns the callable it replaced; 8.3 and earlier return
         | `true`, and restoring that `true` is a TypeError. So what comes
         | back is only restored when it is actually callable - the same
         | reason SafeXmlReader does it this way.
         */
        $previousLoader = libxml_set_external_entity_loader(
            static function (?string $publicId, string $systemId) use ($root, &$refused) {
                $resolved = realpath($systemId);

                if ($root !== false && $resolved !== false && str_starts_with($resolved, $root.\DIRECTORY_SEPARATOR)) {
                    return fopen($resolved, 'rb') ?: null;
                }

                $refused[] = $systemId;

                return null;
            },
        );

        $previous = libxml_use_internal_errors(true);
        $thrown = null;

        try {
            /*
             | Suppressed, and then reported - never swallowed.
             |
             | `libxml_use_internal_errors(true)` collects the parser's
             | errors and does NOT suppress the PHP-level warning
             | `schemaValidate()` raises when the schema itself fails to
             | COMPILE - which is exactly what happens when an import is
             | refused. Laravel's error handler turns that warning into an
             | ErrorException, so without the `@` a refused reference throws
             | out of this method instead of becoming the violation below,
             | and the caller sees a 500 rather than a report naming the
             | reference.
             |
             | Measured while proving the refusal bites: the probe schema
             | importing `http://127.0.0.1:1/stolen.xsd` took the whole
             | check down with `ErrorException: DOMDocument::
             | schemaValidate(): Invalid Schema`.
             |
             | The `@` therefore hides a warning whose information is
             | recovered twice over: as the refused references above, and as
             | the schema-compilation violation below. Nothing is silent.
             */
            $valid = @$document->schemaValidate($schema);
            $errors = libxml_get_errors();
        } catch (\Throwable $e) {
            $valid = false;
            $errors = libxml_get_errors();
            $thrown = $e->getMessage();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            libxml_set_external_entity_loader(\is_callable($previousLoader) ? $previousLoader : null);
        }

        $violations = [];

        if ($thrown !== null) {
            $violations[] = SemanticViolation::internal(
                'EN16931-SCHEMA-REFERENCE',
                detail: sprintf('The schema could not be compiled: %s', $thrown),
            );
        }

        foreach (array_unique($refused) as $systemId) {
            $violations[] = SemanticViolation::internal(
                'EN16931-SCHEMA-REFERENCE',
                detail: sprintf(
                    'The schema asked for "%s", which is not a file under resources/schemas, so it was '.
                    'refused. A validator that fetches part of its schema from a third party has made '.
                    'every invoice depend on that party being up, and an attacker who answers for it '.
                    'decides what counts as valid. Vendor the reference next to the schema that needs '.
                    'it.',
                    $systemId,
                ),
            );
        }

        if ($valid && $violations === []) {
            return [];
        }

        return [
            ...$violations,
            ...array_map(
                static fn (\LibXMLError $error): SemanticViolation => SemanticViolation::internal(
                    'EN16931-SCHEMA',
                    detail: sprintf('line %d: %s', $error->line, trim($error->message)),
                ),
                $errors,
            ),
        ];
    }

    /** @param array<string, list<SemanticViolation>> $byStage */
    private function allPassed(array $byStage): bool
    {
        foreach ($byStage as $violations) {
            if ($violations !== []) {
                return false;
            }
        }

        return true;
    }
}
