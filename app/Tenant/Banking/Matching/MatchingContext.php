<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

use App\Support\Money\Money;
use App\Tenant\Banking\Models\BankTransaction;
use Illuminate\Support\Carbon;

/**
 * Everything a pass needs, looked up once - PF-156.
 *
 * ## Why the open documents are fetched before any pass runs
 *
 * Four of the six passes compare against the same set. Fetching it per pass
 * would be four queries that grow with the number of open invoices, on the
 * accounts where the engine matters most - and worse, the four could
 * disagree: an invoice issued between pass 1 and pass 5 would be invisible
 * to one and matchable by the other, and the proposal set would describe a
 * state that never existed.
 *
 * One snapshot, one moment.
 */
final class MatchingContext
{
    /** @var list<OpenDocument>|null */
    private ?array $documents = null;

    private ?string $identifiedPartyId = null;

    private bool $partyResolved = false;

    public function __construct(
        public readonly BankTransaction $transaction,
        public readonly string $entityId,
        public readonly Money $amount,
        public readonly OpenDocuments $openDocuments,
        public readonly ReferenceExtraction $references,
        public readonly PartyIdentifiers $parties,
    ) {}

    /**
     * The open documents on the side this transaction can settle.
     *
     * @return list<OpenDocument>
     */
    public function candidates(): array
    {
        return $this->documents ??= $this->openDocuments->forATransaction(
            $this->entityId,
            $this->amount->currency,
            $this->amount,
            null,
        );
    }

    /**
     * The party this transaction is from, where it can be said rather than
     * guessed.
     *
     * From a stored identifier - the counterparty's account or BIC, learned
     * from a confirmed match. Not from the name: a name match is pass 7's
     * job and is never evidence of identity on its own.
     */
    public function identifiedParty(): ?string
    {
        if ($this->partyResolved) {
            return $this->identifiedPartyId;
        }

        $this->partyResolved = true;

        $this->identifiedPartyId = $this->parties->identify(
            $this->entityId,
            $this->transaction->counterparty_account,
            $this->transaction->counterparty_bic,
        );

        return $this->identifiedPartyId;
    }

    public function bookingDate(): Carbon
    {
        return Carbon::parse($this->transaction->booking_date->toDateString());
    }

    public function valueDate(): Carbon
    {
        return Carbon::parse($this->transaction->value_date->toDateString());
    }

    /** Every free-text field a reference could be hiding in. */
    public function remittanceFields(): array
    {
        return [
            $this->transaction->remittance_unstructured,
            $this->transaction->end_to_end_id,
            $this->transaction->bank_reference,
            $this->transaction->counterparty_name,
        ];
    }
}
