<?php

namespace App\Enums;

use Illuminate\Validation\Rule;

enum PayrollItemOccurrence: string
{
    case EveryPayroll = 'every_payroll';
    case FirstOfMonth = 'first_of_month';
    case LastOfMonth = 'last_of_month';
    case NthOfMonth = 'nth_of_month';
    case Cycle = 'cycle';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromStored(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return self::EveryPayroll;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::EveryPayroll;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function assignmentRules(bool $occurrenceRequired = true): array
    {
        return [
            'occurrence' => [$occurrenceRequired ? 'required' : 'sometimes', 'string', Rule::in(self::values())],
            'occurrenceCycleLength' => [
                'nullable',
                'integer',
                'min:2',
                'max:26',
                Rule::requiredIf(fn () => request()->input('occurrence') === self::Cycle->value),
            ],
            'occurrenceCycleOffset' => [
                'nullable',
                'integer',
                'min:1',
                'max:26',
                Rule::requiredIf(fn () => in_array(
                    request()->input('occurrence'),
                    [self::Cycle->value, self::NthOfMonth->value],
                    true,
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAssignment(array $data): array
    {
        if (! array_key_exists('occurrence', $data)) {
            return $data;
        }

        $occurrence = self::fromStored(isset($data['occurrence']) ? (string) $data['occurrence'] : null);
        $data['occurrence'] = $occurrence->value;

        $length = isset($data['occurrenceCycleLength']) && $data['occurrenceCycleLength'] !== ''
            ? (int) $data['occurrenceCycleLength']
            : null;
        $offset = isset($data['occurrenceCycleOffset']) && $data['occurrenceCycleOffset'] !== ''
            ? (int) $data['occurrenceCycleOffset']
            : null;

        if ($occurrence === self::Cycle) {
            $data['occurrenceCycleLength'] = $length;
            $data['occurrenceCycleOffset'] = $offset;

            return $data;
        }

        if ($occurrence === self::NthOfMonth) {
            $data['occurrenceCycleLength'] = null;
            $data['occurrenceCycleOffset'] = $offset;

            return $data;
        }

        $data['occurrenceCycleLength'] = null;
        $data['occurrenceCycleOffset'] = null;

        return $data;
    }
}
