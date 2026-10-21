<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyModuleService;
use App\Support\LoginSessionToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiPermission
{
    public function __construct(
        private readonly CompanyModuleService $companyModuleService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        $relativePath = $this->relativeApiPath($request);
        $routeKey = "{$method} {$relativePath}";

        if ($method === 'POST' && $relativePath === 'logout') {
            return $next($request);
        }

        if ($this->isPublicRoute($routeKey, $method, $relativePath)) {
            return $next($request);
        }

        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($moduleResponse = $this->denyIfModuleDisabled($relativePath)) {
            return $moduleResponse;
        }

        if ($this->isAuthOnlyRoute($routeKey, $method, $relativePath)) {
            return $next($request);
        }

        $permission = $this->resolveRequiredPermission($method, $relativePath, $routeKey);
        if ($permission === null) {
            return $next($request);
        }

        $permissions = is_array($permission) ? $permission : [$permission];
        $allowed = false;
        foreach ($permissions as $name) {
            if (is_string($name) && $name !== '' && $user->can($name)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            if ($this->allowsSelfEmployeeRead($request, $user, $method, $relativePath)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Forbidden. Missing required permission.',
                'permission' => count($permissions) === 1 ? $permissions[0] : $permissions,
            ], 403);
        }

        return $next($request);
    }

    private function denyIfModuleDisabled(string $relativePath): ?Response
    {
        $required = $this->companyModuleService->requiredModulesForApiPath($relativePath);
        if ($required === null) {
            return null;
        }

        if ($this->companyModuleService->isAnyModuleEnabled($required)) {
            return null;
        }

        return response()->json([
            'message' => 'Forbidden. Required application module is disabled.',
            'modules' => $required,
        ], 403);
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $plain = $request->bearerToken();
        if (is_string($plain) && $plain !== '') {
            $accessToken = PersonalAccessToken::findToken($plain);
            if (! $accessToken instanceof PersonalAccessToken || ! $this->accessTokenIsCurrent($accessToken)) {
                return null;
            }

            $tokenUser = $accessToken->tokenable;
            if (! $tokenUser instanceof User) {
                return null;
            }

            $tokenUser->withAccessToken($accessToken);
            LoginSessionToken::refresh($accessToken);
            $this->bindAuthenticatedUser($request, $tokenUser);

            return $tokenUser;
        }

        $user = $request->user('sanctum') ?? $request->user();
        if ($user instanceof User) {
            $current = $user->currentAccessToken();
            if ($current instanceof PersonalAccessToken && ! $this->accessTokenIsCurrent($current)) {
                return null;
            }
            if ($current instanceof PersonalAccessToken) {
                LoginSessionToken::refresh($current);
            }
            $this->bindAuthenticatedUser($request, $user);

            return $user;
        }

        if (Auth::guard('web')->check()) {
            $webUser = Auth::guard('web')->user();
            if ($webUser instanceof User) {
                $this->bindAuthenticatedUser($request, $webUser);

                return $webUser;
            }
        }

        return null;
    }

    /**
     * Ensure $request->user() (default guard) resolves for the rest of the request.
     * Sanctum may authenticate via the "sanctum" guard while the default remains unset.
     */
    private function accessTokenIsCurrent(PersonalAccessToken $accessToken): bool
    {
        $expiresAt = $accessToken->expires_at;

        return ! $expiresAt instanceof \DateTimeInterface || $expiresAt->isFuture();
    }

    private function bindAuthenticatedUser(Request $request, User $user): void
    {
        $request->setUserResolver(static fn () => $user);
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

    /**
     * @return string|list<string>|null
     */
    private function resolveRequiredPermission(string $method, string $path, string $routeKey): string|array|null
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
        if (! $segment) {
            return null;
        }

        $resources = config('api-permissions.resources', []);
        if (! isset($resources[$segment])) {
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

        $regex = '#^'.preg_replace('/\{[^}]+\}/', '[^/]+', trim($patternPath, '/')).'$#';

        return (bool) preg_match($regex, $path);
    }

    private function allowsSelfEmployeeRead(Request $request, User $user, string $method, string $path): bool
    {
        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') {
            return false;
        }

        $ownEmployeeId = $user->employee()->value('id');
        if (! $ownEmployeeId) {
            return false;
        }

        if ($path === 'employees/me') {
            return true;
        }

        if (preg_match('#^employees/by-user/([^/]+)$#', $path, $matches) === 1) {
            return (string) $matches[1] === (string) $user->id;
        }

        if (preg_match('#^employees/([^/]+)$#', $path, $matches) === 1) {
            return (string) $matches[1] === (string) $ownEmployeeId;
        }

        if (preg_match('#^employees/([^/]+)/hours-bank$#', $path, $matches) === 1) {
            return (string) $matches[1] === (string) $ownEmployeeId;
        }

        $segment = strtok($path, '/');
        if (! is_string($segment) || $segment === '') {
            return false;
        }

        $catalogs = config('api-permissions.self_employee_catalogs', []);
        if (is_array($catalogs) && in_array($segment, $catalogs, true)) {
            return true;
        }

        $scoped = config('api-permissions.self_employee_resources', []);
        if (! is_array($scoped) || ! in_array($segment, $scoped, true)) {
            return false;
        }

        $requestedEmployeeId = $request->input('employeeId', $request->input('employee_id'));

        return $requestedEmployeeId !== null && (string) $requestedEmployeeId === (string) $ownEmployeeId;
    }
}
