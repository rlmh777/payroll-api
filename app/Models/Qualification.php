<?php

namespace App\Models;

use App\Models\Concerns\HasPublicFileAttachment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Qualification extends Model
{
    use HasUuids;
    use HasPublicFileAttachment;

    protected $table = 'qualification';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'institutionId',
        'degreeId',
        'from',
        'to',
        'note',
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

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function degree(): BelongsTo
    {
        return $this->belongsTo(Degree::class, 'degreeId');
    }
}
