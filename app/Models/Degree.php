<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Degree extends Model
{
    protected $table = 'degree';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name'
    ];

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class, 'degreeId');
    }
}

