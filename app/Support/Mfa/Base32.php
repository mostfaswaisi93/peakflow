<?php

declare(strict_types=1);

namespace App\Support\Mfa;

use App\Support\Mfa\Exceptions\InvalidTotpSecret;

/**
 * Base32, RFC 4648 - the encoding every authenticator app expects for a TOTP
 * secret.
 *
 * Not base64: the alphabet excludes 0, 1, 8 and 9 precisely so that a human
 * reading a secret aloud or typing it from a screen cannot confuse it with O,
 * I, B or g. That is the whole reason the encoding exists here.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // Pad to a multiple of five bits, because base32 encodes five at a time.
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function decode(string $encoded): string
    {
        // Padding and spacing are stripped rather than rejected: a user
        // pasting a secret from a screen brings whichever of them the screen
        // used, and refusing would be pedantry about a transport detail.
        $encoded = strtoupper(preg_replace('/[\s=-]/', '', $encoded) ?? '');

        if ($encoded === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($encoded) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                throw InvalidTotpSecret::notBase32($char);
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        // Only whole bytes. A trailing partial group is the encoder's padding,
        // never data, so discarding it is correct rather than lossy.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
