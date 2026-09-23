<?php

namespace App\Models;

use App\Enums\StorageDriver;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class StorageSetting extends Model
{
    use HasUuids;

    protected $table = 'storage_settings';

    protected $fillable = [
        'driver',
        'container',
        'account_name',
        'account_key',
        'endpoint',
        'region',
        'use_path_style_endpoint',
        'prefix',
    ];

    protected $casts = [
        'account_key' => 'encrypted',
        'use_path_style_endpoint' => 'boolean',
    ];

    protected $hidden = [
        'account_key',
    ];

    public static function current(): self
    {
        if (! Schema::hasTable('storage_settings')) {
            return new self([
                'driver' => StorageDriver::Local->value,
                'container' => 'uploads',
            ]);
        }

        $settings = static::query()->first();
        if ($settings) {
            return $settings;
        }

        return static::query()->create([
            'driver' => StorageDriver::Local->value,
            'container' => 'uploads',
        ]);
    }

    public function driverEnum(): StorageDriver
    {
        return StorageDriver::tryFrom((string) $this->driver) ?? StorageDriver::Local;
    }

    public function hasSecret(): bool
    {
        return filled($this->account_key);
    }
}
