<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeGroup extends Model
{
    use HasUuids;

    protected $table = 'employee_group';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'color',
        'isActive',
    ];

    protected $casts = [
        'isActive' => 'boolean',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(EmployeeGroupMember::class, 'employeeGroupId');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->active();
    }
}
