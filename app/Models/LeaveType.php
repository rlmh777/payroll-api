<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $table = 'leave_type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function leaves(): HasMany
    {
        return $this->hasMany(TrackEmployeeLeave::class);
    }
}
