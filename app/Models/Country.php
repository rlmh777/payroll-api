<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Country extends Model
{
    use HasUuids;

    protected $table = 'country';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'code1',
        'code2',
        'nationalityName'
    ];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'countryId');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

}

