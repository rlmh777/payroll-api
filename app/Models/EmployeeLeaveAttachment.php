<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveAttachment extends Model
{
    use HasUuids;

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

    protected function fileUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->filePath ? asset('storage/'.$this->filePath) : null,
        );
    }

    public function employeeLeave(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeave::class, 'employeeLeaveId');
    }
}
