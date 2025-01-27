<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class LeaveType extends Model
{
    protected $table = 'leave_Type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function leaves(): HasMany {
        return $this->hasMany(TrackEmployeeLeave::class);
    }
}
