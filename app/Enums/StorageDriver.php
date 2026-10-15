<?php

namespace App\Enums;

enum StorageDriver: string
{
    case Local = 'local';
    case Azure = 'azure';
    case S3 = 's3';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
