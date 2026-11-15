<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

/**
 * The outcome of walking the audit chain.
 *
 * `intact` deliberately does not say "authentic". The chain proves the log has
 * not been edited or excised; it cannot prove who wrote a record, because
 * anyone able to write to the table can extend the chain correctly.
 */
final readonly class AuditChainVerdict
{
    private function __construct(
        public bool $isIntact,
        public int $recordsChecked,
        public ?string $firstDivergenceId = null,
        public ?int $firstDivergenceIndex = null,
        public ?string $explanation = null,
    ) {}

    public static function intact(int $recordsChecked): self
    {
        return new self(true, $recordsChecked);
    }

    public static function broken(int $recordsChecked, ?string $id, int $index, string $explanation): self
    {
        return new self(false, $recordsChecked, $id, $index, $explanation);
    }

    public function summary(): string
    {
        if ($this->isIntact) {
            return sprintf(
                '%d audit record(s) form an unbroken chain. This shows the log has not been edited or '.
                'excised; it does not establish who wrote any record.',
                $this->recordsChecked,
            );
        }

        return sprintf(
            'The audit chain diverges at index %d%s: %s',
            (int) $this->firstDivergenceIndex,
            $this->firstDivergenceId === null ? '' : ' (record '.$this->firstDivergenceId.')',
            (string) $this->explanation,
        );
    }
}
