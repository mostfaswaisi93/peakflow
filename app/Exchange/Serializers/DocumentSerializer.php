<?php

declare(strict_types=1);

namespace App\Exchange\Serializers;

use App\Exchange\Semantics\SemanticInvoice;

/**
 * One syntax, from the one model - PF-112, PF-113.
 *
 * The interface is deliberately this small. A serializer takes a semantic
 * model and returns bytes: it does not load anything, does not decide which
 * profile applies, does not validate and does not sign. Each of those was
 * decided before it ran, and a serializer that re-decided any of them would
 * be a second place where the answer lives.
 *
 * Two implementations share nothing but the model, which is what proves the
 * model is genuinely syntax-independent rather than a UBL document with the
 * element names renamed.
 */
interface DocumentSerializer
{
    /** The syntax, for the archive and for an error message: `ubl`, `cii`. */
    public function syntax(): string;

    /** The media type the bytes should be transmitted as. */
    public function mediaType(): string;

    public function serialize(SemanticInvoice $invoice): string;
}
