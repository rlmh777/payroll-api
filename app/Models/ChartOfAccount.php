<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Account
{
    protected $fillable = [
        'name',
        'description',
        'code1',
        'code2',
        'parent_id',
        'account_type_id',
        'type',
        'is_active',
        'balance',
        'level',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balance' => 'decimal:2',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
