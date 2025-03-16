<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relation\BelongsTo;

class TrackEmployeeLeave extends Model
{
    use HasUuids;

    protected $table = 'track_employee_leave';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'leaveTypeId',
        'startDate',
        'endDate',
        'notes'   
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo {
        return $this->belongsTo(LeaveType::class);
    }



}
