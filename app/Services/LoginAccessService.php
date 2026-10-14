<?php

namespace App\Services;

use App\Exceptions\LoginAccessDeniedException;
use App\Models\User;
use Illuminate\Http\Request;

class LoginAccessService
{
    public function __construct(
        private readonly LoginLockoutService $lockout,
        private readonly UserIpAllowlist $ips,
    ) {
    }

    public function assertCanSignIn(User $user, Request $request): void
    {
        $this->lockout->assertNotLocked($user);
        $this->ips->assertAllowed($user, $request);
    }

    /**
     * @throws LoginAccessDeniedException
     */
    public function recordFailedAttempt(User $user): void
    {
        $this->lockout->recordFailure($user);
        $this->lockout->assertNotLocked($user->fresh() ?? $user);
    }
}
