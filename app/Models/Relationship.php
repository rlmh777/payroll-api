<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class Relationship extends Model
{
    protected $table = 'relationship';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function contacts(): HasMany {
        return $this->hasMany(EmployeeContacts::class);
    }
}
