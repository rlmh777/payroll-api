<?php

namespace App\Models;

use App\Enums\LeaveStatusCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class LeaveStatus extends Model
{
    protected $table = 'leave_status';

    protected $fillable = [
        'code',
        'name',
        'sortOrder',
        'isTerminal',
        'requiresSupervisor',
    ];

    protected $casts = [
        'sortOrder' => 'integer',
        'isTerminal' => 'boolean',
        'requiresSupervisor' => 'boolean',
    ];

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class, 'leaveStatusId');
    }

    public function codeEnum(): ?LeaveStatusCode
    {
        return LeaveStatusCode::fromStored($this->code);
    }

    public static function idForCode(LeaveStatusCode|string $code): int
    {
        $normalized = $code instanceof LeaveStatusCode ? $code->value : strtoupper($code);

        return (int) static::cachedByCode()[$normalized];
    }

    /**
     * @return array<string, int>
     */
    public static function cachedByCode(): array
    {
        return Cache::rememberForever('leave_status_by_code', function () {
            return static::query()
                ->pluck('id', 'code')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
    }

    /**
     * @param array<int, LeaveStatusCode> $codes
     * @return array<int, int>
     */
    public static function idsForCodes(array $codes): array
    {
        $map = static::cachedByCode();

        return array_values(array_filter(array_map(
            fn (LeaveStatusCode $code) => $map[$code->value] ?? null,
            $codes,
        )));
    }

    public static function clearCache(): void
    {
        Cache::forget('leave_status_by_code');
    }
}
