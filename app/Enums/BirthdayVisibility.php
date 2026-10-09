<?php

namespace App\Enums;

enum BirthdayVisibility: string
{
    case None = 'none';
    case Department = 'department';
    case Company = 'company';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
