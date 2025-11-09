<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Relationship extends Model
{
    protected $table = 'relationship';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name'
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class, 'relationshipId');
    }
}
