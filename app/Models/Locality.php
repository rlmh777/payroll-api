<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Locality extends Model
{
    use HasUuids;

    protected $table = 'locality';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'districtId'
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function worksites(): HasMany
    {
        return $this->hasMany(Worksite::class, 'localityId');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'localityId');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class, 'localityId');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'localityId');
    }
}
