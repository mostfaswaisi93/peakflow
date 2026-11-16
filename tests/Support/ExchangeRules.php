<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\Cardinality;
use App\Exchange\Semantics\SemanticNode;
use App\Exchange\Validation\CardinalityRules;

/**
 * One semantic model, and a mapping document that agrees with it - PF-110, PF-111.
 *
 * ## What these checks are defending
 *
 * The phase exists to avoid building for one country's XML. That only works
 * if the model is genuinely syntax-independent and genuinely complete: a
 * term the UBL serializer reaches for and the model does not hold is a term
 * the CII serializer will invent differently, and then there are two
 * answers to "what did we send".
 *
 * So: the registry holds every EN 16931 term and no invented one, the
 * mapping document and the registry agree in both directions, the group
 * tree is a tree, and the model refuses a term on the wrong group loudly
 * rather than emitting it in the wrong place.
 */
final class ExchangeRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'the registry holds every EN 16931 term and no invented one' => $this->theRegistryIsComplete(),
            'the group tree is a tree' => $this->theGroupTreeIsATree(),
            'every term belongs to a group that exists' => $this->everyTermHasAHome(),
            'the mapping document and the registry agree' => $this->theMappingAgrees(),
            'a term set on the wrong group is refused' => $this->aTermOnTheWrongGroupIsRefused(),
            'a non-repeatable term cannot be repeated' => $this->repetitionIsRefused(),
            'a group nested in the wrong parent is refused' => $this->badNestingIsRefused(),
            'cardinality reports the rule a recipient would quote' => $this->cardinalityNamesTheRule(),
            'every rule the code can emit has a bilingual explanation' => $this->everyRuleIsExplained(),
        ];
    }

    /**
     * @return list<string>
     */
    private function theRegistryIsComplete(): array
    {
        $violations = [];
        $ids = array_map(static fn (BT $t): int => (int) substr($t->value, 3), BT::cases());
        sort($ids);

        /*
         | EN 16931 numbers its terms BT-1 to BT-161 with real gaps.
         |
         | There is no BT-4. A registry that invented one to close the
         | sequence would be a registry nobody could check against the
         | standard, and the first person to look up BT-4 would find a term
         | we made up.
         */
        $knownGaps = [4];

        foreach (range(1, 161) as $number) {
            $present = \in_array($number, $ids, true);
            $expected = ! \in_array($number, $knownGaps, true);

            if ($present && ! $expected) {
                $violations[] = sprintf('BT-%d is in the registry and EN 16931 does not define it', $number);
            }

            if (! $present && $expected) {
                $violations[] = sprintf('BT-%d is missing from the registry', $number);
            }
        }

        if (\count(BG::cases()) !== 32) {
            $violations[] = sprintf(
                'the registry has %d business groups and EN 16931 defines BG-1 to BG-32',
                \count(BG::cases()),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function theGroupTreeIsATree(): array
    {
        $violations = [];

        foreach (BG::cases() as $group) {
            $seen = [$group->value];
            $cursor = $group->parent();

            while ($cursor !== null) {
                if (\in_array($cursor->value, $seen, true)) {
                    $violations[] = sprintf(
                        '%s is its own ancestor, so the cardinality walk would not terminate',
                        $group->value,
                    );

                    break;
                }

                $seen[] = $cursor->value;
                $cursor = $cursor->parent();
            }
        }

        // And every group is reachable from the root, or the walk never
        // visits it and its mandatory terms are never checked.
        $reachable = [];
        $this->reach(null, $reachable);

        foreach (BG::cases() as $group) {
            if (! \in_array($group->value, $reachable, true)) {
                $violations[] = sprintf(
                    '%s is not reachable from the document root, so nothing it contains is ever validated',
                    $group->value,
                );
            }
        }

        return $violations;
    }

    private function reach(?BG $parent, array &$found): void
    {
        foreach (BG::childrenOf($parent) as $child) {
            if (\in_array($child->value, $found, true)) {
                continue;
            }

            $found[] = $child->value;
            $this->reach($child, $found);
        }
    }

    /** @return list<string> */
    private function everyTermHasAHome(): array
    {
        $violations = [];
        $rootTerms = 0;

        foreach (BT::cases() as $term) {
            if ($term->group() === null) {
                $rootTerms++;
            }
        }

        if ($rootTerms === 0) {
            $violations[] = 'no term sits at the document root, and BT-1 does';
        }

        /*
         | Every group holds at least one term.
         |
         | A group with none is a container the serializers would emit empty,
         | which a schema rejects - and it means a term was mapped to the
         | wrong group, because EN 16931 defines no empty ones.
         */
        foreach (BG::cases() as $group) {
            if ($group->terms() === [] && BG::childrenOf($group) === []) {
                $violations[] = sprintf('%s (%s) holds no terms and no groups', $group->value, $group->label());
            }
        }

        return $violations;
    }

    /**
     * The mapping document and the registry, in both directions - PF-111.
     *
     * @return list<string>
     */
    private function theMappingAgrees(): array
    {
        $path = base_path('docs/standards/en16931-mapping.md');

        if (! is_file($path)) {
            return ['docs/standards/en16931-mapping.md is missing, and the mapping is part of the deliverable'];
        }

        $violations = [];
        $documented = [];
        $cardinalities = [];

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (! str_starts_with(trim($line), '| `BT-')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, "| \t")));
            preg_match_all('/BT-(\d+)/', $cells[0] ?? '', $matches);
            $numbers = array_map('intval', $matches[1] ?? []);

            if ($numbers === []) {
                continue;
            }

            /*
             | Only the five-column mapping tables carry a cardinality.
             |
             | The deliberate-omissions table at the foot of the document has
             | two columns - the term and the reason - and a term listed
             | there IS documented: an omission with a reason is a decision.
             | Reading its second cell as a cardinality was this check's own
             | first bug, and it reported "the document says ''".
             */
            if (\count($cells) < 4) {
                foreach ($numbers as $number) {
                    $documented[] = 'BT-'.$number;
                }

                continue;
            }

            /*
             | Two ids in one cell is an inclusive range.
             |
             | `BT-75`-`BT-79` covering five address lines is more readable
             | than five rows that say the same thing, so the document is
             | allowed to group them - and the registry is where a term's
             | exact cardinality lives, which is why cardinality is only
             | compared for a row naming one term.
             */
            $covered = \count($numbers) === 2 && $numbers[1] > $numbers[0]
                ? range($numbers[0], $numbers[1])
                : $numbers;

            foreach ($covered as $number) {
                $documented[] = 'BT-'.$number;
            }

            if (\count($covered) === 1) {
                $cardinalities['BT-'.$covered[0]] = $cells[2] ?? '';
            }
        }

        $documented = array_values(array_unique($documented));
        $registered = array_map(static fn (BT $t): string => $t->value, BT::cases());

        foreach ($registered as $id) {
            if (! \in_array($id, $documented, true)) {
                $violations[] = sprintf(
                    '%s is in the registry and not in the mapping document. A term with no documented '.
                    'source is a mapping question with no answer',
                    $id,
                );
            }
        }

        foreach ($documented as $id) {
            if (! \in_array($id, $registered, true)) {
                $violations[] = sprintf(
                    '%s is documented and not in the registry, so the document describes a term the '.
                    'serializers cannot emit',
                    $id,
                );
            }
        }

        foreach ($cardinalities as $id => $stated) {
            $term = BT::tryFrom($id);

            if ($term === null) {
                continue;
            }

            $expected = $term->cardinality()->value;

            if (! str_contains($stated, $expected)) {
                $violations[] = sprintf(
                    '%s is %s in the registry and the document says "%s"',
                    $id,
                    $expected,
                    $stated,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function aTermOnTheWrongGroupIsRefused(): array
    {
        $node = SemanticNode::of(BG::PaymentInstructions);

        try {
            // The deliver-to country code, set on the payment instructions.
            $node->set(BT::DeliverToCountryCode, 'SA');
        } catch (\LogicException $e) {
            return str_contains($e->getMessage(), 'BG-15') && str_contains($e->getMessage(), 'BG-16')
                ? []
                : ['the refusal does not name both the term\'s group and the node\'s: '.$e->getMessage()];
        }

        return ['a term was accepted on a group it does not belong to, so a serializer would emit a '.
            'delivery address inside the payment instructions and the recipient would reject the document'];
    }

    /** @return list<string> */
    private function repetitionIsRefused(): array
    {
        $node = SemanticNode::root();

        try {
            $node->add(BT::InvoiceNumber, 'INV-1');
        } catch (\LogicException $e) {
            return str_contains($e->getMessage(), '1..1')
                ? []
                : ['the refusal does not say what the cardinality is: '.$e->getMessage()];
        }

        return ['BT-1 was repeated. One serializer would drop the second value and another would emit '.
            'it twice, and the two syntaxes of one document would disagree'];
    }

    /** @return list<string> */
    private function badNestingIsRefused(): array
    {
        $violations = [];
        $root = SemanticNode::root();

        try {
            // The seller postal address nests in BG-4, not at the root.
            $root->nest(SemanticNode::of(BG::SellerPostalAddress));
            $violations[] = 'BG-5 was nested at the document root, where no schema expects it';
        } catch (\LogicException) {
            // As intended.
        }

        try {
            $root->nest(SemanticNode::root());
            $violations[] = 'the document root was nested inside itself';
        } catch (\LogicException) {
            // As intended.
        }

        return $violations;
    }

    /**
     * A missing mandatory term reports the rule a recipient would quote.
     *
     * @return list<string>
     */
    private function cardinalityNamesTheRule(): array
    {
        $violations = [];

        // A document with nothing on it: every mandatory term is missing.
        $found = array_map(
            static fn (\App\Exchange\Validation\SemanticViolation $v): string => $v->rule,
            app(CardinalityRules::class)->check(SemanticNode::root()),
        );

        foreach (['BR-02', 'BR-03', 'BR-04', 'BR-05', 'BR-16', 'BR-CO-18'] as $rule) {
            if (! \in_array($rule, $found, true)) {
                $violations[] = sprintf(
                    'an empty document did not report %s. A supplier who reads one message from us and '.
                    'a different one from the recipient about the same defect has to work out they are '.
                    'the same thing',
                    $rule,
                );
            }
        }

        /*
         | BR-08 is about a seller WITH no address, not about no seller.
         |
         | Measured: an empty document did not report it, because the walk
         | does not descend into a group that has no instance - and it
         | should not. An invoice with no seller at all is told BR-06, its
         | name is missing; telling it the seller's address is missing too
         | is a second message about the same hole. So the case that must
         | report BR-08 is a seller who is there and has no address.
         */
        $root = SemanticNode::root();
        $seller = SemanticNode::of(BG::Seller);
        $seller->set(BT::SellerName, 'A Seller LLC');
        $root->nest($seller);

        $withASeller = array_map(
            static fn (\App\Exchange\Validation\SemanticViolation $v): string => $v->rule,
            app(CardinalityRules::class)->check($root),
        );

        if (! \in_array('BR-08', $withASeller, true)) {
            $violations[] = 'a seller with no postal address did not report BR-08';
        }

        /*
         | And a group's own terms are only required when the group is there.
         |
         | BT-84 is mandatory inside BG-17, and an invoice with no credit
         | transfer must not be told its IBAN is missing.
         */
        if (\in_array('BR-50', $found, true)) {
            $violations[] = 'an invoice with no credit transfer group was told BT-84 is missing, which '.
                'is a message about a group nobody used';
        }

        return $violations;
    }

    /** @return list<string> */
    private function everyRuleIsExplained(): array
    {
        $violations = [];
        $table = (array) config('en16931.rules', []);
        $emitted = [];

        foreach ($this->exchangeSources() as $file) {
            /*
             | `BR-*` and our own `EN16931-*` identifiers.
             |
             | The second group covers the failures that are not EN 16931
             | rules - a serializer that threw, bytes that do not parse, a
             | schema mismatch - and they need an explanation for the same
             | reason: a message a reader cannot act on is not a message.
             */
            preg_match_all(
                "/'((?:BR|EN16931|PEPPOL)-[A-Z0-9-]+)'/",
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] ?? [] as $rule) {
                $emitted[$rule] = true;
            }
        }

        if ($emitted === []) {
            return ['no rule identifiers were found in the Exchange sources, so this check is inert'];
        }

        foreach (array_keys($emitted) as $rule) {
            $entry = $table[$rule] ?? null;

            if (! \is_array($entry)) {
                $violations[] = sprintf(
                    '%s can be reported and has no entry in config/en16931.php. A rule with no '.
                    'explanation is a rule the reader cannot act on',
                    $rule,
                );

                continue;
            }

            foreach (['en', 'ar'] as $locale) {
                if (trim((string) ($entry[$locale] ?? '')) === '') {
                    $violations[] = sprintf('%s has no %s explanation', $rule, $locale);
                }
            }
        }

        foreach (array_keys($table) as $rule) {

            if (! isset($emitted[(string) $rule])) {
                $violations[] = sprintf(
                    '%s is explained and nothing can report it. An explanation for a rule the code does '.
                    'not implement reads as coverage that is not there',
                    $rule,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function exchangeSources(): array
    {
        $files = [];
        $base = app_path('Exchange');

        if (! is_dir($base)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
