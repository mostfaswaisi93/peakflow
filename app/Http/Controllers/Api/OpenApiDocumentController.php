<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The contract, published - PF-217.
 *
 * ## Why the document is served and not generated
 *
 * `docs/api/openapi.yaml` is hand-authored and committed, and this endpoint
 * returns that file byte for byte. It does not reflect over the routes.
 *
 * An API whose documentation is generated from its implementation documents
 * whatever the implementation happens to do, including its mistakes: a field
 * accidentally nullable is documented as nullable, a status code returned by
 * a forgotten branch becomes part of the contract, and the document can never
 * disagree with the code - which is the only way a document is useful.
 *
 * The agreement is asserted instead, in both directions, by
 * `ApiContractRules`. So the file is the contract, the test is the proof, and
 * this endpoint is the delivery.
 *
 * ## Unauthenticated, deliberately
 *
 * An integrator reads the contract BEFORE they have a token - that is what
 * they are reading it in order to obtain. Requiring one would mean the first
 * question every integration asks is answered by a 401, and the usual
 * workaround is somebody emailing a copy of the file, which is then the
 * version they build against for two years.
 *
 * The document describes shapes, not data: no tenant, no entity, no figure.
 * It is also the one route on this API with no `X-Tenant`, for the same
 * reason - there is nothing tenant-specific in it to resolve.
 */
final class OpenApiDocumentController
{
    /**
     * @no-permission-required The document is the public contract; it contains
     *     no tenant data and is read before a token exists.
     */
    public function __invoke(Request $request): Response
    {
        $path = base_path('docs/api/openapi.yaml');

        if (! is_file($path)) {
            /*
             | Not a 404.
             |
             | The contract missing from a deployment is a broken deployment,
             | not a missing resource - a 404 here would send an integrator
             | looking for the right URL, which they already have. The
             | contract test makes this unreachable in a healthy build.
             */
            return new Response(
                'The OpenAPI document is not present in this deployment.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $document = (string) file_get_contents($path);

        return new Response($document, Response::HTTP_OK, [
            /*
             | `application/yaml`, registered by RFC 9512, with the OpenAPI
             | version as a parameter so a client can tell 3.1 from 3.0
             | without parsing - they differ in how they express nullability
             | and examples, which is exactly what a generator needs to know
             | before it starts.
             */
            'Content-Type' => 'application/yaml; charset=utf-8',
            /*
             | A strong validator over the bytes served, so a client polling
             | for contract changes transfers them once.
             |
             | The hash of the file and not its mtime: a deploy rewrites every
             | mtime, and a client that re-downloads the whole contract on
             | every release learns nothing from having an ETag at all.
             */
            'ETag' => '"'.hash('sha256', $document).'"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
