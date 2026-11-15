<?php

declare(strict_types=1);

namespace App\Support\Numbering;

use App\Support\Numbering\Exceptions\InvalidNumberFormat;
use Illuminate\Support\Carbon;

/**
 * The shape of a document number - PF-071.
 *
 * ## Why a template and not a prefix plus a width
 *
 * A prefix and a padding width can express `INV-00001` and nothing else.
 * Real requirements are not like that:
 *
 *     INV-{YYYY}-{seq:6}          a year in the middle
 *     {entity}/{YY}{MM}/{seq:4}   the issuer and the month, as several
 *                                 jurisdictions require on a tax invoice
 *     CN-{branch}-{seq:5}         a branch, where numbering is per branch
 *
 * None of those is reachable by concatenating a prefix, and every system
 * that tried ends up with a prefix column containing a date somebody has to
 * remember to change each January.
 *
 * ## The placeholders
 *
 *   {seq} {seq:n}  the sequence number, optionally zero-padded to n digits.
 *                  REQUIRED - a format without it is not a numbering series.
 *   {YYYY} {YY}    the year of the document DATE, not of today
 *   {MM} {DD}      month and day, zero-padded
 *   {entity}       the entity's code
 *   {branch}       the branch code, where the series has one
 *   {key}          the series key, e.g. `INV`
 *
 * ## Why the year comes from the document date
 *
 * A document dated 31 December and entered on 2 January belongs to the
 * December series. Taking the year from the clock puts it in the next year's
 * sequence, which breaks that sequence's gaplessness at both ends - the one
 * property the whole numbering mechanism exists to provide.
 */
final readonly class NumberFormat
{
    /** Matches a placeholder and captures its name and optional width. */
    private const PLACEHOLDER = '/\{(seq|YYYY|YY|MM|DD|entity|branch|key)(?::(\d+))?\}/';

    private function __construct(public string $template) {}

    /**
     * @throws InvalidNumberFormat
     */
    public static function of(string $template): self
    {
        $template = trim($template);

        if ($template === '') {
            throw InvalidNumberFormat::empty();
        }

        if (preg_match('/\{seq(?::\d+)?\}/', $template) !== 1) {
            throw InvalidNumberFormat::withoutSequence($template);
        }

        if (preg_match_all('/\{seq(?::\d+)?\}/', $template) > 1) {
            throw InvalidNumberFormat::withTwoSequences($template);
        }

        /*
         | Every brace must belong to a known placeholder.
         |
         | `INV-{YEAR}-{seq:4}` would otherwise render literally as
         | "INV-{YEAR}-0001" on every invoice the entity ever issues, and
         | the first person to notice is a customer.
         */
        $withoutPlaceholders = (string) preg_replace(self::PLACEHOLDER, '', $template);

        if (str_contains($withoutPlaceholders, '{') || str_contains($withoutPlaceholders, '}')) {
            throw InvalidNumberFormat::unknownPlaceholder($template);
        }

        $padding = self::sequenceWidth($template);

        if ($padding > 12) {
            throw InvalidNumberFormat::sequenceTooWide($padding);
        }

        return new self($template);
    }

    /**
     * Validate the format together with the series' reset policy.
     *
     * The combination `resets_annually` with no year in the format is not a
     * malformed template - nothing about it looks wrong on its own - and it
     * issues the same numbers again every January. It can only be caught
     * where both facts are known.
     *
     * @throws InvalidNumberFormat
     */
    public static function forSeries(string $template, bool $resetsAnnually): self
    {
        $format = self::of($template);

        if ($resetsAnnually && ! $format->carriesYear()) {
            throw InvalidNumberFormat::resetsWithoutAYear($template);
        }

        return $format;
    }

    /**
     * Render a number.
     *
     * @param  array{entity?: string, branch?: string, key?: string}  $context
     */
    public function render(int $sequence, Carbon $date, array $context = []): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $m): string => match ($m[1]) {
                'seq' => isset($m[2])
                    ? str_pad((string) $sequence, (int) $m[2], '0', STR_PAD_LEFT)
                    : (string) $sequence,
                'YYYY' => $date->format('Y'),
                'YY' => $date->format('y'),
                'MM' => $date->format('m'),
                'DD' => $date->format('d'),
                'entity' => (string) ($context['entity'] ?? ''),
                'branch' => (string) ($context['branch'] ?? ''),
                'key' => (string) ($context['key'] ?? ''),
                default => $m[0],
            },
            $this->template,
        );
    }

    /**
     * What the next few numbers would look like - PF-071's preview.
     *
     * The requirement exists because a format is frozen the moment the
     * series issues its first document, so the only chance to notice that
     * `{seq:3}` runs out in October is before anything has been issued.
     *
     * @param  array{entity?: string, branch?: string, key?: string}  $context
     * @return list<string>
     */
    public function preview(Carbon $date, array $context = [], int $from = 1, int $count = 3): array
    {
        $samples = [];

        for ($i = 0; $i < $count; $i++) {
            $samples[] = $this->render($from + $i, $date, $context);
        }

        return $samples;
    }

    /**
     * The highest sequence this format can express without widening.
     *
     * Null when the sequence is unpadded, which has no ceiling - and is also
     * the form that produces `INV-9`, `INV-10`, `INV-100` sorting as text in
     * an order nobody expects.
     */
    public function ceiling(): ?int
    {
        $width = self::sequenceWidth($this->template);

        return $width === 0 ? null : (int) str_repeat('9', $width);
    }

    public function padding(): int
    {
        return self::sequenceWidth($this->template);
    }

    /** Whether rendering needs a branch code. */
    public function needsBranch(): bool
    {
        return str_contains($this->template, '{branch}');
    }

    /**
     * Whether the format distinguishes years on its own.
     *
     * Used by the series to catch a combination that silently produces
     * duplicates: a series that resets every year and a format with no year
     * in it issues `INV-00001` again every January.
     */
    public function carriesYear(): bool
    {
        return str_contains($this->template, '{YYYY}') || str_contains($this->template, '{YY}');
    }

    public function __toString(): string
    {
        return $this->template;
    }

    private static function sequenceWidth(string $template): int
    {
        return preg_match('/\{seq:(\d+)\}/', $template, $m) === 1 ? (int) $m[1] : 0;
    }
}
