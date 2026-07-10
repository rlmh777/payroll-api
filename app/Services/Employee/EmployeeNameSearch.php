<?php

namespace App\Services\Employee;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EmployeeNameSearch
{
    private static ?bool $trgmEnabled = null;

    private const TRIGRAM_THRESHOLD = 0.2;

    public static function apply(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        if (self::supportsTrigramSearch()) {
            return self::applyTrigramSearch($query, $search);
        }

        return self::applySubstringSearch($query, $search);
    }

    public static function supportsTrigramSearch(): bool
    {
        if (self::$trgmEnabled !== null) {
            return self::$trgmEnabled;
        }

        try {
            $extension = DB::selectOne(
                "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm') AS enabled"
            );
            $function = DB::selectOne(
                "SELECT EXISTS (
                    SELECT 1
                    FROM pg_proc
                    WHERE proname = 'employee_search_name'
                ) AS enabled"
            );

            self::$trgmEnabled = (bool) ($extension->enabled ?? false)
                && (bool) ($function->enabled ?? false);
        } catch (\Throwable) {
            self::$trgmEnabled = false;
        }

        return self::$trgmEnabled;
    }

    private static function fullNameExpression(): string
    {
        return 'employee_search_name("firstName", "middleName", "lastName", "maidenName")';
    }

    private static function applyTrigramSearch(Builder $query, string $search): Builder
    {
        $needle = strtolower($search);
        $fullName = self::fullNameExpression();
        $threshold = self::TRIGRAM_THRESHOLD;
        $like = '%'.$needle.'%';

        return $query->where(function (Builder $inner) use ($needle, $fullName, $threshold, $like) {
            $inner->whereRaw('"code" ILIKE ?', [$like])
                ->orWhereRaw('"firstName" ILIKE ?', [$like])
                ->orWhereRaw('"lastName" ILIKE ?', [$like])
                ->orWhereRaw('"middleName" ILIKE ?', [$like])
                ->orWhereRaw('"maidenName" ILIKE ?', [$like])
                ->orWhereRaw("similarity({$fullName}, ?) >= ?", [$needle, $threshold])
                ->orWhereRaw("word_similarity(?, {$fullName}) >= ?", [$needle, $threshold]);
        });
    }

    private static function applySubstringSearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(function (Builder $inner) use ($like) {
            $inner->whereRaw('"code" ILIKE ?', [$like])
                ->orWhereRaw('"firstName" ILIKE ?', [$like])
                ->orWhereRaw('"lastName" ILIKE ?', [$like])
                ->orWhereRaw('"middleName" ILIKE ?', [$like])
                ->orWhereRaw('"maidenName" ILIKE ?', [$like])
                ->orWhereRaw('CONCAT("firstName", \' \', "lastName") ILIKE ?', [$like])
                ->orWhereRaw('CONCAT("lastName", \', \', "firstName") ILIKE ?', [$like])
                ->orWhereRaw('CONCAT("firstName", \' \', "middleName", \' \', "lastName") ILIKE ?', [$like]);
        });
    }
}
