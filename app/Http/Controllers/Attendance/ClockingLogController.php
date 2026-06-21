<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ImportClockingLogRequest;
use App\Http\Requests\Attendance\ProcessClockingLogRequest;
use App\Http\Requests\Attendance\StoreClockingLogRequest;
use App\Jobs\ProcessClockingLogsJob;
use App\Models\ClockingLog;
use App\Models\PayPeriodSchedule;
use App\Services\Attendance\BiometricFileParserService;
use App\Services\Attendance\ClockingLogIngestionService;
use App\Services\Attendance\TimesheetProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClockingLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ClockingLog::query()->orderByDesc('punchDateTime');

        if ($request->filled('startDate')) {
            $query->whereDate('punchDateTime', '>=', $request->string('startDate'));
        }

        if ($request->filled('endDate')) {
            $query->whereDate('punchDateTime', '<=', $request->string('endDate'));
        }

        if ($request->filled('biometricUserId')) {
            $query->where('biometricUserId', $request->string('biometricUserId'));
        }

        if ($request->filled('deviceId')) {
            $query->where('deviceId', $request->string('deviceId'));
        }

        $perPage = (int) $request->integer('per_page', 50);
        $logs = $query->paginate(max(1, min($perPage, 500)));

        return response()->json($logs);
    }

    public function store(
        StoreClockingLogRequest $request,
        ClockingLogIngestionService $ingestionService
    ): JsonResponse {
        $summary = $ingestionService->ingest($request->validated('logs'));

        return response()->json([
            'message' => 'Clocking logs received.',
            ...$summary,
        ], 201);
    }

    public function import(
        ImportClockingLogRequest $request,
        BiometricFileParserService $fileParser,
        ClockingLogIngestionService $ingestionService
    ): JsonResponse {
        $validated = $request->validated();
        $rows = $fileParser->parse(
            $request->file('file'),
            (bool) ($validated['hasHeader'] ?? true),
        );

        $defaultDeviceId = isset($validated['defaultDeviceId']) && trim((string) $validated['defaultDeviceId']) !== ''
            ? trim((string) $validated['defaultDeviceId'])
            : null;

        if ($defaultDeviceId !== null) {
            $rows = array_map(function (array $row) use ($defaultDeviceId) {
                $deviceId = trim((string) ($row['deviceId'] ?? ''));
                if ($deviceId === '') {
                    $row['deviceId'] = $defaultDeviceId;
                }

                return $row;
            }, $rows);
        }

        $summary = $ingestionService->ingest($rows);

        return response()->json([
            'message' => 'Biometric file imported.',
            'fileName' => $request->file('file')->getClientOriginalName(),
            ...$summary,
        ], 201);
    }

    public function process(
        ProcessClockingLogRequest $request,
        TimesheetProcessingService $processingService
    ): JsonResponse {
        $validated = $request->validated();
        $payPeriod = isset($validated['payPeriodScheduleId'])
            ? PayPeriodSchedule::query()->findOrFail($validated['payPeriodScheduleId'])
            : null;

        $filters = array_filter([
            'startDate' => $payPeriod?->start_date?->toDateString() ?? ($validated['startDate'] ?? null),
            'endDate' => $payPeriod?->end_date?->toDateString() ?? ($validated['endDate'] ?? null),
            'biometricUserId' => $validated['biometricUserId'] ?? null,
            'overtimeThresholdHours' => $validated['overtimeThresholdHours'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (($validated['queue'] ?? true) === true) {
            ProcessClockingLogsJob::dispatch($filters);

            return response()->json([
                'message' => 'Timesheet processing queued.',
                'queued' => true,
                'filters' => $filters,
                'payPeriod' => $this->payPeriodPayload($payPeriod),
            ], 202);
        }

        $result = $processingService->process($filters);

        return response()->json([
            'message' => 'Timesheet processing completed.',
            'queued' => false,
            'payPeriod' => $this->payPeriodPayload($payPeriod),
            ...$result,
        ]);
    }

    private function payPeriodPayload(?PayPeriodSchedule $payPeriod): ?array
    {
        if (!$payPeriod) {
            return null;
        }

        return [
            'id' => $payPeriod->id,
            'startDate' => $payPeriod->start_date?->toDateString(),
            'endDate' => $payPeriod->end_date?->toDateString(),
            'payDate' => $payPeriod->pay_date?->toDateString(),
        ];
    }
}
