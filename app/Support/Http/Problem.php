<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An RFC 9457 `application/problem+json` response.
 *
 * ## Errors are addressed, not described
 *
 * A field error carries a JSON Pointer (RFC 6901). A document-rule failure
 * carries an **EN 16931 business term** - `BT-112`, not
 * `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount`. Users do not read
 * XPath, and neither do integrators.
 *
 * `type` and `title` are never localized: they are the machine-readable
 * contract. `detail` is, by `Accept-Language`.
 */
final class Problem implements Responsable
{
    /** @var list<array<string, string>> */
    private array $errors = [];

    private ?int $status = null;

    private ?string $detail = null;

    /** @var array<string, mixed> */
    private array $extensions = [];

    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private readonly ProblemType $type,
    ) {}

    public static function of(ProblemType $type): self
    {
        return new self($type);
    }

    /**
     * A specific sentence, in one language.
     *
     * Use it only where the text is already localized - a domain message that
     * carries both languages, or a validator's own output. A hand-written
     * English sentence here is the commonest way `detail` stops being
     * localized, and it fails silently: the response is perfectly readable,
     * in the wrong language, to the one reader who asked for the other.
     *
     * {@see withLocalizedDetail()} is the two-language form, and
     * {@see ProblemType::detail()} is the registry's own.
     */
    public function withDetail(string $detail): self
    {
        $this->detail = $detail;

        return $this;
    }

    /**
     * The same sentence in both languages, chosen by the request's locale.
     *
     * For a call site that knows something the registry cannot - which scope
     * is missing, which entities a token names - and should still answer in
     * the language that was asked for. Written as a pair here rather than as
     * a catalogue key because these sentences describe a condition the code
     * around them already names, and splitting the two puts half of an error
     * in a translation file where a reviewer cannot see it.
     */
    public function withLocalizedDetail(string $ar, string $en): self
    {
        $this->detail = app()->getLocale() === 'ar' ? $ar : $en;

        return $this;
    }

    /** Narrow the status. A call site may never widen it beyond the type's own. */
    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /** A field error, addressed by JSON Pointer into the request body. */
    public function withFieldError(string $pointer, string $code, string $detail): self
    {
        $this->errors[] = [
            'pointer' => $pointer,
            'code' => $code,
            'detail' => $detail,
        ];

        return $this;
    }

    /**
     * A document-rule error, addressed by EN 16931 business term.
     *
     * @param  string  $businessTerm  e.g. `BT-112`
     * @param  string  $rule  e.g. `BR-CO-14`
     */
    public function withBusinessTermError(string $businessTerm, string $rule, string $detail): self
    {
        $this->errors[] = [
            'business_term' => $businessTerm,
            'code' => $rule,
            'detail' => $detail,
        ];

        return $this;
    }

    /** @param array<string, mixed> $extensions */
    public function withExtensions(array $extensions): self
    {
        $this->extensions = [...$this->extensions, ...$extensions];

        return $this;
    }

    /**
     * How long to wait, as a HEADER and not only as a body member.
     *
     * Both, because they are read by different things. `Retry-After` is what
     * an HTTP client library, a proxy and a queue runner already understand
     * with no code written; `retry_after` in the body is what somebody
     * reading a logged response can see without the headers beside it.
     *
     * A problem that carried only the body member would be ignored by every
     * off-the-shelf retry policy, which is how a 429 or an in-flight 409
     * turns into a tight loop against the thing that asked for a pause.
     */
    public function withRetryAfter(int $seconds): self
    {
        $this->headers['Retry-After'] = (string) $seconds;

        return $this->withExtensions(['retry_after' => $seconds]);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    /**
     * Laravel's validator output, converted to addressed field errors.
     *
     * @param  array<string, list<string>>  $errors
     */
    public function withValidationErrors(array $errors): self
    {
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $this->withFieldError(
                    '/'.str_replace('.', '/', $field),
                    'invalid',
                    $message,
                );
            }
        }

        return $this;
    }

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        $body = array_filter(
            [
                'type' => $this->type->uri(),
                'title' => $this->type->title(),
                'status' => $this->status ?? $this->type->status(),
                /*
                 | The type's own sentence when the call site had nothing more
                 | specific, rather than no sentence at all.
                 |
                 | A problem document with no `detail` leaves a client with a
                 | title it must not switch on and a URI it has to go and read
                 | - so the first thing every integrator does is print the
                 | title to their user, in English, whatever language that
                 | user reads. The registry's default is localized, which is
                 | the whole point of having one.
                 */
                'detail' => $this->detail ?? $this->type->detail(),
                'instance' => $request->getRequestUri(),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        $body = [...$body, ...$this->extensions];

        return new JsonResponse(
            $body,
            $this->status ?? $this->type->status(),
            ['Content-Type' => 'application/problem+json', ...$this->headers],
        );
    }
}
