<?php

namespace App\Models;

use App\Models\Concerns\HasPublicFileAttachment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use HasUuids;
    use HasPublicFileAttachment;

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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function documentTag(): BelongsTo
    {
        return $this->belongsTo(DocumentTag::class, 'documentTagId');
    }
}
