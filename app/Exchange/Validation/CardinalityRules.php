<?php

declare(strict_types=1);

namespace App\Exchange\Validation;

use App\Exchange\Semantics\BusinessGroup;
use App\Exchange\Semantics\BusinessTerm;
use App\Exchange\Semantics\SemanticNode;

/**
 * Cardinality and conditionality, enforced once - PF-110.
 *
 * ## Why here and not in each serializer
 *
 * A term that is mandatory in the UBL serializer and forgotten in the CII
 * one is a document that validates in one syntax and not the other. The
 * recipient sees a rejection; the supplier sees a document that looked fine;
 * the engineer looks in the wrong serializer because the last failure was in
 * the other one. One walk over the model, and both syntaxes inherit it.
 *
 * ## The messages name the rule a recipient's validator would name
 *
 * EN 16931 gives most mandatory terms their own `BR-*` rule - a missing
 * seller country code is `BR-09`, not "a required field is empty". Reporting
 * our own vocabulary would mean the supplier reads one message from us and a
 * different one from the recipient's access point about the same defect, and
 * has to work out they are the same thing.
 *
 * Where a mandatory term has no dedicated rule number, the message says so
 * rather than inventing one.
 */
final class CardinalityRules
{
    /**
     * The EN 16931 rule that fires when a mandatory term is missing.
     *
     * Hand-written from EN 16931-1 rather than derived, because the standard
     * does not number these systematically: `BT-40` is `BR-09` and `BT-55`
     * is `BR-11`, and there is no formula between them.
     */
    private const TERM_RULES = [
        'BT-24' => 'BR-01',
        'BT-1' => 'BR-02',
        'BT-2' => 'BR-03',
        'BT-3' => 'BR-04',
        'BT-5' => 'BR-05',
        'BT-27' => 'BR-06',
        'BT-44' => 'BR-07',
        'BT-40' => 'BR-09',
        'BT-55' => 'BR-11',
        'BT-106' => 'BR-12',
        'BT-109' => 'BR-13',
        'BT-112' => 'BR-14',
        'BT-115' => 'BR-15',
        'BT-59' => 'BR-17',
        'BT-62' => 'BR-18',
        'BT-69' => 'BR-20',
        'BT-126' => 'BR-21',
        'BT-129' => 'BR-22',
        'BT-130' => 'BR-23',
        'BT-131' => 'BR-24',
        'BT-153' => 'BR-25',
        'BT-146' => 'BR-26',
        'BT-92' => 'BR-31',
        'BT-95' => 'BR-32',
        'BT-99' => 'BR-36',
        'BT-102' => 'BR-37',
        'BT-136' => 'BR-41',
        'BT-141' => 'BR-43',
        'BT-116' => 'BR-45',
        'BT-117' => 'BR-46',
        'BT-118' => 'BR-47',
        'BT-81' => 'BR-49',
        'BT-84' => 'BR-50',
        'BT-87' => 'BR-51',
        'BT-122' => 'BR-52',
        'BT-160' => 'BR-54',
        'BT-161' => 'BR-54',
        'BT-25' => 'BR-55',
        'BT-63' => 'BR-56',
        'BT-80' => 'BR-57',
    ];

    /** The rule that fires when a mandatory GROUP has no instance. */
    private const GROUP_RULES = [
        'BG-5' => 'BR-08',
        'BG-8' => 'BR-10',
        'BG-25' => 'BR-16',
        'BG-12' => 'BR-19',
        'BG-23' => 'BR-CO-18',
    ];

    /** @return list<SemanticViolation> */
    public function check(SemanticNode $root): array
    {
        return $this->walk($root, 'document');
    }

    /** @return list<SemanticViolation> */
    private function walk(SemanticNode $node, string $path): array
    {
        $violations = [];

        foreach ($this->termsOf($node->group) as $term) {
            $values = $node->all($term);

            if ($term->cardinality()->isMandatory() && $values === []) {
                $violations[] = SemanticViolation::fatal(
                    self::TERM_RULES[$term->value] ?? 'EN16931-CARDINALITY',
                    term: $term,
                    group: $node->group,
                    detail: sprintf(
                        '%s (%s) is %s in %s and is not populated.',
                        $term->value,
                        $term->label(),
                        $term->cardinality()->value,
                        $node->group?->value ?? 'the document',
                    ),
                )->withPath($path);
            }

            if (! $term->cardinality()->isRepeatable() && \count($values) > 1) {
                $violations[] = SemanticViolation::internal(
                    'EN16931-CARDINALITY',
                    term: $term,
                    detail: sprintf(
                        '%s is %s and was populated %d times.',
                        $term->value,
                        $term->cardinality()->value,
                        \count($values),
                    ),
                )->withPath($path);
            }
        }

        foreach (BusinessGroup::childrenOf($node->group) as $group) {
            $instances = $node->children($group);
            $count = \count($instances);
            $cardinality = $group->cardinality();

            if ($cardinality->isMandatory() && $count === 0) {
                $violations[] = SemanticViolation::fatal(
                    self::GROUP_RULES[$group->value] ?? 'EN16931-CARDINALITY',
                    group: $group,
                    detail: sprintf(
                        '%s (%s) is %s in %s and has no instance.',
                        $group->value,
                        $group->label(),
                        $cardinality->value,
                        $node->group?->value ?? 'the document',
                    ),
                )->withPath($path);
            }

            if (! $cardinality->isRepeatable() && $count > 1) {
                $violations[] = SemanticViolation::internal(
                    'EN16931-CARDINALITY',
                    detail: sprintf('%s is %s and has %d instances.', $group->value, $cardinality->value, $count),
                )->withPath($path);
            }

            foreach ($instances as $index => $instance) {
                $violations = [
                    ...$violations,
                    ...$this->walk($instance, sprintf('%s / %s[%d]', $path, $group->value, $index + 1)),
                ];
            }
        }

        return $violations;
    }

    /** @return list<BusinessTerm> */
    private function termsOf(?BusinessGroup $group): array
    {
        return array_values(array_filter(
            BusinessTerm::cases(),
            static fn (BusinessTerm $term): bool => $term->group() === $group,
        ));
    }
}
