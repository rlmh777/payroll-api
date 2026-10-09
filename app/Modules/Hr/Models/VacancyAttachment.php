<?php

namespace App\Modules\Hr\Models;

use App\Support\ConfiguredStorage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacancyAttachment extends Model
{
    use HasUuids;

    protected $table = 'vacancy_attachments';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'vacancy_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
    ];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_url' => app(ConfiguredStorage::class)->urlOrNull($this->file_path),
        ];
    }
}
