<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        $relativePath = $this->relativeApiPath($request);
        $routeKey = "{$method} {$relativePath}";

        if ($this->isPublicRoute($routeKey, $method, $relativePath)) {
            return $next($request);
        }

        $user = $this->resolveAuthenticatedUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->isAuthOnlyRoute($routeKey, $method, $relativePath)) {
            return $next($request);
        }

        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        $permission = $this->resolveRequiredPermission($method, $relativePath, $routeKey);
        if ($permission === null) {
            return $next($request);
        }

        if (!$user->can($permission)) {
            return response()->json([
                'message' => 'Forbidden. Missing required permission.',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user('sanctum') ?? $request->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($token = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken && (!$accessToken->expires_at || $accessToken->expires_at->isFuture())) {
                $tokenUser = $accessToken->tokenable;
                if ($tokenUser instanceof User) {
                    $request->setUserResolver(static fn () => $tokenUser);

                    return $tokenUser;
                }
            }
        }

        if (Auth::guard('web')->check()) {
            $webUser = Auth::guard('web')->user();

            return $webUser instanceof User ? $webUser : null;
        }

        return null;
    }

    private function relativeApiPath(Request $request): string
    {
        $path = trim($request->path(), '/');

        if (str_starts_with($path, 'api/')) {
            return substr($path, 4);
        }

        return $path;
    }

    private function isPublicRoute(string $routeKey, string $method, string $path): bool
    {
        $public = config('api-permissions.public', []);

        if (in_array($routeKey, $public, true)) {
            return true;
        }

        foreach ($public as $pattern) {
            if ($this->matchesPattern($method, $path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isAuthOnlyRoute(string $routeKey, string $method, string $path): bool
    {
        $authOnly = config('api-permissions.auth_only', []);

        if (in_array($routeKey, $authOnly, true)) {
            return true;
        }

        foreach ($authOnly as $pattern) {
            if ($this->matchesPattern($method, $path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRequiredPermission(string $method, string $path, string $routeKey): ?string
    {
        $overrides = config('api-permissions.overrides', []);

        if (isset($overrides[$routeKey])) {
            return $overrides[$routeKey];
        }

        foreach ($overrides as $pattern => $permission) {
            if ($this->matchesPattern($method, $path, $pattern)) {
                return $permission;
            }
        }

        $segment = strtok($path, '/');
        if (!$segment) {
            return null;
        }

        $resources = config('api-permissions.resources', []);
        if (!isset($resources[$segment])) {
            return null;
        }

        $mapping = $resources[$segment];
        $isRead = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        return $isRead ? ($mapping['view'] ?? null) : ($mapping['write'] ?? null);
    }

    private function matchesPattern(string $method, string $path, string $pattern): bool
    {
        [$patternMethod, $patternPath] = array_pad(explode(' ', $pattern, 2), 2, '');
        if (strtoupper($patternMethod) !== $method) {
            return false;
        }

        $regex = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', trim($patternPath, '/')) . '$#';

        return (bool) preg_match($regex, $path);
    }
}
