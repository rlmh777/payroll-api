<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use Illuminate\Validation\ValidationException;

class EmployeeIdentifierUniqueness
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function assertAvailable(array $attributes, ?string $ignoreEmployeeId = null): void
    {
        $errors = [];

        if (array_key_exists('code', $attributes) && self::taken('code', $attributes['code'], $ignoreEmployeeId)) {
            $errors['code'] = ['Employee code is already used by another employee.'];
        }

        if (array_key_exists('internalId1', $attributes) && self::taken('internalId1', $attributes['internalId1'], $ignoreEmployeeId)) {
            $errors['internalId1'] = ['Internal code 1 is already used by another employee.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function taken(string $column, mixed $value, ?string $ignoreEmployeeId = null): bool
    {
        $normalized = self::normalize($value);

        if ($normalized === null || ! in_array($column, ['code', 'internalId1'], true)) {
            return false;
        }

        return Employee::query()
            ->when($ignoreEmployeeId, fn ($query) => $query->where('id', '!=', $ignoreEmployeeId))
            ->whereRaw('lower(btrim("'.$column.'")) = ?', [mb_strtolower($normalized)])
            ->exists();
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
