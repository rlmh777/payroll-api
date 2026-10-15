<?php

namespace App\Modules\Hr\Services\Employee;

use App\Models\Employee;
use Illuminate\Support\Str;

class EmployeeCodeGenerator
{
    /**
     * Build a unique employee code from last name, first name, entry date, and random characters.
     *
     * Format: {LAST3}{FIRST3}{YYMMDD}{RAND4} (uppercase alphanumeric, max 64).
     */
    public function generate(string $firstName, string $lastName, ?\DateTimeInterface $enteredAt = null): string
    {
        $enteredAt ??= now();
        $base = $this->baseCode($firstName, $lastName, $enteredAt);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = $base.$this->randomSuffix(4);
            if (!Employee::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Extremely unlikely collision path.
        return $base.Str::upper(Str::random(6));
    }

    public function baseCode(string $firstName, string $lastName, ?\DateTimeInterface $enteredAt = null): string
    {
        $enteredAt ??= now();
        $last = $this->nameFragment($lastName, 3);
        $first = $this->nameFragment($firstName, 3);
        $date = $enteredAt->format('ymd');

        return $last.$first.$date;
    }

    private function nameFragment(string $name, int $length): string
    {
        $normalized = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
        if ($normalized === '') {
            $normalized = 'XXX';
        }

        return Str::padRight(Str::substr($normalized, 0, $length), $length, 'X');
    }

    private function randomSuffix(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';
        for ($i = 0; $i < $length; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }
}
