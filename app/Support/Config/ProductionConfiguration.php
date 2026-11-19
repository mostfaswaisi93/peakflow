<?php

declare(strict_types=1);

namespace App\Support\Config;

use App\Support\Config\Exceptions\ConfigurationIsUnsafe;

/**
 * The settings a production deployment is not allowed to get wrong - PF-012.
 *
 * ## Why this is code and not a paragraph in a runbook
 *
 * PF-012 asks for a documented default and a documented safe range for every
 * value, and for risk-bearing values to document their platform-enforced
 * ceiling. A documented range that nothing enforces is a sentence in a file
 * somebody read once during onboarding.
 *
 * Every rule here is a value whose wrong setting is SILENT. That is the
 * selection criterion, and it is why the list is short. A wrong database host
 * fails immediately and loudly; `sslmode=prefer` works perfectly and sends
 * every row in plaintext the one time somebody can strip the TLS, and nothing
 * anywhere says so.
 *
 * ## It refuses to boot, which is a deliberate trade
 *
 * A deployment with `APP_DEBUG=true` is one stack trace away from publishing
 * table names, file paths and query bindings - and query bindings in this
 * system are amounts, IBANs and tax numbers. The alternative to refusing is
 * logging a warning into a log nobody reads until the incident.
 *
 * So the cost is accepted: a deployment that has not read this file does not
 * start, and the message says which value and why. That is better than one
 * that starts and is wrong, because the second kind is discovered by a
 * customer.
 *
 * ## Production only
 *
 * Every rule here would make local development impossible - a secure cookie
 * over `http://localhost` cannot be sent at all, and `verify-full` needs
 * certificates nobody has on a laptop. So the guard runs when
 * `app()->isProduction()` and nowhere else, and `.env.example` is deliberately
 * a configuration this guard would reject.
 */
