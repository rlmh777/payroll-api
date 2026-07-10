<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SsBenefitType extends Model
{
    protected $table = 'ss_benefit_type';

    protected $fillable = [
        'name',
    ];

    public function benefitStatuses(): HasMany
    {
        return $this->hasMany(EmployeeSsBenefitStatus::class, 'ss_benefit_type_id');
    }
}
