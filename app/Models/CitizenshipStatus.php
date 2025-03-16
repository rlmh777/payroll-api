<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class CitizenshipStatus extends Model
{
    protected $table = 'citizenship_status';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employees(): HasMany {
        return $this->hasMany(Employee::class);
    }
}
