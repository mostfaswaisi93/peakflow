<?php

declare(strict_types=1);

namespace App\Support\Xml;

/**
 * XML that cannot be read, with the reason - PF-114.
 *
 * The message carries the parser's own words, and the line and column where
 * it gave up. "The document could not be parsed" is not something a person
 * can act on, and the sender of a 4,000-line invoice needs to be told where.
 */
final class MalformedXml extends \RuntimeException
{
    /*
     | `atLine`, not `line`.
     |
     | `Exception::$line` already exists and is the line of the `throw` -
     | this file - which is the least useful number available. Shadowing it
     | is a fatal error in PHP, and reusing it would have made every report
     | point at the parser instead of at the document.
     */
    private function __construct(
        string $message,
        public readonly ?int $atLine = null,
        public readonly ?int $atColumn = null,
    ) {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('The document is empty, so there is nothing to parse.');
    }

    public static function unparseable(string $reason, ?int $line = null, ?int $column = null): self
    {
        $where = $line === null
            ? ''
            : sprintf(' at line %d%s', $line, $column === null || $column === 0 ? '' : ', column '.$column);

        return new self(sprintf('The document is not well-formed%s: %s', $where, $reason), $line, $column);
    }

    public static function hasDoctype(string $name): self
    {
        return new self(sprintf(
            'The document declares a DOCTYPE (%s) and is refused. A DOCTYPE in a received invoice has '.
            'no legitimate purpose, and entity expansion needs no external reference: ten nested '.
            'entities expand to a gigabyte from a few hundred bytes, and the parser is doing exactly '.
            'what the specification says.',
            $name === '' ? 'unnamed' : $name,
        ));
    }
}
