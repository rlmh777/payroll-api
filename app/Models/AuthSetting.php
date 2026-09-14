<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuthSetting extends Model
{
    use HasUuids;

    protected $table = 'auth_settings';

    protected $fillable = [
        'two_factor_policy',
        'passkeys_enabled',
    ];

    protected $casts = [
        'passkeys_enabled' => 'boolean',
    ];

    public const POLICY_OFF = 'off';

    public const POLICY_OPTIONAL = 'optional';

    public const POLICY_REQUIRED = 'required';

    public static function current(): self
    {
        $settings = static::query()->first();
        if ($settings) {
            return $settings;
        }

        return static::query()->create([
            'id' => (string) Str::uuid(),
            'two_factor_policy' => self::POLICY_OFF,
            'passkeys_enabled' => true,
        ]);
    }
}
