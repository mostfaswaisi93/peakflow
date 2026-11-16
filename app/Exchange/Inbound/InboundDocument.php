<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * What arrived, before it is anything of ours - PF-140, PF-141.
 *
 * ## Who sent it is kept apart from whose bill it is
 *
 * `sender_address` and `sender_electronic_address` are how the sender
 * identified themselves; `supplier_party_id` is who we decided that is. They
 * disagree more often than anybody expects - an agent sends on a supplier's
 * behalf, a group company sends for a subsidiary, a finance system sends
 * from a no-reply address - and overwriting one with the other loses the only
 * evidence of which it was.
 *
 * ## The status is the queue
 *
 * `received` and `quarantined` are what arrived; `rejected` carries a report
 * and a response; `validated` has a supplier and is ready to convert;
 * `awaiting_supplier` passed every rule and nobody knows who sent it.
 *
 * @property string $channel
 * @property string $status
 * @property string|null $document_number
 * @property string|null $match_method
 * @property array<string, mixed>|null $validation_report
 */
final class InboundDocument extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'inbound_documents';

    protected $fillable = [
        'entity_id', 'channel', 'received_at', 'status',
        'sender_address', 'sender_electronic_address', 'sender_scheme', 'subject',
        'document_number', 'document_type', 'document_date', 'currency', 'gross_amount',
        'supplier_party_id', 'match_method', 'match_detail',
        'validation_report', 'rejection_reason', 'response_code',
        'archive_attachment_id', 'content_hash', 'content_type', 'content_bytes',
        'bill_id', 'converted_at', 'converted_by', 'note',
    ];

    protected $casts = [
        'received_at' => 'immutable_datetime',
        'document_date' => 'immutable_date',
        'converted_at' => 'immutable_datetime',
        'validation_report' => 'array',
    ];

    public function isQuarantined(): bool
    {
        return $this->status === 'quarantined';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /** Validated, with a supplier, and not yet a bill. */
    public function isReadyToConvert(): bool
    {
        return $this->status === 'validated' && $this->supplier_party_id !== null;
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }

    public function label(): string
    {
        return (string) ($this->document_number ?? $this->getKey());
    }
}
