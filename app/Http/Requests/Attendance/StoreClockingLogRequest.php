<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreClockingLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        if (!isset($payload['logs'])) {
            $payload['logs'] = [$payload];
        }

        if (isset($payload['logs']) && is_array($payload['logs'])) {
            $payload['logs'] = array_values(array_merge(...array_map(function ($row) {
                if (!is_array($row)) {
                    return [$row];
                }

                return $this->normalizeLogRows($row);
            }, $payload['logs'])));
        }

        $this->replace($payload);
    }

    public function rules(): array
    {
        return [
            'logs' => ['required', 'array', 'min:1', 'max:5000'],
            'logs.*.biometricUserId' => ['required', 'string', 'max:255'],
            'logs.*.deviceId' => ['nullable', 'string', 'max:255'],
            'logs.*.punchDateTime' => ['required', 'date'],
            'logs.*.punchType' => ['nullable', 'string', 'in:IN,OUT'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array{biometricUserId:?string, deviceId:?string, punchDateTime:mixed, punchType:?string}>
     */
    private function normalizeLogRows(array $row): array
    {
        $biometricUserId = $this->firstValue($row, [
            'biometricUserId',
            'userId',
            'employeeId',
            'employeeCode',
            'enrollId',
        ]);
        $deviceId = $this->nullableString($this->firstValue($row, [
            'deviceId',
            'machineId',
            'terminalId',
        ]));
        $punchDate = $this->firstValue($row, ['date', 'workDate', 'attendanceDate', 'punchDate']);

        $punchIn = $this->firstValue($row, ['punchIn', 'punchInTime', 'clockIn', 'clockInTime', 'checkIn']);
        $punchOut = $this->firstValue($row, ['punchOut', 'punchOutTime', 'clockOut', 'clockOutTime', 'checkOut']);

        if ($punchIn !== null || $punchOut !== null) {
            $rows = [];

            if ($punchIn !== null) {
                $rows[] = [
                    'biometricUserId' => $this->nullableString($biometricUserId),
                    'deviceId' => $this->nullableString($this->firstValue($row, [
                        'punchInDeviceId',
                        'clockInDeviceId',
                        'sitePunchIn',
                    ])) ?? $deviceId,
                    'punchDateTime' => $this->combineDateAndTime($punchDate, $punchIn),
                    'punchType' => 'IN',
                ];
            }

            if ($punchOut !== null) {
                $rows[] = [
                    'biometricUserId' => $this->nullableString($biometricUserId),
                    'deviceId' => $this->nullableString($this->firstValue($row, [
                        'punchOutDeviceId',
                        'clockOutDeviceId',
                        'sitePunchOut',
                    ])) ?? $deviceId,
                    'punchDateTime' => $this->combineDateAndTime($punchDate, $punchOut),
                    'punchType' => 'OUT',
                ];
            }

            return $rows;
        }

        $punchDateTime = $this->firstValue($row, [
            'punchDateTime',
            'dateTime',
            'timestamp',
        ]);

        if ($punchDateTime === null) {
            $punchDateTime = $this->combineDateAndTime(
                $punchDate,
                $this->firstValue($row, ['punchTime', 'time', 'eventTime', 'clockTime']),
            );
        }

        return [[
            'biometricUserId' => $this->nullableString($biometricUserId),
            'deviceId' => $deviceId,
            'punchDateTime' => $punchDateTime,
            'punchType' => $this->normalizePunchType($this->firstValue($row, [
                'punchType',
                'eventType',
                'clockType',
                'status',
                'inOut',
            ])),
        ]];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && trim((string) $row[$key]) !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePunchType(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));

        return match ($normalized) {
            'IN', 'I', 'PUNCH IN', 'PUNCH-IN', 'CLOCK IN', 'CLOCK-IN', 'CHECK IN', 'CHECK-IN' => 'IN',
            'OUT', 'O', 'PUNCH OUT', 'PUNCH-OUT', 'CLOCK OUT', 'CLOCK-OUT', 'CHECK OUT', 'CHECK-OUT' => 'OUT',
            default => null,
        };
    }

    private function combineDateAndTime(mixed $date, mixed $time): mixed
    {
        if ($time === null || trim((string) $time) === '') {
            return $date;
        }

        if ($date === null || trim((string) $date) === '') {
            return $time;
        }

        $timeValue = trim((string) $time);
        if (preg_match('/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $timeValue)) {
            return $timeValue;
        }

        return trim((string) $date).' '.$timeValue;
    }
}
