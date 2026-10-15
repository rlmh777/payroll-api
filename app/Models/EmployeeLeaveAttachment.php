<?php

namespace App\Models;

use App\Models\Concerns\HasPublicFileAttachment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveAttachment extends Model
{
    use HasUuids;
    use HasPublicFileAttachment;

    protected $table = 'employee_leave_attachment';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeLeaveId',
        'filePath',
        'fileName',
        'mimeType',
        'fileSize',
    ];

    protected $appends = [
        'fileUrl',
    ];

    public function employeeLeave(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeave::class, 'employeeLeaveId');
    }
}
