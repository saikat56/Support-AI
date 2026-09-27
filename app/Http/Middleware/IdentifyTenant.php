<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->tenant_id) {
            return response()->json([
                'message' => 'Tenant not assigned.',
            ], 403);
        }

        app()->instance('currentTenant', $user->tenant);

        return $next($request);
    }
}
