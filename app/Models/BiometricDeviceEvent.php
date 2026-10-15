<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricDeviceEvent extends Model
{
    use HasUuids;

    protected $table = 'biometric_device_events';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'biometric_device_id',
        'employee_no',
        'employee_id',
        'identified',
        'event_time',
        'punch_type',
        'clocking_log_id',
        'duplicate',
        'raw_payload',
        'received_at',
    ];

    protected $casts = [
        'identified' => 'boolean',
        'duplicate' => 'boolean',
        'event_time' => 'datetime',
        'raw_payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function clockingLog(): BelongsTo
    {
        return $this->belongsTo(ClockingLog::class, 'clocking_log_id');
    }
}
