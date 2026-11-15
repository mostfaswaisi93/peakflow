<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Models\ActivityLog;
use App\Platform\Models\Operator;
use App\Tenant\Identity\Services\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Operator sign-in - PF-036.
 *
 * A separate guard and a separate database from the tenant login, and
 * deliberately NOT a shared controller with a parameter. The two have
 * different populations, different stores, and different consequences, and a
 * single controller branching on a flag is how an operator ends up
 * authenticated against a customer's user table because the branch was
 * inverted.
 */
final class OpsLoginController
{
    public function __construct(private readonly LoginThrottle $throttle) {}

    public function show(): View
    {
        return view('ops.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = (string) $credentials['email'];
        $ip = (string) $request->ip();

        $retryAfter = $this->throttle->retryAfter('ops:'.$email, $ip);

        if ($retryAfter !== null) {
            throw ValidationException::withMessages([
                'email' => __('يرجى الانتظار :seconds ثانية قبل المحاولة مرة أخرى.', ['seconds' => $retryAfter]),
            ]);
        }

        $operator = Operator::query()->where('email', $email)->first();

        $matches = $operator === null
            ? Hash::check((string) $credentials['password'], Hash::make('ops-timing-equalizer'))
            : Hash::check((string) $credentials['password'], (string) $operator->getAuthPassword());

        // No `active` flag on operators: departure is a soft delete, and
        // `Operator::query()` already excludes trashed rows, so a removed
        // operator is simply not found. Checking a column that does not exist
        // would have thrown under strict mode on the first wrong password.
        if ($operator === null || ! $matches) {
            $this->throttle->recordFailure('ops:'.$email, $ip);

            ActivityLog::create([
                'category' => 'identity',
                'action' => 'ops.session.refused',
                'context' => ['email' => $email, 'user_agent' => $request->userAgent()],
                'ip_address' => $ip,
            ]);

            throw ValidationException::withMessages(['email' => __('بيانات الدخول غير صحيحة.')]);
        }

        $this->throttle->recordSuccess('ops:'.$email);

        $request->session()->regenerate();
        auth()->guard('ops')->login($operator);
        $request->session()->put('authenticated_at', now()->toIso8601String());

        ActivityLog::create([
            'operator_id' => $operator->getKey(),
            'category' => 'identity',
            'action' => 'ops.session.started',
            'context' => ['email' => $operator->email],
            'ip_address' => $ip,
        ]);

        return redirect()->intended(route('ops.tenants'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $operator = auth()->guard('ops')->user();

        if ($operator instanceof Operator) {
            ActivityLog::create([
                'operator_id' => $operator->getKey(),
                'category' => 'identity',
                'action' => 'ops.session.ended',
                'context' => ['email' => $operator->email],
            ]);
        }

        auth()->guard('ops')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('ops.login');
    }
}
