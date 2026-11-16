<?php

declare(strict_types=1);

namespace App\Exchange\Validation;

use App\Exchange\Semantics\BusinessGroup;
use App\Exchange\Semantics\BusinessTerm;

/**
 * One validation failure, addressed by business term - PF-117.
 *
 * ## Why not an XPath
 *
 * A validator says
 * `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount`. The person
 * reading it is a bookkeeper who has never seen UBL and never should.
 * `BT-112, the invoice total with VAT` is the same fact in the language of
 * the document they are looking at, and it is the same string whether the
 * failure came from UBL, from CII or from the model itself - which is the
 * other reason: a message that quotes the syntax tells the reader which
 * serializer ran, and that is not information they can use.
 *
 * The syntax path is kept, in {@see $path}, because the engineer who has to
 * fix a serializer needs it. It is not what the user is shown.
 *
 * ## Severity
 *
 * `fatal` stops the document. `internal` means a rule fired that an earlier
 * stage should have caught - a calculation error reaching the EN 16931 rule
 * set is a bug in `Tax`, not something to put in front of a customer - and
 * the pipeline reports those as internal errors so they are fixed rather
 * than worked around.
 */
final readonly class SemanticViolation
{
    private function __construct(
        public string $rule,
        public ?BusinessTerm $term,
        public ?BusinessGroup $group,
        public ?string $value,
        public string $severity,
        public ?string $path = null,
        public ?string $detail = null,
    ) {}

    public static function fatal(
        string $rule,
        ?BusinessTerm $term = null,
        ?BusinessGroup $group = null,
        ?string $value = null,
        ?string $detail = null,
    ): self {
        return new self($rule, $term, $group, $value, 'fatal', null, $detail);
    }

    /**
     * A rule an earlier stage should have caught.
     *
     * Reaching the EN 16931 rule set with a calculation error means the
     * document was built wrong, and a customer-facing message about
     * `BR-CO-14` would ask them to fix arithmetic they never entered.
     */
    public static function internal(
        string $rule,
        ?BusinessTerm $term = null,
        ?string $value = null,
        ?string $detail = null,
    ): self {
        return new self($rule, $term, $term?->group(), $value, 'internal', null, $detail);
    }

    public function withPath(string $path): self
    {
        return new self($this->rule, $this->term, $this->group, $this->value, $this->severity, $path, $this->detail);
    }

    public function isInternal(): bool
    {
        return $this->severity === 'internal';
    }

    /** The business term this failure is about, as a user-facing address. */
    public function address(): string
    {
        if ($this->term !== null) {
            return $this->term->value;
        }

        return $this->group?->value ?? 'document';
    }

    /**
     * The bilingual explanation, from the rule table.
     *
     * @return array{en: string, ar: string}
     */
    public function explain(): array
    {
        $table = (array) config('en16931.rules', []);
        $entry = $table[$this->rule] ?? null;

        if (! \is_array($entry)) {
            /*
             | A rule with no explanation is a rule nobody can act on.
             |
             | `ExchangeRules` fails the build when an implemented rule is
             | missing from the table, so this branch is what the user sees
             | if that check is ever removed - and it names the rule rather
             | than pretending to explain it.
             */
            return [
                'en' => sprintf('%s failed on %s.', $this->rule, $this->address()),
                'ar' => sprintf('فشل التحقق %s في %s.', $this->rule, $this->address()),
            ];
        }

        return [
            'en' => (string) ($entry['en'] ?? ''),
            'ar' => (string) ($entry['ar'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $explanation = $this->explain();

        return array_filter([
            'rule' => $this->rule,
            'term' => $this->term?->value,
            'term_name' => $this->term?->label(),
            'group' => $this->group?->value,
            'severity' => $this->severity,
            'value' => $this->value,
            'detail' => $this->detail,
            'path' => $this->path,
            'en' => $explanation['en'],
            'ar' => $explanation['ar'],
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
