<?php

declare(strict_types=1);

namespace App\Support\Lang\Exceptions;

use RuntimeException;

/**
 * An ICU message that will not be rendered - PF-016.
 *
 * Thrown rather than degraded. The tempting fallback is to show the raw line,
 * and the raw line is `{count, plural, zero{...` - which a user reads as the
 * software being broken, next to a figure they were about to act on.
 *
 * A 500 is fixed in minutes. A nonsense sentence on an invoice is fixed when
 * somebody complains about it.
 */
final class MessageCannotBeFormatted extends RuntimeException
{
    /**
     * An argument the pattern names and the caller did not supply.
     *
     * Separate from {@see because()} because ICU does not report it at all -
     * it renders the placeholder and returns a string. Measured: a six-way
     * plural formatted with no `count` returned `{count}`, so the page
     * rendered and the sentence was nonsense.
     *
     * @param  list<string>  $missing
     */
    public static function missingArguments(string $key, string $locale, array $missing): self
    {
        return new self(sprintf(
            'The message "%s" names the argument(s) %s and they were not supplied, so for locale %s it '.
            'would have rendered the placeholder - ICU does not treat a missing argument as an error. '.
            'The sentence would read "%s" and nothing would have logged it.',
            mb_strimwidth($key, 0, 90, '…'),
            implode(', ', $missing),
            $locale,
            '{'.$missing[0].'}',
        ));
    }

    public static function because(string $key, string $locale, string $reason): self
    {
        return new self(sprintf(
            'The message "%s" could not be formatted for locale %s: %s. It is refused rather than shown '.
            'unformatted, because the unformatted form is the ICU pattern itself - and a user reading '.
            '"{count, plural, zero{" beside a total has every reason to doubt the total.',
            mb_strimwidth($key, 0, 90, '…'),
            $locale,
            $reason,
        ));
    }
}
