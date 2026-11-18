<?php

declare(strict_types=1);

namespace App\Tenant\Sync\Exceptions;

use RuntimeException;

/**
 * Writes from a device that cannot be applied - PF-255.
 *
 * ## A conflict carries BOTH sides
 *
 * `$conflict` is what separates a conflict from a rejection. A
 * rejection is our answer: the payload is malformed, the period is
 * closed, the item does not exist. A conflict is a disagreement about
 * the world - the till sold three and the shelf holds one - and nobody
 * can decide it without seeing both numbers.
 *
 * So a refusal that wants to become a decidable conflict has to say
 * what the device said and what the server says. One that does not is
 * recorded as a rejection, which is the honest reading: if the code
 * raising it cannot name the two sides, there is nothing for a person
 * to decide.
 */
final class SyncRefused extends RuntimeException
{
    /** @var array{device: string, server: string}|null */
    public readonly ?array $conflict;

    /** @param array{device: string, server: string}|null $conflict */
    private function __construct(string $message, ?array $conflict = null)
    {
        parent::__construct($message);

        $this->conflict = $conflict;
    }

    public static function because(string $why): self
    {
        return new self($why);
    }

    /**
     * A disagreement about the world, with both sides named.
     *
     * Two tills selling the last unit. Surfaced, never merged: a
     * last-write-wins rule decides it by which shop has better wifi,
     * and decides it invisibly.
     */
    public static function aConflict(string $whatTheDeviceSaid, string $whatTheServerSays): self
    {
        return new self(
            sprintf(
                'The device recorded "%s" and the server has "%s", and the two cannot both be true.',
                $whatTheDeviceSaid,
                $whatTheServerSays,
            ),
            ['device' => $whatTheDeviceSaid, 'server' => $whatTheServerSays],
        );
    }

    public static function aWriteWithNoName(): self
    {
        return new self(
            'A write from a device carries an id the DEVICE generated, before its first attempt. '.
            'Without one there is no way to tell a retry from a second sale - a network that drops a '.
            'response is indistinguishable from one that drops a request, and the till has to retry.',
        );
    }
}
