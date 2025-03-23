<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ContactType extends Model
{
    use HasUuids;

    protected $table = 'contact_type';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

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
