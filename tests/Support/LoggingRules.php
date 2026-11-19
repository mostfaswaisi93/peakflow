<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Telemetry\AllowlistProcessor;
use App\Support\Telemetry\CorrelationId;
use App\Support\Telemetry\LogContext;
use App\Support\Telemetry\ExceptionShape;
use App\Support\Telemetry\SecretShapes;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Throwable;

/**
 * Structured logging and the field allowlist - PF-014.
 *
 * ## The failure this suite exists because of
 *
 * The tap that attaches the allowlist processor named a Monolog constant that
 * does not exist in this version. Laravel catches errors while resolving a log
 * channel - reasonably, because a broken logger must not take the application
 * with it - so the tap never ran, the formatter stayed line-based, and every
 * secret went to the log.
 *
 * It was configured. It looked configured. The probe that found it reported
 * five leaks and all five were real.
 *
 * So these checks assert what is ATTACHED, not what is declared, and they put
 * a real IBAN, a real token and a real password through a real channel and
 * read the bytes that came out.
 */
final class LoggingRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'every configured channel has the allowlist processor attached' => $this->tapsAreApplied(),
            'a payload of secrets produces a line containing none of them' => $this->secretsDoNotSurvive(),
            'an error report carries the diagnosis and none of the payload' => $this->theErrorReportIsSafeAndUseful(),
            'the allowlist keeps the diagnostics it is supposed to' => $this->diagnosticsSurvive(),
            'tenant, entity and correlation are on every line' => $this->ambientIdsArePresent(),
            'a value shape with a check digit is scrubbed under any field name' => $this->shapesAreScrubbed(),
            'a correlation id is sanitised and bounded' => $this->correlationIdIsSafe(),
        ];
    }

    /**
     * The processor is ATTACHED, not merely declared.
     *
     * This is the check that would have caught the broken tap immediately.
     * Asserting the config contains a `tap` key proves nothing: the tap
     * threw, Laravel swallowed it, and the config still said what it said.
     *
     * @return list<string>
     */
    public function tapsAreApplied(): array
    {
        $violations = [];
        $channels = (array) config('logging.channels', []);

        $declaring = [];

        foreach ($channels as $name => $definition) {
            $taps = (array) ($definition['tap'] ?? []);

            if ($taps === []) {
                continue;
            }

            $declaring[] = (string) $name;
        }

        if ($declaring === []) {
            return ['no log channel declares the allowlist tap, so nothing is filtering a log line'];
        }

        foreach ($declaring as $name) {
            try {
                $logger = app('log')->channel($name);
            } catch (Throwable $e) {
                $violations[] = sprintf('channel "%s" could not be resolved at all: %s', $name, $e->getMessage());

                continue;
            }

            $monolog = $logger->getLogger();

            $processors = array_map(
                static fn (object $processor): string => $processor::class,
                $monolog->getProcessors(),
            );

            if (! in_array(AllowlistProcessor::class, $processors, true)) {
                $violations[] = sprintf(
                    'channel "%s" declares the tap and has no allowlist processor attached. A tap that '.
                    'throws is swallowed by Laravel - reasonably, because a broken logger must not take '.
                    'the application with it - so a redaction mechanism that fails this way fails OPEN, '.
                    'silently, while looking configured',
                    $name,
                );
            }

            foreach ($monolog->getHandlers() as $handler) {
                if (! method_exists($handler, 'getFormatter')) {
                    continue;
                }

                if (! $handler->getFormatter() instanceof JsonFormatter) {
                    $violations[] = sprintf(
                        'channel "%s" writes through %s rather than a JSON formatter. A line-based format '.
                        'is parsed by a regular expression that works until a message contains the '.
                        'delimiter, and these messages contain quoted refusals from PostgreSQL',
                        $name,
                        $handler->getFormatter()::class,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The error report says enough to act on, and leaks none of it - PF-234.
     *
     * ## Both halves, because either alone is satisfied by nothing
     *
     * A report that carried no context at all would pass a check asking only
     * "does the IBAN survive". That was the state: Laravel reports an
     * exception as its message plus `['exception' => $e]`, `exception` is not
     * on the allowlist, and the whole thing was dropped - so a production 500
     * was one scrubbed sentence with no class, no file, no line and no
     * frames.
     *
     * Safe, and unusable. An error report nobody can act on is the reason
     * somebody turns the allowlist off, so this asserts the diagnosis
     * survives as well as that the secrets do not.
     *
     * ## Driven with the payload the criterion names
     *
     * An IBAN, a card number, a token, a password, an email and an API key,
     * in the message AND as an ARGUMENT to the frame that threw - because
     * arguments are the half that `getTraceAsString()` renders and
     * `getTrace()` carries in full.
     *
     * The chained exception matters too: a `QueryException` under a domain
     * refusal puts the failing statement and its bindings in its message,
     * and the bindings are the amounts and the account numbers. Only its
     * class is kept.
     *
     * @return list<string>
     */
    public function theErrorReportIsSafeAndUseful(): array
    {
        $iban = 'SA0380000000608010167519';
        $card = '4111111111111111';

        $secrets = [
            'the IBAN' => $iban,
            'the card number' => $card,
            'the token' => 'tok_live_51H8xQ2eZvKYlo2C',
            'the password' => 'correct-horse-battery-staple',
            'the email' => 'someone@example.test',
            'the API key' => 'sk-abcdef0123456789',
        ];

        /*
         | Thrown from a frame whose ARGUMENTS are the secrets.
         |
         | A closure called with them, so `getTrace()` carries them under
         | `args` exactly as a real call would. An exception built inline
         | would have an empty argument list and prove nothing about the
         | half of the trace that leaks.
         */
        $thrower = static function (string $iban, string $card, string $password) : void {
            throw new \RuntimeException(
                sprintf('could not pay %s from card %s', $iban, $card),
                0,
                new \LogicException('insert into payments values (:iban) with bindings '.$iban),
            );
        };

        try {
            $thrower($iban, $card, 'correct-horse-battery-staple');

            return ['the probe did not throw, so nothing was reported'];
        } catch (\Throwable $e) {
            $bytes = $this->through($e->getMessage(), ExceptionShape::of($e));
        }

        if ($bytes === null) {
            return ['nothing was written through the handler, so nothing was checked'];
        }

        $violations = [];

        foreach ($secrets as $what => $secret) {
            /*
             | A PREFIX, not the whole value.
             |
             | Twelve characters, because a truncated secret is still one.
             |
             | Measured while writing this: PHP's `zend.exception_ignore_args`
             | defaults to ON, so `getTrace()` carries no arguments and
             | `getTraceAsString()` elides a long value as `'...'`. Under that
             | default a trace leaks nothing and a value check proves nothing.
             |
             | With the ini OFF - a common development and incident-debugging
             | choice, because it is what makes a trace show what was passed -
             | `getTrace()[n]['args']` carries the IBAN in full. The prefix is
             | what catches a partial leak either way, and the structural
             | assertion below is what holds whatever the ini says.
             */
            $prefix = mb_substr($secret, 0, 12);

            if (str_contains($bytes, $prefix)) {
                $violations[] = sprintf(
                    '%s survives into the error report, in whole or truncated. A trace\'s arguments '.
                    'are where a secret lives, and a report built from getTraceAsString() renders '.
                    'them cut to fifteen characters - which makes an IBAN unreadable rather than '.
                    'absent, and it is still an IBAN',
                    $what,
                );
            }
        }

        /*
         | And the diagnosis is there.
         |
         | Without this the check passes against a reporter that logs
         | nothing at all - which is exactly the state it was written for.
         */
        foreach ([
            'the class' => 'RuntimeException',
            'the chained cause' => 'LogicException',
            'the file' => 'exception_file',
            'the line' => 'exception_line',
            'the frames' => 'exception_frames',
        ] as $what => $needle) {
            if (! str_contains($bytes, $needle)) {
                $violations[] = sprintf(
                    'the error report does not carry %s. An error report nobody can act on is not a '.
                    'smaller problem than one that leaks - it is the reason somebody turns the '.
                    'allowlist off',
                    $what,
                );
            }
        }

        /*
         | Structural, and therefore ini-independent.
         |
         | Every frame is a STRING built from five names. A shape that
         | passed `getTrace()` through would carry arrays, and with
         | `zend.exception_ignore_args=0` those arrays carry `args` - the
         | arguments in full. Asserting the type is what holds on a machine
         | configured the other way, where the value check above would find
         | nothing to object to.
         */
        foreach (ExceptionShape::of($e)['exception_frames'] as $frame) {
            if (! is_string($frame)) {
                $violations[] = sprintf(
                    'a frame is a %s rather than a string, so the trace is being passed through '.
                    'rather than rebuilt - and with zend.exception_ignore_args off, a passed-through '.
                    'frame carries the call\'s arguments in full',
                    get_debug_type($frame),
                );

                break;
            }
        }

        if (str_contains(json_encode(ExceptionShape::of($e)) ?: '', '"args"')) {
            $violations[] = 'the shape carries an "args" key, which is where a trace keeps the '.
                'values a call was made with';
        }

        /*
         | The frames name code and not a filesystem.
         |
         | An absolute path tells a reader of the log where the deployment
         | lives and which user it runs as, and makes two environments'
         | reports of one fault look different.
         */
        if (str_contains($bytes, base_path().'/')) {
            $violations[] = 'the report carries an absolute path, which says where the deployment '.
                'lives and makes the same fault look different in two environments';
        }

        return $violations;
    }


    /**
     * A real payload through a real handler, and the bytes read back.
     *
     * @return list<string>
     */
    public function secretsDoNotSurvive(): array
    {
        $iban = 'SA0380000000608010167519';
        $card = '4111111111111111';

        $secrets = [
            'the IBAN' => $iban,
            'the token' => 'tok_live_51H8xQ2eZvKYlo2C',
            'the password' => 'correct-horse-battery-staple',
            'the email' => 'someone@example.test',
            'the API key' => 'sk-abcdef0123456789',
            'the card number' => $card,
        ];

        $record = $this->through(
            sprintf('could not validate %s for the supplier', $iban),
            [
                'iban' => $iban,
                'token' => 'tok_live_51H8xQ2eZvKYlo2C',
                'password' => 'correct-horse-battery-staple',
                'email' => 'someone@example.test',
                'api_key' => 'sk-abcdef0123456789',
                'card' => $card,
                /*
                 | The adversarial half: the secret under an ALLOWLISTED
                 | name.
                 |
                 | A field allowlist cannot know what a caller put in a
                 | legitimate field, and this case passed every field check
                 | and reached the line - which is why the value-shape layer
                 | exists.
                 */
                'reference' => $iban,
                'findings' => [['kind' => 'missing', 'iban' => $iban]],
                'sample' => ['card' => $card],
            ],
        );

        if ($record === null) {
            return ['nothing was written through the handler, so nothing was checked'];
        }

        $violations = [];
        $bytes = $record;

        foreach ($secrets as $what => $secret) {
            if (str_contains($bytes, $secret)) {
                $violations[] = sprintf(
                    '%s survived into the log line. The log is the one place nobody can delete from',
                    $what,
                );
            }
        }

        /*
         | And the line is still JSON, and still says a value was removed.
         |
         | `[REDACTED]` with no shape is a line nobody can tie to a record,
         | so the marker keeps the last four digits - which is what every
         | bank statement and support call already uses.
         */
        if (json_decode($bytes, true) === null) {
            $violations[] = 'the log line is not JSON, so a collector reads it with a regular expression '.
                'that works until a message contains the delimiter';
        }

        if (! str_contains($bytes, '7519')) {
            $violations[] = 'the scrubbed IBAN left no last four digits, so nobody reading the line can '.
                'tie it to an account';
        }

        if (! str_contains($bytes, 'IBAN')) {
            $violations[] = 'the line does not say that an IBAN was removed, so it reads as a field that '.
                'was never set';
        }

        return $violations;
    }

    /** @return list<string> */
    public function diagnosticsSurvive(): array
    {
        $record = $this->through('balance drift found', [
            'findings' => 3,
            'kinds' => ['missing' => 2, 'wrong' => 1],
            'checked' => 4800,
            'run_id' => 'RUN-77',
            'period_id' => '01M7FWABCDEFGHJKMNPQRSTVWX',
            'account_code' => '1.1.2.1',
        ]);

        if ($record === null) {
            return ['nothing was written through the handler'];
        }

        $violations = [];

        foreach (['RUN-77', '4800', '1.1.2.1', 'missing'] as $needle) {
            if (! str_contains($record, $needle)) {
                $violations[] = sprintf(
                    '"%s" did not survive the allowlist. A scrubber that eats the diagnostics is a '.
                    'scrubber somebody turns off, and the field names the invariant alerts use have to be '.
                    'on the list',
                    $needle,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function ambientIdsArePresent(): array
    {
        $violations = [];

        $context = LogContext::build(['run_id' => 'RUN-1']);

        if (($context['correlation_id'] ?? '') === '') {
            $violations[] = 'a log line carries no correlation id. A line that cannot be joined to the '.
                'request that produced it is a line about the request somebody is trying to understand';
        }

        /*
         | A caller cannot override the ambient ids.
         |
         | Letting it pass its own `tenant_id` would let it pass the WRONG
         | one, and a line attributed to the wrong customer is worse than a
         | line attributed to none.
         */
        $forged = LogContext::build([
            'correlation_id' => 'FORGED',
            'tenant_id' => 'FORGED',
            'entity_id' => 'FORGED',
        ]);

        foreach (['correlation_id', 'tenant_id', 'entity_id'] as $key) {
            if (($forged[$key] ?? null) === 'FORGED') {
                $violations[] = sprintf(
                    'a caller overrode %s. Letting it pass its own would let it pass the wrong one, and a '.
                    'line attributed to the wrong customer is worse than one attributed to none',
                    $key,
                );
            }
        }

        /*
         | And personal data is absent BY CONSTRUCTION.
         |
         | Not by a redaction pass: `email`, `name`, `phone` and `address`
         | are simply not on the allowlist, so a line cannot carry them
         | however they are passed.
         */
        $allowlist = LogContext::allowlist();

        foreach (['email', 'name', 'phone', 'address', 'legal_name', 'iban', 'token', 'password'] as $forbidden) {
            if (in_array($forbidden, $allowlist, true)) {
                $violations[] = sprintf(
                    '"%s" is on the allowlist. Personal data and secrets are absent by construction, '.
                    'which is the whole reason this is an allowlist rather than a blocklist',
                    $forbidden,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function shapesAreScrubbed(): array
    {
        $violations = [];

        $cases = [
            'SA0380000000608010167519' => true,
            'GB33BUKB20201555555555' => true,
            '4111111111111111' => true,
            '4111 1111 1111 1111' => true,
            // Not secrets, and must survive: a document number, an account
            // code, a ULID, and an IBAN-shaped string with a bad check digit.
            'INV-2026-000471' => false,
            '1.1.2.1' => false,
            '01M7FWABCDEFGHJKMNPQRSTVWX' => false,
            'SA0480000000608010167519' => false,
            '1234567890123' => false,
        ];

        foreach ($cases as $value => $shouldScrub) {
            $scrubbed = (string) SecretShapes::scrub((string) $value);
            $wasScrubbed = $scrubbed !== (string) $value;

            if ($wasScrubbed !== $shouldScrub) {
                $violations[] = $shouldScrub
                    ? sprintf('"%s" was not scrubbed', $value)
                    : sprintf(
                        '"%s" was scrubbed to "%s". A scrubber that eats document numbers and account '.
                        'codes is one somebody turns off - which is why only shapes with a CHECK DIGIT '.
                        'are scrubbed',
                        $value,
                        $scrubbed,
                    );
            }
        }

        /*
         | And inside a sentence, because the value that leaks is usually a
         | message rather than a field.
         */
        $message = (string) SecretShapes::scrub('could not validate SA0380000000608010167519 for ACME');

        if (str_contains($message, 'SA0380000000608010167519')) {
            $violations[] = 'an IBAN inside a sentence was not scrubbed. An exception message is the log '.
                'line, and "could not validate <iban>" is an ordinary exception message';
        }

        if (! str_contains($message, 'ACME')) {
            $violations[] = 'scrubbing a sentence destroyed the rest of it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function correlationIdIsSafe(): array
    {
        $violations = [];

        /*
         | The header is attacker-controlled and lands in log lines, so a
         | newline in it forges a log entry - the oldest log-injection trick
         | there is, and one a JSON formatter does NOT stop on its own,
         | because the line is valid JSON holding a string that a line-based
         | collector then splits.
         */
        $adopted = CorrelationId::adopt("abc\ndef\r\n{\"level\":\"info\"}");

        if (str_contains($adopted, "\n") || str_contains($adopted, '{')) {
            $violations[] = sprintf(
                'a correlation id of "%s" was adopted unchanged. A newline in it forges a log entry',
                addcslashes($adopted, "\n\r"),
            );
        }

        $long = CorrelationId::adopt(str_repeat('a', 500));

        if (mb_strlen($long) > 64) {
            $violations[] = sprintf('a correlation id of %d characters was accepted', mb_strlen($long));
        }

        $generated = CorrelationId::adopt('!!!');

        if ($generated === '' || $generated === '!!!') {
            $violations[] = 'an unusable correlation id was not replaced with a generated one. A null id '.
                'means the lines cannot be joined, which is the one thing it exists to prevent';
        }

        $first = CorrelationId::current();
        $second = CorrelationId::current();

        if ($first !== $second) {
            $violations[] = 'two reads of the correlation id in one request disagreed, which makes it '.
                'useless while looking like it works';
        }

        CorrelationId::forget();

        return $violations;
    }

    /**
     * Put a message and a context through the real processor and formatter.
     *
     * A `TestHandler` with the real `JsonFormatter`, and the processor pushed
     * the way the tap pushes it - so what comes back is the bytes a collector
     * would read.
     *
     * @param  array<string, mixed>  $context
     */
    private function through(string $message, array $context): ?string
    {
        $handler = new TestHandler();
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true));

        $monolog = new Monolog('check');
        $monolog->pushProcessor(new AllowlistProcessor());
        $monolog->pushHandler($handler);

        $monolog->error($message, $context);

        $records = $handler->getRecords();
        $record = $records[0] ?? null;

        if (! $record instanceof LogRecord) {
            return null;
        }

        return (string) $handler->getFormatter()->format($record);
    }
}
