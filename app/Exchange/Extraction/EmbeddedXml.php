<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

/**
 * The XML inside a PDF - PF-142.
 *
 * ## A Factur-X PDF is a structured document, and must never reach OCR
 *
 * Which is PF-142's first criterion and the whole reason this class exists
 * before any OCR engine does. A Factur-X (or ZUGFeRD) invoice is a PDF/A-3
 * carrying the EN 16931 XML as an embedded file: the figures are *in* the
 * document, exactly, and running optical recognition over the picture of
 * them is choosing a guess over a fact.
 *
 * It is a surprisingly common mistake, because the file arrives looking like
 * every other PDF invoice and an extraction pipeline that starts with OCR
 * never asks. The result is a bill whose totals are nearly right.
 *
 * ## What this reads, and what it refuses to
 *
 * It walks the PDF's object table for an embedded file stream and returns its
 * bytes. It does **not** render, lay out, or interpret anything: there is no
 * font handling, no text extraction, no page tree walk. A PDF whose structure
 * it cannot follow returns null, and the caller falls back - which is the
 * honest failure, because the alternative is a half-read stream passed to an
 * XML parser as though it were a document.
 *
 * ## Why it is written here rather than taken from a library
 *
 * No PDF library is installable in this environment, and the part of the
 * format this needs is small and stable: a stream object, a filter, and a
 * name tree. What it deliberately does not attempt is the rest of PDF, which
 * is enormous and which nothing here needs.
 *
 * The limits below exist because this reads a file a stranger sent. A stream
 * that claims to inflate to a gigabyte is refused rather than inflated.
 */
final class EmbeddedXml
{
    /** The most an embedded file may inflate to, where configuration is silent. */
    private const MAX_INFLATED_BYTES = 16 * 1024 * 1024;

    /** The names a Factur-X, ZUGFeRD or Order-X attachment uses. */
    private const KNOWN_NAMES = [
        'factur-x.xml',
        'zugferd-invoice.xml',
        'xrechnung.xml',
        'order-x.xml',
        'zf-invoice.xml',
    ];

    /** Whether these bytes are a PDF at all. */
    public static function looksLikeAPdf(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF-');
    }

    /**
     * The embedded XML, or null where there is none to find.
     *
     * Null is not an error: most PDF invoices carry no XML, and that is what
     * the OCR fallback is for. An error would make every ordinary scan look
     * like a malformed Factur-X.
     */
    public function from(string $pdf): ?string
    {
        if (! self::looksLikeAPdf($pdf)) {
            return null;
        }

        /*
         | Every stream in the file, in order, with the dictionary above it.
         |
         | Walking the cross-reference table would be the correct way to read
         | a PDF and the wrong way to do this: a linearised file has two
         | tables, an incrementally-updated one has a chain of them, and a
         | file with a damaged table still carries intact streams. The
         | objects are what we want and they are all here.
         */
        foreach ($this->streams($pdf) as [$dictionary, $raw]) {
            if (! $this->isAnEmbeddedXmlFile($dictionary)) {
                continue;
            }

            $bytes = $this->decode($dictionary, $raw);

            if ($bytes === null) {
                continue;
            }

            /*
             | It has to look like XML before it is handed on.
             |
             | A stream whose dictionary says `text/xml` and whose bytes are
             | something else is a file somebody built by hand, and passing
             | it to the reader would report a parse error against a
             | supplier who sent a valid PDF.
             */
            if (! str_contains(substr($bytes, 0, 512), '<')) {
                continue;
            }

            return $bytes;
        }

        return null;
    }

