<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login throttling, per account AND per address - PF-029.
 *
 * ## Why both, and why neither alone is enough
 *
 * Per-ACCOUNT only: an attacker spreads one guess across ten thousand
 * accounts from one machine and never trips a counter. That is credential
 * stuffing, and it is the common attack, not the rare one.
 *
 * Per-ADDRESS only: an attacker distributes the same attack across a botnet
 * and never trips a counter either, while one office behind a single NAT
 * locks out forty colleagues the moment one of them fat-fingers a password.
 *
 * So both, with different budgets: an account tolerates few attempts because
 * a real person knows their own password, and an address tolerates more
 * because it legitimately carries many people.
 *
 * ## The account key is hashed
 *
 * The limiter key contains the email address, and limiter keys end up in
 * Redis, in `KEYS` output, in a memory dump, and in whatever a support
 * engineer screenshots. Hashing costs nothing and means the rate-limit store
 * is not a list of customer email addresses.
 */
final class LoginThrottle
{
    private const ACCOUNT_ATTEMPTS = 5;

    private const ACCOUNT_DECAY_SECONDS = 900;

    private const ADDRESS_ATTEMPTS = 30;

    private const ADDRESS_DECAY_SECONDS = 900;

    /** Seconds the caller must wait, or null if they may try. */
    public function retryAfter(string $email, string $ip): ?int
    {
        foreach ([
            [$this->accountKey($email), self::ACCOUNT_ATTEMPTS],
            [$this->addressKey($ip), self::ADDRESS_ATTEMPTS],
        ] as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return RateLimiter::availableIn($key);
            }
        }

        return null;
    }

    public function recordFailure(string $email, string $ip): void
    {
        RateLimiter::hit($this->accountKey($email), self::ACCOUNT_DECAY_SECONDS);
        RateLimiter::hit($this->addressKey($ip), self::ADDRESS_DECAY_SECONDS);
    }

    /**
     * Clear the account counter on success - but NOT the address counter.
     *
     * An attacker who owns one valid account on a tenant could otherwise reset
     * the address budget at will by signing into it between guesses, which
     * turns the per-address limit into decoration.
     */
    public function recordSuccess(string $email): void
    {
        RateLimiter::clear($this->accountKey($email));
    }

    private function accountKey(string $email): string
    {
        return 'login:account:'.hash('sha256', Str::lower(trim($email)));
    }

    private function addressKey(string $ip): string
    {
        return 'login:ip:'.hash('sha256', $ip);
    }
}
