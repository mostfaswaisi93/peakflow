<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Documents\NamesReceivableDocuments;
use App\Tenant\Sales\Enums\DocumentType;

/**
 * Sales' answer to which documents a customer can owe - PF-090.
 *
 * A two-line adapter, and that is all it should be. The decision lives in
 * {@see DocumentType::createsAReceivable()}, where a `match` with no
 * `default` means a type added later will not compile until somebody has
 * decided which side of the question it falls on.
 *
 * This exists so `Banking` can ask without depending on `Sales`. See
 * {@see NamesReceivableDocuments} for why that wall is there.
 */
final class ReceivableDocumentTypes implements NamesReceivableDocuments
{
    /** @return list<string> */
    public function receivableCodes(): array
    {
        return DocumentType::receivableCodes();
    }
}