    /**
     * Whether a stream's dictionary describes an embedded XML invoice.
     *
     * By subtype, then by the conventional file names. The subtype is what
     * the specification requires and the names are what files actually
     * carry - several producers write `/Subtype /application#2Fxml`, which is
     * the same thing with the slash escaped, and one or two write no subtype
     * at all.
     */
    private function isAnEmbeddedXmlFile(string $dictionary): bool
    {
        $normalised = str_replace('#2F', '/', $dictionary);

        if (str_contains($normalised, '/Subtype /text/xml')
            || str_contains($normalised, '/Subtype/text/xml')
            || str_contains($normalised, '/Subtype /application/xml')
            || str_contains($normalised, '/Subtype/application/xml')) {
            return true;
        }

        foreach (self::KNOWN_NAMES as $name) {
            if (stripos($dictionary, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stream's bytes, decoded.
     *
     * `FlateDecode` and no filter are the two that occur in practice. A
     * stream under any other filter returns null rather than being guessed
     * at: `/Crypt` means the file is encrypted, and `/DCTDecode` means
     * somebody has labelled a JPEG as an XML invoice.
     */
    private function decode(string $dictionary, string $raw): ?string
    {
        if (! str_contains($dictionary, '/Filter')) {
            return $this->within($raw);
        }

        if (! str_contains($dictionary, 'FlateDecode')) {
            return null;
        }

        /*
         | Inflated with its size checked, not after.
         |
         | A stream of a few hundred bytes can claim to inflate to a
         | gigabyte, and this reads a file a stranger sent. `gzinflate`'s
         | length argument stops at the limit rather than allocating past
         | it.
         */
        $cap = (int) config('extraction.max_embedded_bytes', self::MAX_INFLATED_BYTES);
        $inflated = @gzinflate($raw, $cap + 1);

        if ($inflated === false) {
            // Some producers write a zlib header, which gzinflate refuses.
            $inflated = @gzuncompress($raw, $cap + 1);
        }

        if ($inflated === false || \strlen($inflated) > $cap) {
            return null;
        }

        return $this->within($inflated);
    }

    /**
     * The XML within a stream, trimmed of anything before the declaration.
     *
     * Several producers prepend a byte-order mark, and one writes a newline
     * before the declaration. An XML parser refuses both, and refusing a
     * valid invoice over a leading newline is not a position worth
     * defending.
     */
    private function within(string $bytes): ?string
    {
        $start = strpos($bytes, '<?xml');

        if ($start === false) {
            $start = strpos($bytes, '<');
        }

        if ($start === false) {
            return null;
        }

        return substr($bytes, $start);
    }

    /**
     * The `/Length` a stream's dictionary declares, where it declares one.
     *
     * An indirect reference - `/Length 12 0 R` - is not resolved: following
     * it means reading the object table, which is the part of PDF this
     * deliberately does not implement. The EOL fallback handles it.
     */
    private function declaredLength(string $dictionary): ?int
    {
        if (preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dictionary, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Every `stream ... endstream` in the file, with the dictionary above it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function streams(string $pdf): array
    {
        $found = [];
        $offset = 0;

        while (true) {
            $start = strpos($pdf, 'stream', $offset);

            if ($start === false) {
                break;
            }

            /*
             | `endstream` and `endobj` both contain "stream" and "obj".
             |
             | Searching for the next `stream` from inside `endstream` finds
             | the same one again, which is an infinite loop in the obvious
             | implementation.
             */
            if ($start >= 3 && substr($pdf, $start - 3, 3) === 'end') {
                $offset = $start + 6;

                continue;
            }

            $end = strpos($pdf, 'endstream', $start);

            if ($end === false) {
                break;
            }

            $dictionaryStart = strrpos(substr($pdf, 0, $start), '<<');
            $dictionary = $dictionaryStart === false
                ? ''
                : substr($pdf, $dictionaryStart, $start - $dictionaryStart);

            /*
             | The data begins after the keyword's end-of-line.
             |
             | The specification allows CRLF or LF after `stream` and forbids
             | CR alone, and getting this wrong shifts every byte by one -
             | which inflates to nothing and reads as "no XML here".
             */
            $data = substr($pdf, $start + 6, $end - $start - 6);
            $data = preg_replace('/^\r\n|^\n/', '', $data, 1) ?? $data;

            /*
             | And it ENDS where `/Length` says, not where a newline is.
             |
             | Measured: trimming a trailing EOL instead ate a real byte
             | whenever the stream's own last byte was a carriage return -
             | the chunk then ends CR LF, a `\r\n$` strip removes both, and
             | the deflate stream is one byte short. It inflates to nothing,
             | so the document reads as a PDF with no invoice in it and goes
             | to OCR: the exact failure this class exists to prevent, on a
             | file that was perfectly valid.
             |
             | `/Length` is what the specification says the length is. The
             | EOL heuristic is the fallback for a producer that omits it.
             */
            $length = $this->declaredLength($dictionary);

            if ($length !== null && $length <= \strlen($data)) {
                $data = substr($data, 0, $length);
            } else {
                $data = (string) preg_replace('/\r\n$|\n$|\r$/', '', $data, 1);
            }

            $found[] = [$dictionary, $data];
            $offset = $end + 9;
        }

        return $found;
    }
}
