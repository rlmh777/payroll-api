<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportDocumentHeader extends Model
{
    use HasUuids;

    protected $table = 'report_document_headers';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'kind',
        'is_system',
        'canvas',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'canvas' => 'array',
    ];
}
