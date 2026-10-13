<?php

namespace App\Modules\Hr\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormSchema
{
    public const TYPES = [
        'section',
        'score',
        'number',
        'text',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'date',
    ];

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public static function normalize(mixed $schema): array
    {
        $fields = is_array($schema) ? ($schema['fields'] ?? $schema) : [];
        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'schema' => 'Form fields must be a list.',
            ]);
        }

        $normalized = [];
        foreach (array_values($fields) as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages([
                    "schema.fields.{$index}" => 'Each field must be an object.',
                ]);
            }

            $normalized[] = self::normalizeField($field, $index);
        }

        return ['fields' => $normalized];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public static function validateAnswers(array $schema, array $answers): array
    {
        $clean = [];
        foreach (self::inputFields($schema) as $field) {
            $id = (string) $field['id'];
            $value = $answers[$id] ?? null;
            $clean[$id] = self::validateAnswer($field, $value);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return array{total: float, max: float}
     */
    public static function score(array $schema, array $answers): array
    {
        $total = 0.0;
        $max = 0.0;

        foreach (self::inputFields($schema) as $field) {
            if (($field['type'] ?? '') !== 'score') {
                continue;
            }

            $weight = (float) ($field['weight'] ?? 1);
            $fieldMax = (float) ($field['max'] ?? 10);
            $max += $fieldMax * $weight;
            $value = $answers[(string) $field['id']] ?? null;
            if (is_numeric($value)) {
                $total += ((float) $value) * $weight;
            }
        }

        return [
            'total' => round($total, 2),
            'max' => round($max, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    public static function inputFields(array $schema): array
    {
        $fields = $schema['fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter(
            $fields,
            fn ($field) => is_array($field) && ($field['type'] ?? '') !== 'section',
        ));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function normalizeField(array $field, int $index): array
    {
        $type = (string) ($field['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages([
                "schema.fields.{$index}.type" => 'Unsupported field type.',
            ]);
        }

        $label = trim((string) ($field['label'] ?? ''));
        if ($label === '') {
            throw ValidationException::withMessages([
                "schema.fields.{$index}.label" => 'Each field needs a label.',
            ]);
        }

        $id = trim((string) ($field['id'] ?? ''));
        if ($id === '') {
            $id = (string) Str::uuid();
        }

        $normalized = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'help' => filled($field['help'] ?? null) ? (string) $field['help'] : null,
            'required' => (bool) ($field['required'] ?? false),
            'placeholder' => filled($field['placeholder'] ?? null) ? (string) $field['placeholder'] : null,
        ];

        if (in_array($type, ['score', 'number'], true)) {
            $normalized['min'] = is_numeric($field['min'] ?? null) ? (float) $field['min'] : 0;
            $normalized['max'] = is_numeric($field['max'] ?? null) ? (float) $field['max'] : ($type === 'score' ? 10 : null);
            $normalized['weight'] = $type === 'score'
                ? max(0, (float) ($field['weight'] ?? 1))
                : null;
        }

        if (in_array($type, ['select', 'radio'], true)) {
            $options = [];
            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (is_string($option) && trim($option) !== '') {
                    $options[] = ['value' => $option, 'label' => $option];

                    continue;
                }
                if (! is_array($option)) {
                    continue;
                }
                $value = trim((string) ($option['value'] ?? $option['label'] ?? ''));
                $optionLabel = trim((string) ($option['label'] ?? $value));
                if ($value === '' || $optionLabel === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => $optionLabel];
            }
            if ($options === []) {
                throw ValidationException::withMessages([
                    "schema.fields.{$index}.options" => 'Choice fields need at least one option.',
                ]);
            }
            $normalized['options'] = $options;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function validateAnswer(array $field, mixed $value): mixed
    {
        $label = (string) $field['label'];
        $required = (bool) ($field['required'] ?? false);
        $blank = $value === null || $value === '';

        if ($blank) {
            if ($required) {
                throw ValidationException::withMessages([
                    'answers' => "{$label} is required.",
                ]);
            }

            return $field['type'] === 'checkbox' ? false : null;
        }

        return match ($field['type']) {
            'score', 'number' => self::numericAnswer($field, $value),
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'select', 'radio' => self::choiceAnswer($field, $value),
            'date' => self::dateAnswer($label, $value),
            default => is_scalar($value) ? trim((string) $value) : null,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function numericAnswer(array $field, mixed $value): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'answers' => "{$field['label']} must be a number.",
            ]);
        }

        $number = (float) $value;
        $min = isset($field['min']) ? (float) $field['min'] : null;
        $max = isset($field['max']) ? (float) $field['max'] : null;
        if ($min !== null && $number < $min) {
            throw ValidationException::withMessages([
                'answers' => "{$field['label']} must be at least {$min}.",
            ]);
        }
        if ($max !== null && $number > $max) {
            throw ValidationException::withMessages([
                'answers' => "{$field['label']} must be at most {$max}.",
            ]);
        }

        return $number;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function choiceAnswer(array $field, mixed $value): string
    {
        $choice = trim((string) $value);
        $allowed = array_map(
            fn ($option) => (string) ($option['value'] ?? ''),
            is_array($field['options'] ?? null) ? $field['options'] : [],
        );
        if (! in_array($choice, $allowed, true)) {
            throw ValidationException::withMessages([
                'answers' => "{$field['label']} has an invalid choice.",
            ]);
        }

        return $choice;
    }

    private static function dateAnswer(string $label, mixed $value): string
    {
        $date = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages([
                'answers' => "{$label} must be a date.",
            ]);
        }

        return $date;
    }
}
