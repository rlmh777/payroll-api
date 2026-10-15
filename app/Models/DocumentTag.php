<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTag extends Model
{
    use HasUuids;

    protected $table = 'document_tag';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'parentId',
        'name',
        'description',
        'color',
        'sortOrder',
        'isActive',
    ];

    protected $casts = [
        'sortOrder' => 'integer',
        'isActive' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentTag::class, 'parentId');
    }

    public function children(): HasMany
    {
        return $this->hasMany(DocumentTag::class, 'parentId');
    }

    public function employeeDocuments(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'documentTagId');
    }
}
