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
     * @return list<SemanticViolation>
     */
    private function validateAgainst(string $xml, string $schema): array
    {
        $document = SafeXmlReader::parse($xml);

        $previous = libxml_use_internal_errors(true);
        $valid = $document->schemaValidate($schema);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($valid) {
            return [];
        }

        return array_map(
            static fn (\LibXMLError $error): SemanticViolation => SemanticViolation::internal(
                'EN16931-SCHEMA',
                detail: sprintf('line %d: %s', $error->line, trim($error->message)),
            ),
            $errors,
        );
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
