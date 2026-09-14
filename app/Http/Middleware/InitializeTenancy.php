<?php

namespace App\Http\Middleware;

use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InitializeTenancy
{
    public function __construct(
        private readonly TenantManager $tenants,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenants->enabled()) {
            return $next($request);
        }

        // Health checks and platform maintenance stay on the default connection.
        if ($request->is('up') || $request->is('api/health')) {
            return $next($request);
        }

        $slug = $this->tenants->resolveSlugFromRequest($request);
        if ($slug === null || $slug === '') {
            return response()->json([
                'message' => 'Tenant is required. Send the X-Tenant header or use a tenant subdomain.',
            ], 400);
        }

        try {
            $this->tenants->initializeBySlug($slug);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        return $next($request);
    }
}
