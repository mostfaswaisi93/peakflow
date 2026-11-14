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

    private function __construct(
        private readonly ProblemType $type,
    ) {}

    public static function of(ProblemType $type): self
    {
        return new self($type);
    }

    public function withDetail(string $detail): self
    {
        $this->detail = $detail;

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
                'detail' => $this->detail,
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
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
