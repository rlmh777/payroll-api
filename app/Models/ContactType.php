<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactType extends Model
{
    protected $table = 'contact_type';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name'
    ];

    /**
     * Get the contacts for this type.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
