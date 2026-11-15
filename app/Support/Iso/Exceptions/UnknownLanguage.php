<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use RuntimeException;

/**
 * A locale the product is not translated into - PF-007.
 */
final class UnknownLanguage extends RuntimeException
{
    /** @param  list<string>  $served  */
    public static function notServed(string $tag, array $served): self
    {
        return new self(sprintf(
            'PeakFlow does not serve the locale "%s". It serves %s. A locale is a promise that the product '.
            'has been translated, so accepting an untranslated one would set a language the interface then '.
            'renders in English - which is worse than refusing it, because the user chose it and the product '.
            'appeared to agree.',
            $tag,
            implode(', ', $served),
        ));
    }
}
