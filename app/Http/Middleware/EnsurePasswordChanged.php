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
    // Routes allowed even while password_changed_at is null — without these,
    // the redirect loops and the user can't bail out via logout.
    private const EXEMPT_ROUTE_PATTERNS = [
        'filament.group.auth.logout',
        'groupe.set-password*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('group')->user();

        if ($user === null || ! $user->mustChangePassword()) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT_ROUTE_PATTERNS)) {
            return $next($request);
        }

        return redirect()->route('groupe.set-password.show');
    }
}
