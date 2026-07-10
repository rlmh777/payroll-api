<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use HasUuids;

    protected $table = 'employee_document';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'documentTagId',
        'name',
        'description',
        'filePath',
        'fileName',
        'mimeType',
        'fileSize',
    ];

    protected $appends = [
        'fileUrl',
    ];

    protected function fileUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->filePath ? asset('storage/'.$this->filePath) : null,
        );
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function documentTag(): BelongsTo
    {
        return $this->belongsTo(DocumentTag::class, 'documentTagId');
    }
}
