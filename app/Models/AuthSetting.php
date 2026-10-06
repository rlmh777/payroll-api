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
        'username_pattern',
        'username_patterns',
        'username_separator',
        'username_include_middle_initial',
        'employee_login_domain',
    ];

    protected $casts = [
        'passkeys_enabled' => 'boolean',
        'username_include_middle_initial' => 'boolean',
        'username_patterns' => 'array',
    ];

    public const USERNAME_PATTERN_FIRST_LAST = 'first_last';

    public const USERNAME_PATTERN_LAST_FIRST = 'last_first';

    public const USERNAME_PATTERN_FIRST_INITIAL_LAST = 'first_initial_last';

    public const USERNAME_PATTERN_FIRSTLAST = 'firstlast';

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
            'username_pattern' => self::USERNAME_PATTERN_FIRST_LAST,
            'username_patterns' => [self::USERNAME_PATTERN_FIRST_LAST],
            'username_separator' => '.',
            'username_include_middle_initial' => true,
            'employee_login_domain' => null,
        ]);
    }
}
