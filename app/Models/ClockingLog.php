<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClockingLog extends Model
{
    use HasUuids;

    protected $table = 'clocking_log';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'biometricUserId',
        'deviceId',
        'punchDateTime',
        'punchType',
    ];

    protected $casts = [
        'punchDateTime' => 'datetime',
    ];
}
