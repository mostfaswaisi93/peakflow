<?php

declare(strict_types=1);

namespace App\Tenant\Sales;

use JsonSerializable;

/**
 * One thing that would prevent issuing, addressed by field - PF-092.
 *
 * ## Addressed by field, not by message
 *
 * `lines.3.tax_code_id` rather than "a line has no tax code". The difference
 * is whether the person editing can be taken to the thing that is wrong: a
 * list of sentences at the foot of a form is a list somebody reads once and
 * then stops reading.
 *
 * ## Blocking and advisory are both worth saying
 *
 * A missing tax code blocks. A customer with no address does not - plenty of
 * regimes accept an invoice without one - and saying so anyway is how
 * somebody notices before the customer does. Reporting only the blockers
 * trains people to believe that whatever the form allows is correct.
 */
final readonly class InvoiceFinding implements JsonSerializable
{
    private function __construct(
        public string $field,
        public string $code,
        public string $messageEn,
        public string $messageAr,
        public bool $blocking,
    ) {}

    public static function blocking(string $field, string $code, string $en, string $ar): self
    {
        return new self($field, $code, $en, $ar, true);
    }

    public static function advisory(string $field, string $code, string $en, string $ar): self
    {
        return new self($field, $code, $en, $ar, false);
    }

    public function message(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->messageAr : $this->messageEn;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => ['en' => $this->messageEn, 'ar' => $this->messageAr],
            'blocking' => $this->blocking,
        ];
    }
}
