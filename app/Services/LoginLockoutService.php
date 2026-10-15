<?php

namespace App\Services;

use App\Exceptions\LoginAccessDeniedException;
use App\Models\AuthSetting;
use App\Models\User;

class LoginLockoutService
{
    public function maxAttempts(): int
    {
        return max(0, (int) AuthSetting::current()->lockout_max_attempts);
    }

    public function decayMinutes(): int
    {
        return max(1, (int) AuthSetting::current()->lockout_decay_minutes);
    }

    public function isLocked(User $user): bool
    {
        if ($this->maxAttempts() < 1) {
            return false;
        }

        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    public function assertNotLocked(User $user): void
    {
        if (! $this->isLocked($user)) {
            return;
        }

        $retryAfter = max(1, $user->locked_until->getTimestamp() - now()->getTimestamp());

        throw new LoginAccessDeniedException(
            sprintf(
                'Too many failed sign-in attempts. Try again in %d minute%s.',
                max(1, (int) ceil($retryAfter / 60)),
                (int) ceil($retryAfter / 60) === 1 ? '' : 's',
            ),
            423,
            [
                'locked_until' => $user->locked_until?->toIso8601String(),
                'retry_after' => $retryAfter,
            ],
        );
    }

    public function recordFailure(User $user): void
    {
        if ($this->maxAttempts() < 1) {
            return;
        }

        if ($this->isLocked($user)) {
            return;
        }

        $attempts = (int) $user->failed_login_attempts + 1;
        $updates = ['failed_login_attempts' => $attempts];

        if ($attempts >= $this->maxAttempts()) {
            $updates['locked_until'] = now()->addMinutes($this->decayMinutes());
        }

        $user->forceFill($updates)->save();
    }

    public function clear(User $user): void
    {
        if ((int) $user->failed_login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }
}
