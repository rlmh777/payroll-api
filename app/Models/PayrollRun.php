<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'payroll_runs';

    protected $fillable = [
        'id',
        'pay_period_schedule_id',
        'payrate_frequency_id',
        'status',
        'payroll_number',
        'timesheet_lock_applied_at',
    ];

    protected $casts = [
        'payrate_frequency_id' => 'integer',
        'payroll_number' => 'integer',
        'timesheet_lock_applied_at' => 'datetime',
    ];

    protected $appends = [
        'payrollNumberFormatted',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            if (empty($model->payroll_number)) {
                $model->payroll_number = static::nextPayrollNumber();
            }
        });
    }

    public static function nextPayrollNumber(): int
    {
        $max = (int) static::query()->max('payroll_number');

        return $max + 1;
    }

    public function getPayrollNumberFormattedAttribute(): string
    {
        return str_pad((string) ($this->payroll_number ?? 0), 9, '0', STR_PAD_LEFT);
    }

    public function payPeriodSchedule(): BelongsTo
    {
        return $this->belongsTo(PayPeriodSchedule::class, 'pay_period_schedule_id');
    }

    public function payrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class, 'payrate_frequency_id');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'payroll_run_id');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(PayrollEarningLine::class, 'payroll_run_id');
    }
}