final class ProductionConfiguration
{
    /**
     * Every unsafe value, with what to do about it.
     *
     * Returned rather than thrown so that a check can report all of them at
     * once. A deployment with four wrong values should learn about four, not
     * about one per restart.
     *
     * @param  array<string, mixed>|null  $overrides  config paths, for testing a hypothetical deployment
     * @return list<string>
     */
    public static function problems(?array $overrides = null): array
    {
        $get = static function (string $key, mixed $default = null) use ($overrides): mixed {
            if ($overrides !== null && \array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }

            return \function_exists('config') ? config($key, $default) : $default;
        };

        $problems = [];

        /*
         | APP_DEBUG.
         |
         | The one everybody knows and the one that still happens. Laravel's
         | debug page prints the query that failed WITH ITS BINDINGS, and in
         | this system the bindings are amounts, IBANs and tax registration
         | numbers. It also prints the file paths, which is the first half of
         | every subsequent attempt.
         */
        if ($get('app.debug') === true) {
            $problems[] = 'APP_DEBUG is true. The debug page prints the failing query WITH ITS BINDINGS, '.
                'and in this system a binding is an amount, an IBAN or a tax registration number. Set '.
                'APP_DEBUG=false.';
        }

        /*
         | The session cookie.
         |
         | `SESSION_SECURE_COOKIE` unset resolves to null, which Laravel reads
         | as "not secure" - so the cookie is sent over plain HTTP. It is the
         | default in the skeleton, it is invisible, and the consequence is a
         | session id on the wire.
         */
        if ($get('session.secure') !== true) {
            $problems[] = sprintf(
                'SESSION_SECURE_COOKIE is %s. Unset resolves to null, which means the session cookie is '.
                'sent over plain HTTP - the session id of an accountant with payment-release rights. Set '.
                'SESSION_SECURE_COOKIE=true.',
                var_export($get('session.secure'), true),
            );
        }

        if ($get('session.http_only') !== true) {
            $problems[] = 'SESSION_HTTP_ONLY is not true, so a single cross-site scripting bug reads the '.
                'session cookie out of JavaScript. Set SESSION_HTTP_ONLY=true.';
        }

        if (! \in_array($get('session.same_site'), ['lax', 'strict'], true)) {
            $problems[] = sprintf(
                'SESSION_SAME_SITE is %s. "none" sends the session cookie on cross-site requests, which '.
                'is what a cross-site request forgery needs; unset sends no attribute at all, which '.
                'leaves the answer to whichever default the visitor\'s browser version happens to have. '.
                'Use "lax" unless an embedded flow genuinely requires otherwise, and then say which one '.
                'in writing.',
                var_export($get('session.same_site'), true),
            );
        }

        /*
         | Session payload encryption.
         |
         | The store is the PLATFORM database, so every tenant's sessions sit
         | in one table that no row-level security policy covers - the
         | policies are per tenant database. The payload holds the entity
         | context and the second-factor assertion time, which together are
         | enough to answer "who was allowed to release a payment and when".
         */
        if ($get('session.encrypt') !== true) {
            $problems[] = 'SESSION_ENCRYPT is not true. Sessions are stored in the PLATFORM database, '.
                'which no row-level security policy covers, and the payload holds the entity context and '.
                'the second-factor assertion time. Set SESSION_ENCRYPT=true.';
        }

        /*
         | A secure cookie and an http:// URL cannot both be right.
         |
         | This one is not a security hole, it is an outage: the browser will
         | not send the cookie, so nobody can log in, and the symptom is "the
         | login form keeps reloading" rather than anything about cookies.
         */
        $url = (string) $get('app.url', '');

        if ($get('session.secure') === true && $url !== '' && ! str_starts_with($url, 'https://')) {
            $problems[] = sprintf(
                'SESSION_SECURE_COOKIE is true and APP_URL is %s. The browser will not send a secure '.
                'cookie over http, so nobody can sign in - and the symptom is a login form that keeps '.
                'reloading rather than anything mentioning cookies.',
                $url,
            );
        }

        /*
         | Database TLS.
         |
         | `prefer` is the libpq default and it is the dangerous one: it tries
         | TLS, and silently uses plaintext if the server says no. Everything
         | works, every row crosses the network in the clear the one time
         | somebody can answer for the server, and no log line mentions it.
         */
        foreach (['platform', 'tenant', 'migrator'] as $connection) {
            $mode = $get('database.connections.'.$connection.'.sslmode');

            if ($mode === null) {
                continue;
            }

            if (\in_array($mode, ['disable', 'allow', 'prefer'], true)) {
                $problems[] = sprintf(
                    'The %s connection uses sslmode=%s. "prefer" is the dangerous one: it tries TLS and '.
                    'silently falls back to plaintext, so everything works and every row crosses the '.
                    'network in the clear the one time somebody can answer for the server. Use "require" '.
                    'at least, and "verify-full" where the certificate chain allows it.',
                    $connection,
                    (string) $mode,
                );
            }
        }

        /*
         | The XML parser.
         |
         | These three default to false and are in the list because turning
         | one on to make a supplier's malformed invoice parse is a plausible
         | afternoon, and the result is a parser that reads local files.
         */
        foreach ([
            'exchange.xml.allow_dtd' => 'a DOCTYPE is how an entity declaration gets in',
            'exchange.xml.allow_external_entities' => 'an external entity reads local files and reaches the network',
            'exchange.xml.allow_network' => 'a schema reference becomes an outbound request to whoever sent the document',
        ] as $key => $why) {
            if ($get($key) === true) {
                $problems[] = sprintf('%s is true in production, and %s.', $key, $why);
            }
        }

        /*
         | Query bindings in telemetry.
         |
         | The statement SHAPE is useful and safe; the bindings are the data.
         | This is off by default and is exactly the switch somebody flips
         | while debugging a slow query at 2am and forgets.
         */
        if ($get('telemetry.database.record_bindings') === true) {
            $problems[] = 'telemetry.database.record_bindings is true, so every amount, IBAN and tax '.
                'number that reaches the database is copied into the trace store - which has a different '.
                'retention policy and a different audience from the database.';
        }

        /*
         | The log channel has to be one that filters its own lines.
         |
         | ## Measured, because this is a bypass and not a preference
         |
         | `config/logging.php` declares eleven channels and exactly TWO -
         | `single` and `daily` - carry `'tap' => [JsonLog::class]`, which is
         | what installs `AllowlistProcessor`. Read off the resolved logger:
         |
         |     single   ContextLogProcessor, AllowlistProcessor, PsrLogMessage
         |     stderr   ContextLogProcessor, PsrLogMessage
         |
         | So a line written on `stderr` has been through no allowlist at
         | all. Nothing constrained the choice: `LOG_CHANNEL` and `LOG_STACK`
         | had no reader in this class or in the configuration checks. And
         | `LOG_STACK=single,stderr` is the ordinary choice in a container -
         | one copy to a file, one to the collector - which sends every log
         | line, every span and every alert through the unfiltered half.
         |
         | `config/telemetry.php`'s own narrative says a line may not carry
         | "a bound value, an account number, a party name or anything
         | else". The allowlist is what enforces that sentence, and it could
         | be left out of the path by one environment variable.
         |
         | ## The whole stack, not just the default
         |
         | A stack writes to every member, so checking only `logging.default`
         | would pass a stack whose first channel filters and whose second
         | does not - which is the shape above, and the one somebody arrives
         | at by adding a collector to a working setup.
         |
         | ## Why it refuses rather than installing the tap
         |
         | Adding the processor to all eleven channels would make the guard
         | unnecessary and the product worse: `null` exists to discard,
         | `emergency` exists for when logging itself is broken, and quietly
         | rewriting a chosen channel hides the decision. A refusal names
         | the channel and the fix.
         */
        foreach (self::channelsThatWillBeWritten($get) as $channel) {
            $taps = $get(sprintf('logging.channels.%s.tap', $channel));

            if (\is_array($taps) && \in_array(\App\Support\Telemetry\JsonLog::class, $taps, true)) {
                continue;
            }

            $problems[] = sprintf(
                'the log channel "%s" will be written to and declares no allowlist tap, so every line, '.
                'span and alert reaching it has been through no field allowlist at all - bound values, '.
                'account numbers and party names included. Only "single" and "daily" carry it: write '.
                'to one of those, or add the JsonLog tap to that channel.',
                $channel,
            );
        }

        /*
         | The stub drivers.
         |
         | Both are the committed defaults so that a fresh clone works with no
         | third-party credentials (PF-229). Both mean "do nothing, silently"
         | if they survive into production: `log` sends no invoice to a tax
         | authority, and `stub` renders a placeholder PDF. Neither errors.
         */
        if ($get('exchange.driver') === 'log') {
            $problems[] = 'EXCHANGE_DRIVER is "log", which is the fresh-clone default and means nothing '.
                'is actually transmitted. An invoice would be marked sent, no tax authority would receive '.
                'it, and nothing would error - the discovery is a penalty notice.';
        }

        if ($get('pdf.driver') === 'stub') {
            $problems[] = 'PDF_DRIVER is "stub", which is the fresh-clone default and renders a '.
                'placeholder. Customers would receive it.';
        }

        if ($get('pdf.driver') !== 'stub' && $get('pdf.verapdf.enforce') !== true) {
            $problems[] = 'PDF/A conformance checking is off while a real PDF driver is configured. An '.
                'archival invoice that is not PDF/A is rejected by the receiving archive, months later, '.
                'in bulk.';
        }

        /*
         | A clearance sandbox in production.
         |
         | Only checked when the profile is enabled, because a deployment that
         | does not do Saudi e-invoicing has no reason to hold a production
         | ZATCA configuration.
         */
        if ($get('jurisdictions.enabled.zatca') === true && $get('exchange.zatca.environment') !== 'production') {
            $problems[] = sprintf(
                'The ZATCA profile is enabled and exchange.zatca.environment is "%s". A sandbox '.
                'clearance returns a valid-looking response and has no legal effect, so every invoice '.
                'would appear cleared and none would be.',
                (string) $get('exchange.zatca.environment'),
            );
        }

        if ($get('tenancy.migrate.require_online_safety_declaration') !== true) {
            $problems[] = 'tenancy.migrate.require_online_safety_declaration is off, so a migration that '.
                'takes an ACCESS EXCLUSIVE lock can reach a live tenant without anybody having said it '.
                'is safe to.';
        }

        if ($get('exchange.webhooks.refuse_private_ranges') !== true) {
            $problems[] = 'exchange.webhooks.refuse_private_ranges is off, so a tenant can point a '.
                'webhook at 169.254.169.254 and have the platform fetch its own cloud credentials.';
        }

        if ($get('exchange.webhooks.follow_redirects') === true) {
            $problems[] = 'exchange.webhooks.follow_redirects is on, which defeats the private-range '.
                'check: the first request goes to a public host and the redirect goes wherever it likes.';
        }

        /*
         | The attachment scanner - PF-073.
         |
         | `unavailable` stores a file and leaves it unreadable, which is the
         | right behaviour on a developer's machine and a broken product in
         | production: nobody can open a bank statement they attached. The
         | deployment needs a scanner, and the absence of one is a
         | configuration problem rather than a feature.
         */
        if ($get('attachments.scanner') === 'unavailable') {
            $problems[] = 'attachments.scanner is "unavailable", so every uploaded file is stored '.
                'unscanned and therefore unreadable. Attachments are bank statements and contracts, '.
                'and a product where nobody can open one is not working.';
        }

        /*
         | The download origin.
         |
         | Empty means a tenant's uploaded file is served from the
         | application's own host. A stored HTML or SVG file then executes
         | against that origin, where the session cookie is - and
         | `Content-Disposition` and `nosniff` have each been bypassed
         | somewhere, which is why the origin is the layer that matters.
         */
        if (trim((string) $get('attachments.origin', '')) === '') {
            $problems[] = 'attachments.origin is empty, so uploaded files are served from the '.
                'application\'s own origin. A stored HTML or SVG file then runs where the session '.
                'cookie is.';
        }

        if ((int) $get('attachments.url_ttl_seconds', 300) > 3600) {
            $problems[] = sprintf(
                'attachments.url_ttl_seconds is %d. A download link ends up in a browser history, a '.
                'chat message and a support ticket, and the file stays readable from all three until '.
                'it expires.',
                (int) $get('attachments.url_ttl_seconds', 300),
            );
        }

        /*
         | Object-lock on the archive bucket - PF-129.
         |
         | The database refuses a delete before the retention date, which is
         | the half that can be enforced here. The other half is the bucket:
         | an archive an operator with credentials can delete is not a WORM
         | archive, whatever the application refuses - and object-lock cannot
         | be turned on after a bucket exists, so a deployment that did not
         | do it has to be told while it still can.
         */
        if ($get('archive.object_lock') !== true) {
            $problems[] = 'archive.object_lock is off, so the archive bucket has no WORM retention. '.
                'The database refuses a delete before the retention date; a bucket without object-lock '.
                'lets anybody with credentials remove the bytes anyway, and object-lock cannot be '.
                'enabled after the bucket exists.';
        }

        if ($get('archive.disk') === $get('attachments.disk')) {
            $problems[] = 'the archive and attachments share a disk. They have different retention and '.
                'different deletion rules: an attachment goes when its document does, and an archived '.
                'artefact cannot go for years whatever anybody asks.';
        }

        return $problems;
    }

    /** @throws ConfigurationIsUnsafe */
    public static function assert(): void
    {
        $problems = self::problems();

        if ($problems !== []) {
            throw ConfigurationIsUnsafe::because($problems);
        }
    }

    /**
     * Every channel a log line can actually land on, a stack expanded.
     *
     * One level of expansion, deliberately. Laravel permits a stack whose
     * member is another stack, and resolving that recursively would need a
     * cycle guard for a configuration nobody writes. A nested stack falls
     * through to the tap check and is reported by name, which is the right
     * answer for a shape this guard does not understand - rather than
     * silently accepting it.
     *
     * @param  callable(string): mixed  $get
     * @return list<string>
     */
    private static function channelsThatWillBeWritten(callable $get): array
    {
        $default = (string) $get('logging.default');

        if ($default === '') {
            return [];
        }

        $members = $get(sprintf('logging.channels.%s.channels', $default));

        if (! \is_array($members) || $members === []) {
            return [$default];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $member): string => (string) $member,
            $members,
        )));
    }
}
