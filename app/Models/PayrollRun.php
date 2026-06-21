<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
    ];

    protected $casts = [
        'payrate_frequency_id' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function payPeriodSchedule(): BelongsTo
    {
        return $this->belongsTo(PayPeriodSchedule::class, 'pay_period_schedule_id');
    }

    public function payrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class, 'payrate_frequency_id');
    }
}
