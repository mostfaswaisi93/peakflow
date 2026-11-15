<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

/**
 * What is wrong with one line, and where - PF-055.
 *
 * ## The field matters as much as the message
 *
 * A screen that says "line 4 is invalid" makes somebody read four fields to
 * find out which. The field name is what lets the message land next to the
 * input that caused it, which is the difference between a form people can
 * fix and one they retype.
 *
 * ## And the explanation is not the rule
 *
 * "A control account cannot be selected" is the rule. Why not is the thing
 * the person actually needs: the subledger IS the receivable, a manual
 * posting into it makes the two disagree, and nothing afterwards can say
 * which is right. PF-055 asks for the UI to explain rather than hide, and
 * hiding the account from the picker is the version that gets built by
 * default - it makes the screen simpler and leaves the person hunting for an
 * account they can see in the chart.
 */
final readonly class LineProblem
{
    private function __construct(
        public int $line,
        public string $field,
        public string $code,
        public string $messageAr,
        public string $messageEn,
    ) {}

    public static function of(
        int $line,
        string $field,
        string $code,
        string $messageAr,
        string $messageEn,
    ): self {
        return new self($line, $field, $code, $messageAr, $messageEn);
    }

    public function message(string $locale): string
    {
        return $locale === 'ar' ? $this->messageAr : $this->messageEn;
    }

    /** @return array{line: int, field: string, code: string, message_ar: string, message_en: string} */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'field' => $this->field,
            'code' => $this->code,
            'message_ar' => $this->messageAr,
            'message_en' => $this->messageEn,
        ];
    }
}
