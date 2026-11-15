<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * The place-of-supply rule book will not load - PF-078.
 *
 * ## Validated at load, refused whole
 *
 * Not per-rule, and not lazily. A rule book with one bad row is a rule book
 * whose precedence is unknown: the bad row might be the one that should have
 * won, so skipping it answers a question with the wrong rule. Everything
 * stops, and the message names the row.
 *
 * Every refusal here is a typo somebody could make in a configuration file.
 * A typo'd key defaults, a typo'd country never matches, a duplicated
 * precedence makes the answer depend on array order - all three are silent,
 * and all three change what a document charges.
 */
final class RuleBookUnusable extends RuntimeException
{
    public static function notAList(): self
    {
        return new self(
            'config/place_of_supply.php must return an array with "blocs" and "rules" keys.',
        );
    }

    public static function missingCondition(string $rule, string $key): self
    {
        return new self(sprintf(
            'Rule "%s" does not state "%s". Every rule states all five conditions and there is no '.
            'default: a condition nobody wrote is a condition nobody thought about, and the one that '.
            'gets left out is the one that would have excluded the case somebody is about to be '.
            'assessed on. Write "*" if it genuinely does not matter.',
            $rule,
            $key,
        ));
    }

    public static function unknownKey(string $rule, string $key): self
    {
        return new self(sprintf(
            'Rule "%s" carries the unknown key "%s". Refused rather than ignored, because the key that '.
            'gets misspelled is "category" - and an ignored category leaves the rule charging standard '.
            'rate on a supply that should have been reverse-charged.',
            $rule,
            $key,
        ));
    }

    public static function duplicatePrecedence(int $precedence, string $first, string $second): self
    {
        return new self(sprintf(
            'Rules "%s" and "%s" both have precedence %d. Which one wins would then depend on the order '.
            'they happen to sit in the file, and array order is invisible in a diff: a rule moved by '.
            'three lines would change what thousands of documents charge and read as a reformatting.',
            $first,
            $second,
            $precedence,
        ));
    }

    public static function duplicateId(string $id): self
    {
        return new self(sprintf(
            'Two rules share the id "%s". The id is what gets recorded on a document as the reason for '.
            'its tax treatment, so two rules with one id means a document that cites a rule nobody can '.
            'identify afterwards.',
            $id,
        ));
    }

    /** @param  list<string>  $allowed */
    public static function unknownWord(string $rule, string $key, string $word, array $allowed): self
    {
        return new self(sprintf(
            'Rule "%s" uses "%s" for "%s", which is not in the vocabulary (%s) and is not an ISO 3166-1 '.
            'alpha-2 code. A word the engine does not know would never match, and a rule that never '.
            'matches is a rule the file claims to have.',
            $rule,
            $word,
            $key,
            implode(', ', $allowed),
        ));
    }

    public static function unknownBloc(string $rule, string $bloc): self
    {
        return new self(sprintf(
            'Rule "%s" refers to the bloc "%s", which is not declared under "blocs".',
            $rule,
            $bloc,
        ));
    }

    public static function unknownPlace(string $rule, string $place): self
    {
        return new self(sprintf(
            'Rule "%s" places the supply at "%s". The three places are the supplier, the customer and '.
            'the delivery location, because those are the only countries a supply has.',
            $rule,
            $place,
        ));
    }

    public static function unknownCategory(string $rule, string $category): self
    {
        return new self(sprintf(
            'Rule "%s" carries the tax category "%s", which is not a UNCL 5305 code this engine knows.',
            $rule,
            $category,
        ));
    }

    public static function placeCannotBeReached(string $rule): self
    {
        return new self(sprintf(
            'Rule "%s" places the supply at the delivery location while accepting any delivery location, '.
            '"*" included. A supply with no delivery location would then match the rule and the rule '.
            'would have no country to answer with - so it must constrain the delivery location it '.
            'depends on, and the engine refuses the supply instead of answering with nothing.',
            $rule,
        ));
    }

    public static function unknownCountryInABloc(string $bloc, string $country): self
    {
        return new self(sprintf(
            'The bloc "%s" lists "%s", which is not an ISO 3166-1 alpha-2 code. A member that no country '.
            'record can equal is a member that silently never matches - and the bloc then answers '.
            '"export" for a supply that is intra-bloc. Note that EU VAT identifiers use EL for Greece '.
            'and XI for Northern Ireland; neither is an ISO 3166-1 code.',
            $bloc,
            $country,
        ));
    }

    public static function blocWithoutASource(string $bloc): self
    {
        return new self(sprintf(
            'The bloc "%s" declares no source. Membership of a bloc decides whether a supply is an '.
            'intra-bloc supply or a zero-rated export, so the instrument it comes from is part of the '.
            'data, not a comment.',
            $bloc,
        ));
    }

    public static function ruleWithoutASource(string $rule): self
    {
        return new self(sprintf(
            'Rule "%s" cites no source. A rule with no article behind it cannot be defended in an audit '.
            'and cannot be checked when the law changes, which is the moment somebody needs to know '.
            'which rows to revisit.',
            $rule,
        ));
    }

    public static function departureThatIsAlsoAMember(string $bloc, string $country): self
    {
        return new self(sprintf(
            'The bloc "%s" lists "%s" as both a member and a departure. One of the two is wrong, and '.
            'which one decides whether a supply there is intra-bloc or an export.',
            $bloc,
            $country,
        ));
    }

    public static function emptyBook(): self
    {
        return new self(
            'The place-of-supply rule book is empty. Every supply would be refused, which is safe and '.
            'is not a configuration anybody intended.',
        );
    }
}
