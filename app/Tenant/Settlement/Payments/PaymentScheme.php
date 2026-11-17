<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Payments;

use App\Tenant\Settlement\Exceptions\PaymentRefused;

/**
 * What one bank's rulebook permits - PF-176.
 *
 * A thin reader over `config/settlement.php`, so every constraint is
 * declared in one file and asked for by name rather than being a literal
 * somewhere in a serializer. A limit written inline is a limit that gets
 * a second, different value the next time somebody needs it.
 */
final readonly class PaymentScheme
{
    /** @param array<string, mixed> $definition */
    private function __construct(
        public string $key,
        public array $definition,
    ) {}

    /** @throws PaymentRefused */
    public static function named(?string $key): self
    {
        $schemes = (array) config('settlement.schemes', []);

        if ($key === null || $key === '') {
            throw PaymentRefused::because(sprintf(
                'This bank account declares no payment scheme, so there is no rulebook to build a file '.
                'to. Set one on the account: %s. There is deliberately no default - a file built to the '.
                'wrong rulebook passes every check here and is rejected by the bank.',
                implode(', ', array_keys($schemes)),
            ));
        }

        if (! \array_key_exists($key, $schemes)) {
            throw PaymentRefused::because(sprintf(
                'This bank account declares the scheme "%s", which is not one this release knows: %s.',
                $key,
                implode(', ', array_keys($schemes)),
            ));
        }

        return new self($key, (array) $schemes[$key]);
    }

    public function label(): string
    {
        return (string) ($this->definition['label'] ?? $this->key);
    }

    public function format(): string
    {
        return (string) $this->definition['format'];
    }

    public function namespace(): string
    {
        return (string) $this->definition['namespace'];
    }

    /** The one currency this scheme carries, or null where it carries any. */
    public function currency(): ?string
    {
        $currency = $this->definition['currency'] ?? null;

        return $currency === null ? null : (string) $currency;
    }

    public function limit(string $field): ?int
    {
        $limit = $this->definition['limits'][$field] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    public function amountCeiling(): ?string
    {
        $ceiling = $this->definition['amount_ceiling'] ?? null;

        return $ceiling === null ? null : (string) $ceiling;
    }

    /** @return list<string> */
    public function countries(): array
    {
        return array_values(array_map(
            static fn (mixed $country): string => (string) $country,
            (array) ($this->definition['countries'] ?? []),
        ));
    }

    public function reaches(string $country): bool
    {
        return \in_array(mb_strtoupper($country), $this->countries(), true);
    }

    public function requiresIban(): bool
    {
        return (bool) ($this->definition['requires_iban'] ?? true);
    }

    public function requiresBic(): bool
    {
        return (bool) ($this->definition['requires_bic'] ?? false);
    }

    public function businessDaysOnly(): bool
    {
        return (bool) ($this->definition['execution']['business_days_only'] ?? false);
    }

    /**
     * The days this scheme's banks are closed, lower-cased.
     *
     * Declared rather than inferred. Saturday and Sunday in the euro
     * area, Friday and Saturday where the working week starts on Sunday -
     * and a product that inferred it from the country list would be
     * wrong for the first scheme that spans both.
     *
     * @return list<string>
     */
    public function closedDays(): array
    {
        return array_values(array_map(
            static fn (mixed $day): string => mb_strtolower((string) $day),
            (array) ($this->definition['execution']['closed_days'] ?? ['saturday', 'sunday']),
        ));
    }

    public function maxDaysAhead(): int
    {
        return (int) ($this->definition['execution']['max_days_ahead'] ?? 0);
    }
}
