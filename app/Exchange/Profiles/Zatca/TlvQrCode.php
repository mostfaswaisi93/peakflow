<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Zatca;

/**
 * The ZATCA QR payload, TLV-encoded - PF-123.
 *
 * ## TLV, not JSON and not a URL
 *
 * Tag, length, value: one byte for the tag, one byte for the length, then
 * the bytes. Concatenated, then base64 for the document and for the printed
 * code. It is a fixed binary format because a phone with no network has to
 * read it: the inspector scans the code on a printed receipt and sees the
 * seller, the registration, the time, the total and the tax without asking
 * anything of anybody.
 *
 * That is also why the tag numbers matter more than their names. A reader
 * written to the specification looks for tag 4 and finds the gross total; a
 * reader written against a producer who reordered them finds whatever that
 * producer put there.
 *
 * | Tag | Value |
 * |-----|-------|
 * | 1 | Seller name |
 * | 2 | Seller VAT registration number |
 * | 3 | Invoice timestamp, ISO 8601 |
 * | 4 | Invoice total including VAT |
 * | 5 | VAT total |
 * | 6 | Hash of the invoice XML |
 * | 7 | ECDSA signature of the cryptographic stamp |
 * | 8 | ECDSA public key of the stamp |
 * | 9 | ECDSA signature of the authority's stamp, on a simplified invoice |
 *
 * Tags 1 to 5 are what a document carries without sealing. 6 to 9 arrive
 * with PF-124, which needs a key in a KMS - and until it does, this encoder
 * **omits them rather than emitting empty ones**: a tag with a zero-length
 * value is a reader being told the signature is there and is nothing.
 *
 * ## Length is a byte, and a byte is 255
 *
 * A seller name in Arabic is two or three bytes per character in UTF-8, so a
 * long trading name can exceed 255 bytes and the length field cannot say so.
 * The encoder refuses rather than truncating: a QR code carrying half a
 * seller's name is worse than one that failed to build, because the failure
 * is visible and the truncation is not.
 */
final class TlvQrCode
{
    public const TAG_SELLER_NAME = 1;

    public const TAG_VAT_NUMBER = 2;

    public const TAG_TIMESTAMP = 3;

    public const TAG_TOTAL_WITH_VAT = 4;

    public const TAG_VAT_TOTAL = 5;

    public const TAG_XML_HASH = 6;

    public const TAG_SIGNATURE = 7;

    public const TAG_PUBLIC_KEY = 8;

    public const TAG_AUTHORITY_SIGNATURE = 9;

    /** One byte holds the length, so a value is at most 255 bytes. */
    private const MAX_VALUE_BYTES = 255;

    /**
     * @param  array<int, string|null>  $values  tag => value, in tag order
     *
     * @throws \LengthException if a value does not fit its length byte
     */
    public static function encode(array $values): string
    {
        ksort($values);

        $payload = '';

        foreach ($values as $tag => $value) {
            if ($value === null || $value === '') {
                /*
                 | An absent tag is absent, not empty.
                 |
                 | Emitting tag 7 with no bytes tells a reader the signature
                 | is present and is nothing, which is a worse answer than
                 | "this document is not sealed".
                 */
                continue;
            }

            $bytes = \strlen($value);

            if ($bytes > self::MAX_VALUE_BYTES) {
                throw new \LengthException(sprintf(
                    'Tag %d is %d bytes and TLV holds the length in one byte, so 255 is the maximum. '.
                    'An Arabic name is two or three bytes per character, and a QR code carrying half a '.
                    'seller\'s name is worse than one that failed to build.',
                    $tag,
                    $bytes,
                ));
            }

            $payload .= \chr($tag).\chr($bytes).$value;
        }

        return $payload;
    }

    /** The payload as it appears in the document and under the printed code. */
    public static function base64(array $values): string
    {
        return base64_encode(self::encode($values));
    }

    /**
     * Read a payload back, for a check or a support question.
     *
     * Deliberately strict: a payload whose last value runs past the end of
     * the buffer is a truncated one, and returning what could be read would
     * make a corrupted code look like a short one.
     *
     * @return array<int, string>
     *
     * @throws \UnexpectedValueException if the payload is malformed
     */
    public static function decode(string $payload): array
    {
        $values = [];
        $offset = 0;
        $length = \strlen($payload);

        while ($offset < $length) {
            if ($offset + 2 > $length) {
                throw new \UnexpectedValueException(sprintf(
                    'The payload ends mid-header at byte %d: a tag with no length byte.',
                    $offset,
                ));
            }

            $tag = \ord($payload[$offset]);
            $size = \ord($payload[$offset + 1]);
            $offset += 2;

            if ($offset + $size > $length) {
                throw new \UnexpectedValueException(sprintf(
                    'Tag %d declares %d bytes and only %d remain, so the payload is truncated.',
                    $tag,
                    $size,
                    $length - $offset,
                ));
            }

            $values[$tag] = substr($payload, $offset, $size);
            $offset += $size;
        }

        return $values;
    }

    /** @return array<int, string> */
    public static function decodeBase64(string $encoded): array
    {
        $payload = base64_decode($encoded, true);

        if ($payload === false) {
            throw new \UnexpectedValueException('The QR payload is not valid base64.');
        }

        return self::decode($payload);
    }
}
