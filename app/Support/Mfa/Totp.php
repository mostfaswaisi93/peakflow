<?php

declare(strict_types=1);

namespace App\Support\Mfa;

use App\Support\Mfa\Exceptions\InvalidTotpSecret;

/**
 * Time-based one-time passwords, RFC 6238 - PF-030.
 *
 * Implemented here rather than taken from a package because it is forty lines
 * of HMAC, the standard has not changed since 2011, and a dependency in the
 * authentication path is a dependency that can take the authentication path
 * down.
 *
 * ## The window, and why it is not larger
 *
 * A code is accepted for the step it belongs to and for ONE step either side -
 * 30 seconds back for a user who typed slowly, 30 forward for a clock that is
 * fast. That is 90 seconds of validity.
 *
 * Widening the window is the obvious response to support tickets about codes
 * not working, and it is the wrong one: every extra step multiplies the codes
 * an attacker may guess and extends how long a shoulder-surfed code stays
 * useful. A user whose clock is minutes out has a clock problem, and telling
 * them so fixes it permanently.
 *
 * ## Replay is prevented by the caller, not here
 *
 * A correct code stays correct for its whole step, so a code observed in
 * transit can be used again within that window. This class cannot prevent
 * that - it has no memory. The verifier records the last accepted step per
 * authenticator and refuses anything at or before it.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    public const ALGORITHM = 'sha1';

    /** One step either side. See the class note on why this is not larger. */
    public const WINDOW = 1;

    /**
     * A new base32 secret.
     *
     * 160 bits, which is what RFC 4226 recommends and the SHA-1 block size:
     * a shorter secret weakens the HMAC, and a longer one is folded by the
     * HMAC anyway, so it would be a longer string for no extra work.
     */
    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    /** The code for a given moment, default now. */
    public static function at(string $secret, ?int $timestamp = null): string
    {
        return self::forStep($secret, self::stepAt($timestamp ?? time()));
    }

    /**
     * Which step a code belongs to, or null if it matches none in the window.
     *
     * Returns the STEP rather than a boolean so the caller can store it and
     * refuse a replay. A boolean would make replay prevention impossible
     * without re-deriving what this already knows.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $current = self::stepAt($timestamp ?? time());

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;

            // hash_equals, not ===. A timing-variable comparison of a
            // six-digit code is a narrow leak but a free one to close.
            if (hash_equals(self::forStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function stepAt(int $timestamp): int
    {
        return (int) floor($timestamp / self::PERIOD);
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     *
     * The issuer appears twice - once as a label prefix and once as a
     * parameter - because that is what the de facto spec requires and apps
     * disagree about which one they read.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => strtoupper(self::ALGORITHM),
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    private static function forStep(string $secret, int $step): string
    {
        $key = Base32::decode($secret);

        if (strlen($key) < 10) {
            throw InvalidTotpSecret::tooShort(strlen($key));
        }

        // The counter is a 64-bit big-endian integer. `J` would be correct and
        // is not portable, so the two halves are packed separately.
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);

        $hash = hash_hmac(self::ALGORITHM, $counter, $key, true);

        // Dynamic truncation, RFC 4226 section 5.3: the low nibble of the last
        // byte selects where to read four bytes from, and the top bit is
        // masked off so the result is a positive 31-bit integer on every
        // platform.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }
}
