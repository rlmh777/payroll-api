<?php

namespace App\Support;

use App\Models\User;

class LoginUserFinder
{
    public function find(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $normalized = mb_strtolower($identifier);

        if (str_contains($identifier, '@')) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [$normalized])
                ->first();
        }

        $byUsername = User::query()
            ->whereRaw('LOWER(username) = ?', [$normalized])
            ->first();
        if ($byUsername) {
            return $byUsername;
        }

        return User::query()
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(email) = ?', [$normalized])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$normalized.'@%']);
            })
            ->orderBy('email')
            ->first();
    }
}
