<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Http\Controllers;

use App\Tenant\Reporting\Overview\OverviewQuery;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The Overview - UX-032.
 *
 * The page every user lands on after signing in, so the PAGE is open to
 * anyone with a role in the entity and the FIGURES are not: they need
 * `report.view`. Someone without it still gets a page that says where they
 * are and why the numbers are absent, rather than a 403 on the first screen
 * after sign-in.
 */
final class OverviewController
{
    /**
     * @no-permission-required: the landing page after sign-in. Its financial
     * figures are gated on `report.view` in the body below - the page itself
     * shows only the entity the user is already authorized to act in.
     */
    public function __invoke(Request $request, EntityContext $context, OverviewQuery $query): View
    {
        $entity = $context->entity();
        $user = $request->user();

        $overview = $entity !== null && $user !== null && $user->can('report.view')
            ? $query->for($entity)
            : null;

        return view('app.home', [
            'entity' => $entity,
            'overview' => $overview,
        ]);
    }
}
