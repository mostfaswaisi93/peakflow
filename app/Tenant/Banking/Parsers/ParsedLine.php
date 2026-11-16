<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use Illuminate\Support\Carbon;

/**
 * One line of a statement, as the file gave it - PF-150 to PF-154.
 *
 * ## Signed once, here
 *
 * Every format states direction separately from magnitude: CAMT has
 * `CdtDbtInd`, MT940 has `C`/`D` in `:61:`, OFX has `TRNTYPE`, a CSV has
 * either a sign or two columns. Each parser applies it, and everything
 * downstream sees one signed number.
 *
 * The alternative - carrying the magnitude and an indicator - puts the sign
 * in every consumer, and the one that forgets reports a refund as a payment.
 *
 * ## Booked or pending
 *
 * A CAMT.053 can carry pending entries alongside booked ones, and they are
 * not in the closing balance. Importing one as an ordinary line makes the
 * statement's lines disagree with its own stated movement, and the import is
 * then refused - which looks like a parser bug and is not. A pending line is
 * provisional: it feeds the cash position and reconciles nothing.
 */
final readonly class ParsedLine
{
    /**
     * @param  list<ParsedDetail>  $details  what the bank says was inside it
     * @param  array<string, mixed>|null  $remittanceStructured
     */
    public function __construct(
        public Carbon $bookingDate,
        public Carbon $valueDate,
        public string $amount,
        public string $currency,
        public ?string $counterpartyName = null,
        public ?string $counterpartyAccount = null,
        public ?string $counterpartyBic = null,
        public ?string $remittanceUnstructured = null,
        public ?array $remittanceStructured = null,
        public ?string $bankReference = null,
        public ?string $endToEndId = null,
        public ?string $mandateId = null,
        public ?string $codeDomain = null,
        public ?string $codeFamily = null,
        public ?string $codeSubfamily = null,
        public ?string $bankCodeProprietary = null,
        public bool $isReversal = false,
        public ?string $reversesReference = null,
        public ?string $chargesAmount = null,
        public ?string $instructedAmount = null,
        public ?string $instructedCurrency = null,
        public ?string $exchangeRate = null,
        public bool $isPending = false,
        public bool $isBatch = false,
        public array $details = [],
        public ?array $sourcePayload = null,
    ) {}

    /**
     * The shape {@see \App\Tenant\Banking\Services\StatementImport} writes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'booking_date' => $this->bookingDate->toDateString(),
            'value_date' => $this->valueDate->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'counterparty_name' => $this->counterpartyName,
            'counterparty_account' => $this->counterpartyAccount,
            'counterparty_bic' => $this->counterpartyBic,
            'remittance_unstructured' => $this->remittanceUnstructured,
            'remittance_structured' => $this->remittanceStructured,
            'bank_reference' => $this->bankReference,
            'end_to_end_id' => $this->endToEndId,
            'mandate_id' => $this->mandateId,
            'code_domain' => $this->codeDomain,
            'code_family' => $this->codeFamily,
            'code_subfamily' => $this->codeSubfamily,
            'bank_code_proprietary' => $this->bankCodeProprietary,
            'is_reversal' => $this->isReversal,
            'reverses_reference' => $this->reversesReference,
            'charges_amount' => $this->chargesAmount,
            'instructed_amount' => $this->instructedAmount,
            'instructed_currency' => $this->instructedCurrency,
            'exchange_rate' => $this->exchangeRate,
            'is_pending' => $this->isPending,
            'is_batch' => $this->isBatch,
            'details' => array_map(
                static fn (ParsedDetail $detail): array => $detail->toArray(),
                $this->details,
            ),
            'source_payload' => $this->sourcePayload,
        ];
    }
}
