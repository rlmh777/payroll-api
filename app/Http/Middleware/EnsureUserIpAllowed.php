<?php

namespace App\Http\Middleware;

use App\Exceptions\LoginAccessDeniedException;
use App\Models\User;
use App\Services\UserIpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIpAllowed
{
    public function __construct(
        private readonly UserIpAllowlist $ips,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        try {
            $this->ips->assertAllowed($user, $request);
        } catch (LoginAccessDeniedException $exception) {
            return $exception->toResponse();
        }

        return $next($request);
    }
}
