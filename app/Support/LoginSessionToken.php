<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class LoginSessionToken
{
    public const ABILITY = 'session';

    public const NAME = 'Login session';

    public const LIFETIME_HOURS = 24;

    public static function issue(User $user): NewAccessToken
    {
        return $user->createToken(
            self::NAME,
            [self::ABILITY],
            now()->addHours(self::LIFETIME_HOURS),
        );
    }

    /**
     * Keep an active login session alive for another 24 hours.
     * Named integration tokens keep the expiry they were issued with.
     * A token with no expiry is given one 24-hour deadline and is not extended again.
     */
    public static function refresh(PersonalAccessToken $token): void
    {
        if (! $token->exists || ! self::slides($token)) {
            return;
        }

        $renewUntil = now()->addHours(self::LIFETIME_HOURS);
        if ($token->expires_at && $token->expires_at->greaterThan($renewUntil->copy()->subHour())) {
            return;
        }

        $token->forceFill([
            'expires_at' => $renewUntil,
        ])->save();
    }

    public static function isLoginSession(PersonalAccessToken $token): bool
    {
        $abilities = $token->abilities ?? [];

        return in_array(self::ABILITY, $abilities, true)
            && ! in_array('*', $abilities, true);
    }

    private static function slides(PersonalAccessToken $token): bool
    {
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return false;
        }

        if (self::isLoginSession($token)) {
            return true;
        }

        return $token->expires_at === null;
    }
}
