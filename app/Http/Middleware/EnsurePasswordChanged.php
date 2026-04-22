<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercepts every authenticated /groupe/* request and redirects to the
 * set-password page until the member rotates the admin-generated password.
 *
 * Exempts the set-password routes themselves (otherwise we'd loop) and the
 * logout route (so a user can bail out without being trapped).
 */
class EnsurePasswordChanged
{
    /**
     * Routes allowed even when the user still has password_changed_at = null.
     * Named routes resolved via `routeIs()` which supports wildcards — the
     * Filament logout endpoint is registered as `filament.group.auth.logout`.
     */
    private const EXEMPT_ROUTE_PATTERNS = [
        'filament.group.auth.logout',
        'groupe.set-password*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('group')->user();

        if ($user === null || ! method_exists($user, 'mustChangePassword')) {
            return $next($request);
        }

        if (! $user->mustChangePassword()) {
            return $next($request);
        }

        foreach (self::EXEMPT_ROUTE_PATTERNS as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        return redirect()->route('groupe.set-password.show');
    }
}
