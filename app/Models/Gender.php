<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class Gender extends Model
{
    protected $table = 'gender';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employees(): HasMany {
        return $this->hasMany(Employee::class);
    }
}
