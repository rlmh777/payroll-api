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
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function worksites(): HasMany
    {
        return $this->hasMany(Worksite::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContacts::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
