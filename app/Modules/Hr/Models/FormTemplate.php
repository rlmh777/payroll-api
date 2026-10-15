<?php

namespace App\Modules\Hr\Models;

use App\Modules\Hr\Support\FormSchema;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormTemplate extends Model
{
    use HasUuids;

    public const PURPOSE_SCORE_SHEET = 'score_sheet';

    public const PURPOSE_GENERAL = 'general';

    public const PURPOSE_FEEDBACK = 'feedback';

    public const PURPOSE_APPRAISAL = 'appraisal';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'purpose',
        'schema',
        'is_active',
    ];

    protected $casts = [
        'schema' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * @return list<string>
     */
    public static function purposes(): array
    {
        return [self::PURPOSE_SCORE_SHEET, self::PURPOSE_GENERAL, self::PURPOSE_FEEDBACK, self::PURPOSE_APPRAISAL];
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class, 'form_template_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FormAssignment::class, 'form_template_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class, 'form_template_id');
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public function normalizedSchema(): array
    {
        return FormSchema::normalize($this->schema ?? ['fields' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $schema = $this->normalizedSchema();
        $score = FormSchema::score($schema, []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'purpose' => $this->purpose,
            'schema' => $schema,
            'is_active' => $this->is_active,
            'max_score' => $score['max'],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $schema = $this->normalizedSchema();
        $score = FormSchema::score($schema, []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'is_active' => $this->is_active,
            'max_score' => $score['max'],
        ];
    }
}
