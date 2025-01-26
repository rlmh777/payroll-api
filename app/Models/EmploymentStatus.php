<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class EmploymentStatus extends Model
{
    protected $table = 'employment_status';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employees(): HasMany {
        return $this->hasMany(Employee::class);
    }
}
