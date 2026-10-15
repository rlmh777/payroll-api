<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeBank extends Model
{
    use HasUuids;

    protected $table = 'employee_bank';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'bankId',
        'accountNumber',
        'isPrimary',
        'notes',
    ];

    protected $casts = [
        'isPrimary' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (EmployeeBank $employeeBank) {
            if ($employeeBank->isPrimary) {
                static::query()
                    ->where('employeeId', $employeeBank->employeeId)
                    ->where('id', '!=', $employeeBank->id)
                    ->update(['isPrimary' => false]);
            }
        });

        static::deleted(function (EmployeeBank $employeeBank) {
            if (!$employeeBank->isPrimary) {
                return;
            }

            $nextPrimary = static::query()
                ->where('employeeId', $employeeBank->employeeId)
                ->orderBy('created_at')
                ->first();

            if ($nextPrimary) {
                $nextPrimary->update(['isPrimary' => true]);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bankId');
    }

    public static function resolvePrimaryForEmployee(string $employeeId): ?self
    {
        $primary = static::query()
            ->with('bank')
            ->where('employeeId', $employeeId)
            ->where('isPrimary', true)
            ->first();

        if ($primary) {
            return $primary;
        }

        return static::query()
            ->with('bank')
            ->where('employeeId', $employeeId)
            ->orderBy('created_at')
            ->first();
    }
}
