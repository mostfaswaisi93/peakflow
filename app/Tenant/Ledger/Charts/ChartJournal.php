<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Charts;

use App\Tenant\Ledger\Charts\Exceptions\InvalidChartTemplate;

/**
 * One journal in a chart template - PF-039, PF-040.
 *
 * A reserved journal names the ONE posting rule allowed into it. `reserved`
 * on its own was a comment: any rule could post into the opening-balances
 * journal, and an opening balance typed by hand six months into the year is
 * indistinguishable from the real one - found a year later by whoever
 * compares this opening to last year's closing.
 */
final readonly class ChartJournal
{
    public function __construct(
        public string $code,
        public string $type,
        public string $nameAr,
        public string $nameEn,
        public bool $reserved,
        public ?string $postingRule,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(string $template, array $row): self
    {
        foreach (['code', 'type', 'name_ar', 'name_en'] as $required) {
            if (($row[$required] ?? '') === '') {
                throw InvalidChartTemplate::because(
                    $template,
                    sprintf('a journal has no %s', $required),
                );
            }
        }

        if (! array_key_exists('reserved', $row)) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf('journal %s does not say whether it is reserved', (string) $row['code']),
            );
        }

        $reserved = (bool) $row['reserved'];
        $rule = ($row['posting_rule'] ?? null) === null ? null : (string) $row['posting_rule'];

        if ($reserved && $rule === null) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf(
                    'journal %s is reserved and names no posting rule, so everything may post into it. '.
                    '"Reserved" with no rule is a label, not a restriction',
                    (string) $row['code'],
                ),
            );
        }

        if (! $reserved && $rule !== null) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf(
                    'journal %s is not reserved but names the rule "%s", which is enforced only on a '.
                    'reserved journal - so it reads as a restriction and is none',
                    (string) $row['code'],
                    $rule,
                ),
            );
        }

        return new self(
            code: (string) $row['code'],
            type: (string) $row['type'],
            nameAr: (string) $row['name_ar'],
            nameEn: (string) $row['name_en'],
            reserved: $reserved,
            postingRule: $rule,
        );
    }
}
