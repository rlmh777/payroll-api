<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionType extends Model
{
    protected $table = 'deduction_type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function deductions(): HasMany
    {
        return $this->hasMany(EmployeeDefaultDeduction::class, 'deductionTypeId');
    }

    public function historicalDeductions(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

}
