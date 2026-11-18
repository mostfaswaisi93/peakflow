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

    public static function tooLarge(int $bytes, int $cap): self
    {
        return new self(sprintf(
            'The document is %s bytes and the cap is %s. The size is checked BEFORE the parser sees '.
            'the bytes, because a parser is where an oversized document costs memory: the DOM libxml '.
            'builds from a document is several times the size of the document, so a cap applied after '.
            'parsing is a cap that has already been paid.',
            number_format($bytes),
            number_format($cap),
        ));
    }

    public static function tooDeep(int $depth, int $limit): self
    {
        return new self(sprintf(
            'The document nests elements %d deep and the limit is %d. No billing document, statement '.
            'or payment status has a legitimate reason to nest that far, and depth is what every '.
            'recursive reader downstream pays for - a limit libxml also enforces for itself, at 256, '.
            'which is too far to be the only one.',
            $depth,
            $limit,
        ));
    }

    /**
     * The DOCTYPE refusal that happens BEFORE the parser runs.
     *
     * Deliberately a different sentence from {@see hasDoctype()}, which is
     * the same refusal made after parsing. A check that merely sees "a
     * DOCTYPE was refused" cannot tell which of the two layers produced it,
     * and the whole point of the pre-parse layer is that the post-parse one
     * is too late.
     */
    public static function prologDeclaresADoctype(): self
    {
        return new self(
            'The document declares a DOCTYPE before its root element and is refused without being '.
            'parsed. It is refused HERE, on the bytes, rather than after parsing: libxml expands an '.
            'internal entity while it builds the tree whether or not LIBXML_NOENT was passed, so a '.
            'DOCTYPE check that runs on the parsed document has already paid for the expansion it '.
            'exists to prevent. Measured: a 347-byte document with five levels of nested entities '.
            'produced a 1,000,000-character text node before any post-parse check could look at it.'
        );
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
