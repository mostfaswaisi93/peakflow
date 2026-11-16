<?php

declare(strict_types=1);

namespace App\Exchange\Semantics;

/**
 * How many times a term or a group may appear - EN 16931-1.
 *
 * Enforced once, in the model, rather than in each serializer: a term that
 * is mandatory in UBL and forgotten in CII is a document that validates in
 * one syntax and not the other, which is the failure two serializers over
 * one model exist to prevent.
 */
enum Cardinality: string
{
    case One = '1..1';
    case ZeroOrOne = '0..1';
    case OneOrMany = '1..n';
    case ZeroOrMany = '0..n';

    public function isMandatory(): bool
    {
        return $this === self::One || $this === self::OneOrMany;
    }

    public function isRepeatable(): bool
    {
        return $this === self::OneOrMany || $this === self::ZeroOrMany;
    }
}
