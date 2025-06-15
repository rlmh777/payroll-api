<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrateFrequency extends Model
{
    protected $table = 'payrate_frequency';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeAllowance(): HasMany
    {
        return $this->hasMany(EmployeeAllowance::class);
    }

    public function employeeDefaultDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDefaultDeduction::class);
    }

    public function employmentDetails(): HasMany
    {
        return $this->hasMany(EmploymentDetail::class);
    }
}
