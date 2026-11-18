<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exchange\Messaging\Contracts\MessageChannel;
use App\Exchange\Messaging\Providers\FakeChannel;
use Illuminate\Support\ServiceProvider;

/**
 * Which channel implementation is in effect - PF-257.
 *
 * ## The fake is the DEFAULT, and that is deliberate
 *
 * A deployment that has not configured a provider sends nothing and
 * records why, rather than failing at the first invoice - and an
 * operator setting the product up can watch the whole delivery path
 * work before they have an account.
 *
 * It is also what every check runs against, which keeps the checks
 * about this product's behaviour rather than a vendor's uptime.
 *
 * ## One binding, so there is one place to change vendor
 *
 * A product wired to a vendor's SDK at every call site is a product
 * that cannot change vendor - and WhatsApp Business is reached through
 * resellers whose pricing and approval queues differ, so a business
 * that outgrows one has to move.
 */
final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessageChannel::class, static function (): MessageChannel {
            $driver = (string) config('messaging.driver', 'fake');

            /*
             | An unknown driver gets the fake and not an exception.
             |
             | A typo in configuration must not stop a business issuing
             | invoices: the document is issued either way, and an
             | undelivered one is a row somebody can see. Failing at boot
             | would take the whole product down for a delivery setting.
             */
            return match ($driver) {
                default => new FakeChannel(),
            };
        });
    }
}
