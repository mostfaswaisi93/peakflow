<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

/**
 * One transaction inside a bank entry - PF-150.
 *
 * A payment run of fifty supplier payments is one debit on the statement and
 * fifty things to match. The entry carries the movement; these carry the
 * references, the beneficiaries and the amounts that make matching possible.
 *
 * Nothing sums these into a balance. That is deliberate: the entry is what
 * the bank took, and a detail that counted toward the account's balance
 * would double it.
 */
final readonly class ParsedDetail
{
    /** @param array<string, mixed>|null $remittanceStructured */
    public function __construct(
        public string $amount,
        public string $currency,
        public ?string $counterpartyName = null,
        public ?string $counterpartyAccount = null,
        public ?string $counterpartyBic = null,
        public ?string $remittanceUnstructured = null,
        public ?array $remittanceStructured = null,
        public ?string $endToEndId = null,
        public ?string $mandateId = null,
        public ?string $instructionId = null,
        public ?string $instructedAmount = null,
        public ?string $instructedCurrency = null,
        public ?string $exchangeRate = null,
        public ?string $chargesAmount = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'counterparty_name' => $this->counterpartyName,
            'counterparty_account' => $this->counterpartyAccount,
            'counterparty_bic' => $this->counterpartyBic,
            'remittance_unstructured' => $this->remittanceUnstructured,
            'remittance_structured' => $this->remittanceStructured,
            'end_to_end_id' => $this->endToEndId,
            'mandate_id' => $this->mandateId,
            'instruction_id' => $this->instructionId,
            'instructed_amount' => $this->instructedAmount,
            'instructed_currency' => $this->instructedCurrency,
            'exchange_rate' => $this->exchangeRate,
            'charges_amount' => $this->chargesAmount,
        ];
    }
}
