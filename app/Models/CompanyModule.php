<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModule extends Model
{
    use HasUuids;

    protected $table = 'company_modules';

    protected $fillable = [
        'company_id',
        'module_code',
        'enabled',
        'config',
        'enabled_at',
        'enabled_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
        'enabled_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_code', 'code');
    }
}
