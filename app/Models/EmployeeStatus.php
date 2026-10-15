<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeStatus extends Model
{
    protected $table = 'employee_status';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'employeeStatusId');
    }



}
