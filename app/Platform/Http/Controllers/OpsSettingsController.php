<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Models\ActivityLog;
use App\Platform\Models\Operator;
use App\Platform\Models\Plan;
use App\Platform\Models\PlatformSetting;
use App\Platform\Services\PlatformCeilings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Plans and the platform-wide risk ceilings - PF-036.
 *
 * ## Raising a ceiling is logged like any other privileged act
 *
 * A ceiling is the furthest any customer may go, so moving one changes what
 * is possible for every workspace at once. It takes a written reason, the
 * before and after are recorded, and the row names the operator.
 *
 * Lowering one is recorded for the same reason in reverse: a customer whose
 * exception stopped being grantable deserves an answer better than "it must
 * have always been like that".
 */
final class OpsSettingsController
{
    public function __construct(private readonly PlatformCeilings $ceilings) {}

    public function show(): View
    {
        return view('ops.settings', [
            'plans' => Plan::query()->orderBy('sort_order')->get(),
            'ceilings' => $this->ceilings->ceilings()->sortKeys(),
            'settings' => $this->ceilings->all()
                ->reject(static fn (PlatformSetting $s): bool => (bool) $s->is_ceiling)
                ->sortKeys(),
        ]);
    }

    /**
     * @no-permission-required: KNOWN LIMITATION, the same one as the tenant
     * lifecycle - the operator plane has no role model, so every operator is
     * a full administrator. Recorded as open in PF-036 rather than hidden.
     * What exists: auth:ops, a mandatory written reason, and an activity-log
     * row naming who changed what.
     */
    public function update(Request $request, PlatformSetting $setting): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $operator = auth()->guard('ops')->user();
        $from = $setting->value;

        $setting->update(['value' => (string) $validated['value']]);
        $this->ceilings->flush();

        ActivityLog::create([
            'operator_id' => $operator instanceof Operator ? $operator->getKey() : null,
            'category' => 'platform',
            'action' => $setting->is_ceiling ? 'ceiling.changed' : 'setting.changed',
            'context' => [
                'key' => $setting->key,
                'from' => $from,
                'to' => $setting->value,
                'reason' => $validated['reason'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('ops_notice', __('تم تحديث الإعداد.'));
    }
}
