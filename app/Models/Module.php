<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $table = 'modules';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'title',
        'icon',
        'default_route',
        'is_core',
        'is_active',
        'sort_order',
        'version',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class, 'module_code', 'code');
    }
}
