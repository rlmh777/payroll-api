<?php

namespace App\Services\Attendance;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

class BiometricFileParserService
{
    /**
     * Parse a biometric export file and return normalized rows.
     *
     * @return array<int, array{rowNumber:int, biometricUserId:mixed, deviceId:mixed, punchDateTime:mixed, punchType:?string}>
     */
    public function parse(UploadedFile $file, bool $hasHeader = true): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($file, $hasHeader),
            'xlsx' => $this->parseXlsx($file, $hasHeader),
            default => throw new RuntimeException("Unsupported file format: {$extension}"),
        };
    }

    /**
     * @return array<int, array{rowNumber:int, biometricUserId:mixed, deviceId:mixed, punchDateTime:mixed, punchType:?string}>
     */
    private function parseCsv(UploadedFile $file, bool $hasHeader): array
    {
        $path = $file->getRealPath();
        if (!$path) {
            throw new RuntimeException('Unable to access uploaded file.');
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        $rows = [];
        $rowNumber = 0;
        $headerMap = null;
        $delimiter = ',';

        while (($columns = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            if ($rowNumber === 1) {
                $delimiter = $this->detectDelimiter($columns);

                if (count($columns) <= 1) {
                    // Re-read once with detected delimiter from the first line.
                    fclose($handle);
                    $handle = fopen($path, 'rb');
                    if (!$handle) {
                        throw new RuntimeException('Unable to re-open CSV file.');
                    }
                    $rowNumber = 0;
                    continue;
                }
            }

            if ($this->isBlankRow($columns)) {
                continue;
            }

            if ($rowNumber === 1 && $hasHeader) {
                $headerMap = $this->resolveHeaderMap($columns);
                continue;
            }

            array_push($rows, ...$this->buildLogRows(
                $columns,
                $headerMap,
                $rowNumber,
            ));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array{rowNumber:int, biometricUserId:mixed, deviceId:mixed, punchDateTime:mixed, punchType:?string}>
     */
    private function parseXlsx(UploadedFile $file, bool $hasHeader): array
    {
        $path = $file->getRealPath();
        if (!$path) {
            throw new RuntimeException('Unable to access uploaded file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX archive.');
        }

        $sheetPath = $this->firstWorksheetPath($zip);
        if (!$sheetPath) {
            $zip->close();
            throw new RuntimeException('Unable to locate worksheet in XLSX file.');
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException('Unable to read worksheet data.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $zip->close();

        $gridRows = $this->readSheetRows($sheetXml, $sharedStrings);

        $rows = [];
        $headerMap = null;

        foreach ($gridRows as $index => $columns) {
            $rowNumber = $index + 1;
            if ($this->isBlankRow($columns)) {
                continue;
            }

            if ($rowNumber === 1 && $hasHeader) {
                $headerMap = $this->resolveHeaderMap($columns);
                continue;
            }

            array_push($rows, ...$this->buildLogRows(
                $columns,
                $headerMap,
                $rowNumber,
            ));
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $columns
     * @param array<string, int>|null $headerMap
     * @return array<int, array{rowNumber:int, biometricUserId:mixed, deviceId:mixed, punchDateTime:mixed, punchType:?string}>
     */
    private function buildLogRows(array $columns, ?array $headerMap, int $rowNumber): array
    {
        if (!$headerMap) {
            return [[
                'rowNumber' => $rowNumber,
                'biometricUserId' => $columns[0] ?? null,
                'deviceId' => $columns[1] ?? null,
                'punchDateTime' => $columns[2] ?? null,
                'punchType' => null,
            ]];
        }

        $biometricUserId = $this->columnValue($columns, $headerMap, 'biometricUserId');
        $deviceId = $this->columnValue($columns, $headerMap, 'deviceId');
        $punchDate = $this->columnValue($columns, $headerMap, 'punchDate');
        $punchIn = $this->columnValue($columns, $headerMap, 'punchIn');
        $punchOut = $this->columnValue($columns, $headerMap, 'punchOut');

        if ($punchIn !== null || $punchOut !== null) {
            $rows = [];

            if ($punchIn !== null) {
                $rows[] = [
                    'rowNumber' => $rowNumber,
                    'biometricUserId' => $biometricUserId,
                    'deviceId' => $this->columnValue($columns, $headerMap, 'punchInDeviceId') ?? $deviceId,
                    'punchDateTime' => $this->combineDateAndTime($punchDate, $punchIn),
                    'punchType' => 'IN',
                ];
            }

            if ($punchOut !== null) {
                $rows[] = [
                    'rowNumber' => $rowNumber,
                    'biometricUserId' => $biometricUserId,
                    'deviceId' => $this->columnValue($columns, $headerMap, 'punchOutDeviceId') ?? $deviceId,
                    'punchDateTime' => $this->combineDateAndTime($punchDate, $punchOut),
                    'punchType' => 'OUT',
                ];
            }

            return $rows;
        }

        $punchDateTime = $this->columnValue($columns, $headerMap, 'punchDateTime');
        if ($punchDateTime === null) {
            $punchDateTime = $this->combineDateAndTime(
                $punchDate,
                $this->columnValue($columns, $headerMap, 'punchTime'),
            );
        }

        return [[
            'rowNumber' => $rowNumber,
            'biometricUserId' => $biometricUserId,
            'deviceId' => $deviceId,
            'punchDateTime' => $punchDateTime,
            'punchType' => $this->normalizePunchType($this->columnValue($columns, $headerMap, 'punchType')),
        ]];
    }

    /**
     * @param array<int, mixed> $header
     * @return array<string, int>
     */
    private function resolveHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $value) {
            $normalized = $this->normalizeHeader((string) $value);

            if (in_array($normalized, ['biometricuserid', 'userid', 'employeeid', 'employeecode', 'enrollid'], true)) {
                $map['biometricUserId'] = $index;
            }

            if (in_array($normalized, ['deviceid', 'machineid', 'terminalid'], true)) {
                $map['deviceId'] = $index;
            }

            if (in_array($normalized, ['punchindeviceid', 'clockindeviceid', 'checkindeviceid', 'sitepunchin', 'punchinsite'], true)) {
                $map['punchInDeviceId'] = $index;
            }

            if (in_array($normalized, ['punchoutdeviceid', 'clockoutdeviceid', 'checkoutdeviceid', 'sitepunchout', 'punchoutsite'], true)) {
                $map['punchOutDeviceId'] = $index;
            }

            if (in_array($normalized, ['punchdatetime', 'datetime', 'timestamp', 'eventdatetime', 'clockdatetime'], true)) {
                $map['punchDateTime'] = $index;
            }

            if (in_array($normalized, ['date', 'workdate', 'attendancedate', 'punchdate'], true)) {
                $map['punchDate'] = $index;
            }

            if (in_array($normalized, ['time', 'eventtime', 'clocktime', 'punchtime'], true)) {
                $map['punchTime'] = $index;
            }

            if (in_array($normalized, ['punchtype', 'eventtype', 'clocktype', 'status', 'inout'], true)) {
                $map['punchType'] = $index;
            }

            if (in_array($normalized, ['punchin', 'punchintime', 'clockin', 'clockintime', 'checkin', 'checkintime', 'timein'], true)) {
                $map['punchIn'] = $index;
            }

            if (in_array($normalized, ['punchout', 'punchouttime', 'clockout', 'clockouttime', 'checkout', 'checkouttime', 'timeout'], true)) {
                $map['punchOut'] = $index;
            }
        }

        return $map;
    }

    /**
     * @param array<int, mixed> $columns
     * @param array<string, int> $headerMap
     */
    private function columnValue(array $columns, array $headerMap, string $key): mixed
    {
        if (!array_key_exists($key, $headerMap)) {
            return null;
        }

        $value = $columns[$headerMap[$key]] ?? null;

        return $value !== null && trim((string) $value) !== '' ? $value : null;
    }

    private function combineDateAndTime(mixed $date, mixed $time): mixed
    {
        if ($time === null || trim((string) $time) === '') {
            return $date;
        }

        if ($date === null || trim((string) $date) === '') {
            return $time;
        }

        if (is_numeric($date) && is_numeric($time)) {
            return (float) $date + (float) $time;
        }

        $timeValue = trim((string) $time);
        if (preg_match('/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $timeValue)) {
            return $timeValue;
        }

        return trim((string) $date).' '.$timeValue;
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

    /**
     * @param array<int, mixed> $columns
     */
    private function isBlankRow(array $columns): bool
    {
        foreach ($columns as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function detectDelimiter(array $columns): string
    {
        // If `fgetcsv` with comma already split correctly, keep comma.
        if (count($columns) > 1) {
            return ',';
        }

        $line = (string) ($columns[0] ?? '');
        $candidates = [',', ';', "\t", '|'];
        $scores = [];

        foreach ($candidates as $candidate) {
            $scores[$candidate] = substr_count($line, $candidate);
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $best && $scores[$best] > 0 ? $best : ',';
    }

    private function normalizeHeader(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($header))) ?? '';
    }

    private function firstWorksheetPath(ZipArchive $zip): ?string
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name && preg_match('/^xl\/worksheets\/sheet\d+\.xml$/', $name)) {
                $candidates[] = $name;
            }
        }

        sort($candidates, SORT_NATURAL);

        return $candidates[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml === false) {
            return [];
        }

        $xml = simplexml_load_string($sharedXml);
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            // Rich text strings may be split into multiple <r><t> nodes.
            $value = '';
            foreach ($si->r as $run) {
                $value .= (string) ($run->t ?? '');
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function readSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $xml = simplexml_load_string($sheetXml);
        if ($xml === false || !isset($xml->sheetData)) {
            return [];
        }

        $rows = [];

        foreach ($xml->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                preg_match('/[A-Z]+/', $ref, $matches);
                $columnLetters = $matches[0] ?? 'A';
                $columnIndex = $this->columnLettersToIndex($columnLetters);

                $type = (string) ($cell['t'] ?? '');
                $value = '';

                if ($type === 's') {
                    $sharedIndex = (int) ($cell->v ?? 0);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = (string) ($cell->v ?? '');
                }

                $cells[$columnIndex] = $value;
            }

            if (!empty($cells)) {
                ksort($cells);
                $rows[] = array_replace(
                    array_fill(0, max(array_keys($cells)) + 1, ''),
                    $cells,
                );
            }
        }

        return $rows;
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;

        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
        }

        return max(0, $index - 1);
    }
}
