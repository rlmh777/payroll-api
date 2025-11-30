<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $table = 'leave_type';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'name'
    ];

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class, 'leaveTypeId');
    }
}
