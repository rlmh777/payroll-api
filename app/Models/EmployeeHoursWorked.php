<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeHoursWorked extends Model
{
    use HasUuids;

    protected $table = 'employee_hours_worked';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeCode1',
        'employeeCode2',
        'from',
        'to',
        'regularWorkingHours',
        'overtimeHours',
        'doubletimeHours',
        'note',
        'location'
    ];
}
