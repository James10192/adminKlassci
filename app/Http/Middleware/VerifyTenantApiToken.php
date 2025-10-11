<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTenantApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get token from Authorization header or query parameter
        $token = $request->bearerToken() ?? $request->query('token');

        if (!$token) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API token is required. Provide it via Authorization: Bearer {token} header or ?token={token} query parameter.',
            ], 401);
        }

        // Find tenant by API token
        $tenant = Tenant::where('api_token', $token)
            ->where('status', '!=', 'deleted')
            ->first();

        if (!$tenant) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API token',
            ], 401);
        }

        // Attach tenant to request for use in controller
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
