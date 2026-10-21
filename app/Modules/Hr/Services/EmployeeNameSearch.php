<?php

namespace App\Modules\Hr\Services;

use Illuminate\Database\Eloquent\Builder;

class EmployeeNameSearch
{
    public static function apply(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($tokens) {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $tokenQuery) use ($token) {
                    self::matchToken($tokenQuery, (string) $token);
                });
            }
        });
    }

    private static function matchToken(Builder $query, string $token): void
    {
        $pattern = mb_strlen($token) <= 2 ? $token.'%' : '%'.$token.'%';

        $query->whereHas('person', function (Builder $person) use ($pattern) {
            $person->where(function (Builder $name) use ($pattern) {
                $name->whereRaw('"firstName" ILIKE ?', [$pattern])
                    ->orWhereRaw('"lastName" ILIKE ?', [$pattern])
                    ->orWhereRaw('"middleName" ILIKE ?', [$pattern])
                    ->orWhereRaw('"maidenName" ILIKE ?', [$pattern]);
            });
        })->orWhereRaw('"employee"."code" ILIKE ?', [$pattern])
            ->orWhereRaw('"employee"."internalId1" ILIKE ?', [$pattern])
            ->orWhereRaw('"employee"."internalId2" ILIKE ?', [$pattern]);
    }
}
