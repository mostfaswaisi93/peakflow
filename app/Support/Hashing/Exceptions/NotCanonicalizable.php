<?php

declare(strict_types=1);

namespace App\Support\Hashing\Exceptions;

use RuntimeException;

final class NotCanonicalizable extends RuntimeException
{
    public static function float(float $value): self // @arch-allow: this method exists to REJECT a float; it has to be able to receive one
    {
        return new self(sprintf(
            'A float (%s) reached the canonicalizer. PeakFlow has no float in its money path, so this is a bug rather than an input problem: an amount must be a decimal string and a quantity must be a string or an integer. Hashing a float would make the hash depend on the platform.',
            var_export($value, true),
        ));
    }

    public static function unsafeInteger(int $value, string $nearest): self
    {
        return new self(sprintf(
            'The integer %d cannot be canonicalized: it is outside the range an IEEE-754 double '.
            'represents exactly, and RFC 8785 processes numbers as doubles. A conformant implementation '.
            'reading it would serialize %s and compute a different hash, so the chain would verify here '.
            'and fail at a counterparty. Nothing in PeakFlow needs an integer this large - money is a '.
            'decimal string and identifiers are ULIDs.',
            $value,
            $nearest,
        ));
    }

    public static function xmlEmpty(): self
    {
        return new self('There is no XML to canonicalize. An empty string hashes to a constant, which '.
            'would be the same hash for every empty document and for every document that failed to '.
            'arrive.');
    }

    public static function xmlUnparseable(string $reason): self
    {
        return new self(sprintf(
            'The XML could not be parsed: %s. Canonicalization is defined over an information set, so a '.
            'document that has none cannot have a canonical form - and hashing the raw bytes instead '.
            'would produce a value that changes the moment anything reserializes it.',
            $reason,
        ));
    }

    public static function xmlHasDoctype(string $name): self
    {
        return new self(sprintf(
            'The XML declares a DOCTYPE (%s), which is refused. Measured both ways and both are wrong: '.
            'with entity substitution on, an entity declaring file:///etc/passwd canonicalized to the '.
            'password file; with it off, the entity reference canonicalized to nothing, so a document '.
            'whose content sits in an entity hashes as though the content were absent while a conformant '.
            'processor includes it. No e-invoicing syntax needs a DOCTYPE - UBL 2.1 and UN/CEFACT CII are '.
            'schema-defined and declare no entities.',
            $name,
        ));
    }

    public static function xmlUsesDisputedAttribute(string $attribute): self
    {
        return new self(sprintf(
            'The XML uses %s, which Canonical XML 1.0 and 1.1 handle differently - and 1.1 is not '.
            'reachable through PHP\'s DOM API, which exposes only libxml\'s 1.0 and exclusive-1.0 modes. '.
            'Producing 1.0 output under a signature that claims 1.1 is a signature that verifies nowhere '.
            'else, so the document is refused rather than canonicalized under an algorithm it did not '.
            'ask for.',
            $attribute,
        ));
    }

    public static function xmlProducedNothing(): self
    {
        return new self('Canonicalization produced an empty string from a document that parsed. That is '.
            'the shape an unexpanded entity reference leaves behind, and an empty canonical form hashes '.
            'to the same value for every document it happens to.');
    }

    public static function xmlFragmentNotFound(string $xpath): self
    {
        return new self(sprintf(
            'No node matches %s, so there is nothing to sign. A signature over a fragment that is not '.
            'there would be a signature over nothing, and it would verify.',
            $xpath,
        ));
    }

    public static function xmlFragmentAmbiguous(string $xpath, int $count): self
    {
        return new self(sprintf(
            '%d nodes match %s. A signature must name one, because which of the matches was signed is '.
            'not recoverable from the signature afterwards.',
            $count,
            $xpath,
        ));
    }

    public static function xmlFragmentNotAnElement(string $xpath, string $type): self
    {
        return new self(sprintf(
            '%s matches a %s rather than an element. Canonical XML over a bare attribute or text node is '.
            'defined, and is not what any signature profile asks for.',
            $xpath,
            $type,
        ));
    }

    public static function type(string $type): self
    {
        return new self(sprintf('A value of type %s cannot be canonicalized.', $type));
    }

    public static function topLevel(string $type): self
    {
        return new self(sprintf(
            'A canonical payload must serialize to an array or object; %s was given.',
            $type,
        ));
    }

    public static function encoding(string $value): self
    {
        return new self(sprintf('"%s" is not valid UTF-8 and cannot be ordered.', $value));
    }
}
