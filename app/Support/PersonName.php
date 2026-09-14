<?php

namespace App\Support;

class PersonName
{
    /**
     * Display format used by scheduler and timesheet lists: "Last, First".
     */
    public static function lastFirst(?string $firstName, ?string $lastName): ?string
    {
        $first = trim((string) $firstName);
        $last = trim((string) $lastName);

        if ($last !== '' && $first !== '') {
            return "{$last}, {$first}";
        }

        $name = trim("{$last} {$first}");

        return $name !== '' ? $name : null;
    }
}
