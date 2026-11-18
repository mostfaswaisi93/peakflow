<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use RuntimeException;

/**
 * Devices that cannot act, and why - PF-255.
 */
final class DeviceRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    /**
     * One message whether the token is unknown or malformed.
     *
     * A refusal that distinguished them would tell somebody with a list
     * of guesses which ones exist, which is the only thing they want.
     */
    public static function noSuchToken(): self
    {
        return new self(
            'That token is not one this platform issued, or it no longer is. If the device was '.
            're-registered its old token stopped working at that moment; register it again and use the '.
            'new one.',
        );
    }

    public static function revoked(string $name, string $on, string $reason): self
    {
        return new self(sprintf(
            'Device "%s" was revoked on %s: %s. It cannot read or write anything, and nothing it sends '.
            'will be queued for later.',
            $name,
            $on,
            $reason,
        ));
    }

    public static function anUnknownKind(string $kind): self
    {
        return new self(sprintf(
            '"%s" is not a kind of device this release knows. The ones it knows are a till, a handheld, '.
            'a phone, a tablet and an integration - the kind decides what a device is allowed to do, so '.
            'it is not a label.',
            $kind,
        ));
    }
}
