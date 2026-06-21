<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PayPeriodSchedule extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'pay_period_schedule';

    protected $fillable = [
        'id',
        'start_date',
        'end_date',
        'pay_date',
        'pay_period_group_id',
        'payrate_frequency_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'pay_date' => 'date',
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

    public function payPeriodGroup(): BelongsTo
    {
        return $this->belongsTo(PayPeriodGroup::class, 'pay_period_group_id');
    }

    public function payrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class, 'payrate_frequency_id');
    }
}
