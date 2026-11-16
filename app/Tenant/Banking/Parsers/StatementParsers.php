<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Tenant\Banking\Exceptions\StatementUnreadable;

/**
 * Which reader reads a file - PF-150 to PF-155.
 *
 * ## The format is checked, not trusted
 *
 * A person picks "CAMT.053" from a list and uploads an MT940, because the
 * two live in the same folder and the names mean nothing to them. Reading an
 * MT940 as CAMT produces no rows; reading a CAMT.052 as a CAMT.053 produces
 * rows that look right and are provisional. The second is the dangerous one,
 * so a named format is verified against the content before anything is
 * parsed, and a mismatch is refused with what the file actually looks like.
 *
 * ## Sniffing is by shape, never by extension
 *
 * A bank that serves CAMT.053 as `statement.txt` is ordinary, and an
 * extension is whatever the browser put there. Each reader says whether it
 * recognises the content, and the order is by fidelity - so a file that
 * could be read two ways is read the richer way.
 *
 * CSV has no shape to recognise. It is never sniffed, only chosen: anything
 * is a CSV if you squint, and a sniffer that guessed CSV would swallow every
 * file no other reader claimed and produce nonsense rows instead of a
 * refusal.
 */
final class StatementParsers
{
    /** @var list<StatementParser> */
    private array $readers;

    /** @param list<StatementParser>|null $readers */
    public function __construct(?array $readers = null)
    {
        /*
         | Ordered by fidelity, as `banking.formats` is.
         |
         | Where a file could be read two ways, the richer reading wins -
         | and the order is the same one the configuration documents, so
         | there is one answer to "which do we prefer" rather than two.
         */
        $this->readers = $readers ?? [
            new CamtParser('camt053'),
            new CamtParser('camt052'),
            new CamtParser('camt054'),
            new Mt940Parser('mt940'),
            new Mt940Parser('mt942'),
            new OfxParser(),
        ];
    }

    /**
     * The reader for a named format.
     *
     * @throws StatementUnreadable
     */
    public function for(string $format): StatementParser
    {
        foreach ($this->readers as $reader) {
            if ($reader->format() === $format) {
                return $reader;
            }
        }

        throw StatementUnreadable::because(sprintf(
            'PeakFlow has no reader for "%s". It reads: %s. A CSV is imported with a column '.
            'mapping rather than a reader, because a CSV has no shape of its own.',
            $format,
            $this->known(),
        ));
    }

    /**
     * The reader that recognises this content.
     *
     * @throws StatementUnreadable
     */
    public function sniff(string $content): StatementParser
    {
        foreach ($this->readers as $reader) {
            if ($reader->recognises($content)) {
                return $reader;
            }
        }

        throw StatementUnreadable::unrecognised($this->known());
    }

    /**
     * Parse, with the named format checked against the content.
     *
     * @return list<ParsedStatement>
     *
     * @throws StatementUnreadable
     */
    public function parse(string $content, ?string $format = null): array
    {
        if ($format === null) {
            return $this->sniff($content)->parse($content);
        }

        $reader = $this->for($format);

        if (! $reader->recognises($content)) {
            /*
             | Named as one thing and shaped like another.
             |
             | Reported with what it does look like, because "this is not a
             | CAMT.053" leaves somebody guessing, and the answer is usually
             | that they picked the wrong entry from a list of four.
             */
            throw StatementUnreadable::notTheFormatItClaims($format, $this->describe($content));
        }

        /*
         | A CAMT message says which of the three it is, and the reader
         | cannot tell from its own format alone - all three recognise all
         | three. So the content decides, and importing a 052 as a 053 is
         | refused rather than silently treated as booked.
         */
        $actual = $this->camtKind($content);

        if ($actual !== null && str_starts_with($format, 'camt') && $actual !== $format) {
            throw StatementUnreadable::notTheFormatItClaims($format, strtoupper($actual).', which is '.(
                $actual === 'camt053'
                    ? 'the booked statement'
                    : 'provisional: it feeds the cash position and reconciles nothing'
            ));
        }

        return $reader->parse($content);
    }

    /** What a file looks like, for a message somebody has to act on. */
    public function describe(string $content): string
    {
        $kind = $this->camtKind($content);

        if ($kind !== null) {
            return 'an ISO 20022 '.strtoupper($kind).' message';
        }

        foreach ($this->readers as $reader) {
            if ($reader->recognises($content)) {
                $name = strtoupper($reader->format());

            return (str_starts_with($name, 'O') ? 'an ' : 'a ').$name.' file';
            }
        }

        $head = ltrim(substr($content, 0, 256));

        if (str_starts_with($head, '<?xml') || str_starts_with($head, '<')) {
            return 'an XML document that is not a statement message';
        }

        if (str_contains($head, ',') || str_contains($head, ';')) {
            return 'delimited text, which is imported as CSV with a column mapping';
        }

        return 'something PeakFlow does not recognise';
    }

    /** Which CAMT message this is, by its root element. */
    private function camtKind(string $content): ?string
    {
        $head = substr($content, 0, 8192);

        return match (true) {
            str_contains($head, 'BkToCstmrStmt') => 'camt053',
            str_contains($head, 'BkToCstmrAcctRpt') => 'camt052',
            str_contains($head, 'BkToCstmrDbtCdtNtfctn') => 'camt054',
            default => null,
        };
    }

    private function known(): string
    {
        return implode(', ', array_map(
            static fn (StatementParser $reader): string => $reader->format(),
            $this->readers,
        ));
    }
}
