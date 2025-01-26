<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmploymentHistory extends Model
{
    use HasUuids;

    protected $table = 'employment_history';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employerName',
        'positionHeld',
        'from',
        'to',
        'note'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }
}
