<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ShiftTemplate extends Model
{
    use HasUuids;

    protected $table = 'shift_template';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'segments',
        'include_lunch_hour',
        'lunch_hour_hours',
        'is_active',
    ];

    protected $casts = [
        'segments' => 'array',
        'include_lunch_hour' => 'boolean',
        'lunch_hour_hours' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'shift_template_department',
            'shift_template_id',
            'department_id',
        )->withTimestamps();
    }

    /**
     * Human-readable label: optional name, else time segments.
     */
    public function displayLabel(): string
    {
        $name = trim((string) ($this->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $segments = $this->resolvedSegments();
        if ($segments === []) {
            return 'Shift';
        }

        $times = collect($segments)
            ->map(fn (array $segment) => $segment['start_time'].'–'.$segment['end_time'])
            ->implode(' / ');

        if ($this->include_lunch_hour) {
            return $times.' (w/ lunch)';
        }

        return $times;
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @return list<array{start_time: string, end_time: string}>
     */
    public static function normalizeSegments(array $segments): array
    {
        $normalized = [];

        foreach ($segments as $segment) {
            $start = self::normalizeTime($segment['start_time'] ?? null);
            $end = self::normalizeTime($segment['end_time'] ?? null);
            if ($start === null || $end === null) {
                continue;
            }

            $normalized[] = [
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return array_values($normalized);
    }

    public static function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $raw) === 1) {
            return substr($raw, 0, 5);
        }

        return null;
    }

    /**
     * @return list<array{start_time: string, end_time: string}>
     */
    public function resolvedSegments(): array
    {
        return self::normalizeSegments(is_array($this->segments) ? $this->segments : []);
    }
}
