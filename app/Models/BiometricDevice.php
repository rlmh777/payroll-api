<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BiometricDevice extends Model
{
    use HasUuids;

    public const PROTOCOLS = ['http_push', 'isup', 'agent'];

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_UNKNOWN = 'unknown';

    protected $table = 'biometric_devices';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'model',
        'serial_number',
        'location',
        'timezone',
        'protocol',
        'auth_token_hash',
        'auth_token_prefix',
        'heartbeat_timeout_seconds',
        'is_active',
        'registered_at',
        'last_seen_at',
        'last_heartbeat_at',
        'last_event_at',
        'ip_address',
        'firmware',
        'last_error',
        'notes',
    ];

    protected $hidden = [
        'auth_token_hash',
        'auth_token_prefix',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'heartbeat_timeout_seconds' => 'integer',
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(BiometricDeviceEvent::class);
    }

    public static function issueToken(): string
    {
        return Str::password(40, symbols: false);
    }

    public function setAuthToken(string $plain): void
    {
        $this->auth_token_hash = Hash::make($plain);
        $this->auth_token_prefix = substr($plain, 0, 8);
    }

    public function tokenMatches(string $plain): bool
    {
        return Hash::check($plain, $this->auth_token_hash);
    }

    public function liveStatus(?Carbon $now = null): string
    {
        $seen = $this->last_seen_at ?? $this->last_heartbeat_at;
        if (! $seen) {
            return self::STATUS_UNKNOWN;
        }

        $timeout = max(15, (int) $this->heartbeat_timeout_seconds);
        $now ??= Carbon::now();

        return $seen->gt($now->copy()->subSeconds($timeout))
            ? self::STATUS_ONLINE
            : self::STATUS_OFFLINE;
    }

    public function markSeen(?string $ipAddress = null, bool $heartbeat = false, bool $registered = false): void
    {
        $now = Carbon::now();
        $this->last_seen_at = $now;
        $this->last_error = null;
        if ($heartbeat) {
            $this->last_heartbeat_at = $now;
        }
        if ($registered) {
            $this->registered_at = $this->registered_at ?? $now;
        }
        if ($ipAddress) {
            $this->ip_address = $ipAddress;
        }
        $this->save();
    }

    public function markEvent(): void
    {
        $now = Carbon::now();
        $this->last_seen_at = $now;
        $this->last_event_at = $now;
        $this->last_error = null;
        $this->save();
    }

    public function recordError(string $message): void
    {
        $this->last_error = Str::limit($message, 500);
        $this->save();
    }
}
