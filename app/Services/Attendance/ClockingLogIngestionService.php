<?php

namespace App\Services\Attendance;

use App\Models\ClockingLog;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

class ClockingLogIngestionService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{
     *   totalUploaded:int,
     *   inserted:int,
     *   duplicatesSkipped:int,
     *   failedRows:array<int, array{rowNumber:int|string,error:string,row:array<string,mixed>}>
     * }
     */
    public function ingest(array $rows): array
    {
        $summary = [
            'totalUploaded' => count($rows),
            'inserted' => 0,
            'duplicatesSkipped' => 0,
            'failedRows' => [],
        ];

        foreach ($rows as $index => $row) {
            $rowNumber = $row['rowNumber'] ?? ($index + 1);

            try {
                $normalized = $this->normalizeRow($row);
                $duplicate = $this->isDuplicate(
                    $normalized['biometricUserId'],
                    $normalized['deviceId'],
                    $normalized['punchDateTime'],
                );

                if ($duplicate) {
                    $summary['duplicatesSkipped']++;
                    continue;
                }

                ClockingLog::create($normalized);
                $summary['inserted']++;
            } catch (\Throwable $e) {
                if ($this->isUniqueViolation($e)) {
                    $summary['duplicatesSkipped']++;
                    continue;
                }

                $summary['failedRows'][] = [
                    'rowNumber' => $rowNumber,
                    'error' => $e->getMessage(),
                    'row' => $row,
                ];
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{biometricUserId:string, deviceId:?string, punchDateTime:string, punchType:?string}
     */
    private function normalizeRow(array $row): array
    {
        $biometricUserId = trim((string) ($row['biometricUserId'] ?? ''));
        if ($biometricUserId === '') {
            throw new \InvalidArgumentException('biometricUserId is required.');
        }

        $deviceId = array_key_exists('deviceId', $row) ? trim((string) ($row['deviceId'] ?? '')) : '';
        $deviceId = $deviceId !== '' ? $deviceId : null;

        $punchDateTime = $this->normalizeDateTime($row['punchDateTime'] ?? null);
        if ($punchDateTime === null) {
            throw new \InvalidArgumentException('punchDateTime is invalid.');
        }

        $punchType = $this->normalizePunchType($row['punchType'] ?? null);

        return [
            'biometricUserId' => $biometricUserId,
            'deviceId' => $deviceId,
            'punchDateTime' => $punchDateTime->format('Y-m-d H:i:s'),
            'punchType' => $punchType,
        ];
    }

    private function normalizeDateTime(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            // Excel serial date support for xlsx uploads.
            if (is_numeric($value) && (float) $value > 0) {
                $seconds = ((float) $value - 25569) * 86400;
                return Carbon::createFromTimestampUTC((int) round($seconds))
                    ->setTimezone(config('app.timezone', 'UTC'));
            }

            return Carbon::parse((string) $value, config('app.timezone', 'UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isDuplicate(string $biometricUserId, ?string $deviceId, string $punchDateTime): bool
    {
        $query = ClockingLog::query()
            ->where('biometricUserId', $biometricUserId)
            ->where('punchDateTime', $punchDateTime);

        if ($deviceId === null) {
            $query->whereNull('deviceId');
        } else {
            $query->where('deviceId', $deviceId);
        }

        return $query->exists();
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

    private function isUniqueViolation(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }

        $sqlState = $e->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
