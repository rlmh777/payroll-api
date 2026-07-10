<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaveType extends Model
{
    protected $table = 'leave_type';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'name',
        'code',
        'isPaid',
        'affectsBalance',
        'requiresCertification',
        'isActive',
        'sortOrder',
    ];

    protected $casts = [
        'isPaid' => 'boolean',
        'affectsBalance' => 'boolean',
        'requiresCertification' => 'boolean',
        'isActive' => 'boolean',
        'sortOrder' => 'integer',
    ];

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class, 'leaveTypeId');
    }

    public function policy(): HasOne
    {
        return $this->hasOne(LeaveTypePolicy::class, 'leaveTypeId');
    }
}
