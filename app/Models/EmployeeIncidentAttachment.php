<?php

namespace App\Models;

use App\Models\Concerns\HasPublicFileAttachment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeIncidentAttachment extends Model
{
    use HasUuids;
    use HasPublicFileAttachment;

    protected $table = 'employee_incident_attachment';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeIncidentId',
        'filePath',
        'fileName',
        'mimeType',
        'fileSize',
    ];

    protected $appends = [
        'fileUrl',
    ];

    public function employeeIncident(): BelongsTo
    {
        return $this->belongsTo(EmployeeIncident::class, 'employeeIncidentId');
    }
}
