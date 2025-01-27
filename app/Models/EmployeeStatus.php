<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class EmployeeStatus extends Model
{
    protected $table = 'employee_status';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employeeDetails(): HasMany {
        return $this->hasMany(EmploymentDetail::class);
    }



}
