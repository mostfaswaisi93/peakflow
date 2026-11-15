<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A classification code AND the scheme it belongs to - PF-064.
 *
 * ## Both, always
 *
 * EN 16931 requires the scheme identifier beside the code: BT-158-1 beside
 * BT-158. `43232408` means one thing in UNSPSC and another in CPV, so a bare
 * code on an invoice cannot be resolved by the receiving system - which
 * rejects the document rather than querying it.
 *
 * The version is kept where the scheme has one. UNSPSC is revised and codes
 * are retired; an invoice issued under version 23 is a statement about
 * version 23, and re-reading it against version 26 can find no code at all.
 *
 * @property string $scheme
 * @property string $code
 */
final class ItemClassification extends TenantModel
{
    protected $table = 'item_classifications';

    protected $fillable = ['item_id', 'scheme', 'code', 'scheme_version'];

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The coded pair as an e-invoice carries it. */
    public function asPair(): string
    {
        return sprintf('%s:%s', $this->scheme, $this->code);
    }
}
