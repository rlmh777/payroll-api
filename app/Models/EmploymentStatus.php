<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmploymentStatus extends Model
{
    protected $table = 'employment_status';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employmentDetails(): HasMany
    {
        return $this->hasMany(EmploymentDetail::class);
    }
}
