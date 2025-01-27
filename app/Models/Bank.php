<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class Banks extends Model
{
    use HasUuids;

    protected $table = 'bank';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'code'
     
    ];

    public function employeeBank(): HasMany {
        return $this->hasMany(EmployeeBank::class);
    }

    public function vendors(): HasMany {
        return $this->hasMany(Vendor::class);
    }

}
