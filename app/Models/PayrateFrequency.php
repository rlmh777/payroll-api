<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class PayrateFrequency extends Model
{
    protected $table = 'payrate_frequency';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function employees(): HasMany {
        return $this->hasMany(Employee::class);
    }
}
