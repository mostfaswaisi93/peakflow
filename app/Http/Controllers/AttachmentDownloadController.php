<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Attachments\AttachmentRefused;
use App\Support\Attachments\AttachmentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a stored attachment, once, to whoever holds the link - PF-073.
 *
 * ## Why there is no permission check here
 *
 * The signature IS the authorization. The link is minted by
 * {@see AttachmentStore::signedUrl()} for somebody who had already been
 * authorized to see the document, it expires in minutes, and it is served
 * from an origin with no session.
 *
 * That is deliberate rather than a gap: the download origin has no cookie
 * for this application, so there is no session to authorize against. A
 * permission check here would have to authenticate the request, which means
 * a cookie on the download origin, which is exactly the thing a separate
 * origin exists to avoid.
 *
 * ## The headers are the other half
 *
 * `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`
 * mean a stored HTML or SVG file is downloaded rather than rendered, and a
 * `Content-Security-Policy` of `sandbox` stops anything that is rendered
 * anyway from running script or reaching the network. Three layers, because
 * each of them has been bypassed somewhere.
 */
final class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, string $tenant, string $entity, string $attachment): Response
    {
        $store = app(AttachmentStore::class);

        DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$entity]);

        try {
            $contents = $store->read($entity, $attachment);
        } catch (AttachmentRefused $e) {
            /*
             | 404, not 403.
             |
             | A link whose file turned out to be infected, or whose row has
             | gone, tells the holder nothing about which. Distinguishing
             | them would let somebody with an expired link learn whether a
             | particular attachment exists.
             */
            abort(404, $e->getMessage());
        }

        $row = DB::connection('tenant')->table('attachments as a')
            ->join('attachment_blobs as b', 'b.id', '=', 'a.attachment_blob_id')
            ->where('a.entity_id', $entity)
            ->where('a.id', $attachment)
            ->select(['a.declared_name', 'b.content_type', 'b.byte_size'])
            ->firstOrFail();

        return response($contents, 200, [
            'Content-Type' => (string) $row->content_type,
            'Content-Length' => (string) $row->byte_size,
            'Content-Disposition' => $this->disposition((string) $row->declared_name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * `Content-Disposition`, with the name quoted and escaped both ways.
     *
     * An Arabic filename is not ASCII, so the `filename*` form carries it
     * percent-encoded as RFC 5987 requires, and a plain `filename` is kept
     * for readers that do not implement it. A quote or a newline in the name
     * would otherwise end the header and start another one, which is header
     * injection through a filename.
     */
    private function disposition(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'attachment';
        $ascii = str_replace(['"', '\\', "\r", "\n"], '_', $ascii);

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $ascii,
            rawurlencode($name),
        );
    }
}
