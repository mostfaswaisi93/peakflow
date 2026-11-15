<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Switches which entity the user is acting in - PF-027.
 *
 * ## The claim is validated here AND re-checked on every later request
 *
 * This writes the chosen entity to the session, and `ResolveEntity` reads it
 * back on every subsequent request and re-checks the user's roles then. Both,
 * deliberately: this is where a bad choice is refused with a message, and
 * that is where a revoked role takes effect without waiting for another
 * switch.
 *
 * Trusting the session alone would mean a user whose access to a subsidiary
 * was removed keeps posting into it until they happen to switch again.
 */
final class EntitySwitchController
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @no-permission-required: switching is authorized by the GRANTS rather
     * than by a permission - the entity must appear in entitiesForSwitcher(),
     * which reads the user's roles and excludes closed entities, and the
     * refusal below is that check. There is no `entity.switch` permission to
     * hold, because the right to act in an entity IS holding a role in it.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $requested = (string) $request->input('entity');

        // Validated against the grants, not against what the form offered -
        // the form is a hint the user controls, and a hidden option in it is
        // not authorization.
        $entity = $user->entitiesForSwitcher()->firstWhere('id', $requested);

        if ($entity === null) {
            $this->audit->refusal(
                category: 'tenancy',
                action: 'entity.switch.refused',
                subjectType: 'Entity',
                subjectId: $requested,
                reason: 'the user holds no role in that entity, or it is not active',
                actor: $user,
            );

            throw ValidationException::withMessages([
                'entity' => __('لا تملك صلاحية في هذه الشركة.'),
            ]);
        }

        $previous = $request->session()->get('entity_id');

        $request->session()->put('entity_id', $entity->id);

        // Remembered across devices, so signing in elsewhere lands in the
        // company they were last working in.
        $user->forceFill(['last_entity_id' => $entity->id])->saveQuietly();

        // Permissions are per entity, so anything resolved for the previous
        // one is now wrong.
        $user->forgetResolvedPermissions();

        $this->audit->record(
            category: 'tenancy',
            action: 'entity.switched',
            subjectType: 'Entity',
            subjectId: (string) $entity->id,
            subjectLabel: (string) $entity->legal_name,
            before: ['entity_id' => $previous],
            after: ['entity_id' => $entity->id],
            entityId: (string) $entity->id,
            actor: $user,
        );

        // Never back() - arriving from the chooser, back() is the chooser,
        // and the user would be asked again having just answered.
        return redirect()
            ->intended(route('overview'))
            // Read by the live region in the layout, on the page they land
            // on - an announcement rendered on the page they are leaving is
            // never read.
            ->with('entity_switched', $entity->legal_name);
    }
}
