<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\ScheduledWork;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\ScheduledWorkOverlapValidator;
use App\Modules\Hr\Services\Attendance\TimesheetCompensationRecalculationService;
use App\Modules\Hr\Services\Employment\EmploymentContractAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ScheduledWorkController extends Controller
{
    private const RELATIONS = [
        'employee',
        'department',
        'worksite',
        'employmentDetail.department',
        'employmentDetail.worksite',
        'employmentDetail.contractType',
        'employmentDetail.defaultPayPeriodGroup',
    ];

    public function __construct(
        private readonly ScheduledWorkOverlapValidator $overlapValidator,
        private readonly TimesheetCompensationRecalculationService $timesheetRecalculationService,
        private readonly EmploymentContractAssignmentService $contractAssignmentService,
    ) {
    }
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'startDate' => "{$required}|date",
            'endDate' => "{$required}|date",
            'startTime' => 'nullable|date_format:H:i',
            'endTime' => 'nullable|date_format:H:i',
            'employeeId' => 'nullable|uuid|exists:employee,id',
            'employmentDetailId' => 'nullable|uuid|exists:employment_detail,id',
            'departmentId' => 'nullable|integer|exists:department,id',
            'worksiteId' => 'nullable|integer|exists:worksite,id',
            'includeLunchHour' => 'nullable|boolean',
            'lunchHourHours' => 'nullable|numeric|min:0|max:8',
            'description' => 'nullable|string|max:1024',
            'rate' => 'nullable|numeric|min:0|max:9.99',
        ];
    }

    private function normalizePayload(array $data): array
    {
        $startDate = !empty($data['startDate'])
            ? Carbon::parse($data['startDate'])->toDateString()
            : null;
        $endDate = !empty($data['endDate'])
            ? Carbon::parse($data['endDate'])->toDateString()
            : $startDate;

        if ($startDate && $endDate && $endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $data['startDate'] = $startDate;
        $data['endDate'] = $endDate;
        $data['startTime'] = $this->normalizeTimeValue($data['startTime'] ?? null) ?? '09:00';
        $data['endTime'] = $this->normalizeTimeValue($data['endTime'] ?? null) ?? '17:00';
        $data['lunchHourHours'] = $data['lunchHourHours'] ?? 1;

        return $data;
    }

    private function normalizeTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->format('H:i');
    }

    private function normalizeTimeFields(Request $request): void
    {
        $normalized = [];

        foreach (['startTime', 'endTime'] as $field) {
            $value = $request->input($field);
            if (!is_string($value) || $value === '') {
                continue;
            }

            $normalized[$field] = substr($value, 0, 5);
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = ScheduledWork::query()->with(self::RELATIONS);

        if ($request->filled('start') && $request->filled('end')) {
            $query->whereDate('startDate', '<=', $request->string('end'))
                ->whereDate('endDate', '>=', $request->string('start'));
        }

        if ($request->filled('employee_id')) {
            $query->where(function ($builder) use ($request) {
                $builder->whereNull('employeeId')
                    ->orWhere('employeeId', $request->string('employee_id'));
            });
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('description', 'ilike', "%{$search}%");
        }

        $sortField = $request->get('sort_by', 'startDate');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['startDate', 'endDate', 'description', 'rate'], true)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('startDate', 'asc');
        }

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $this->normalizeTimeFields($request);
            $validatedData = $request->validate($this->rules());
            $payload = $this->normalizePayload([
                'startDate' => $validatedData['startDate'],
                'endDate' => $validatedData['endDate'],
                'startTime' => $validatedData['startTime'] ?? null,
                'endTime' => $validatedData['endTime'] ?? null,
                'employeeId' => $validatedData['employeeId'] ?? null,
                'employmentDetailId' => $validatedData['employmentDetailId'] ?? null,
                'departmentId' => $validatedData['departmentId'] ?? null,
                'worksiteId' => $validatedData['worksiteId'] ?? null,
                'includeLunchHour' => (bool) ($validatedData['includeLunchHour'] ?? false),
                'lunchHourHours' => $validatedData['lunchHourHours'] ?? 1,
                'description' => trim((string) ($validatedData['description'] ?? '')),
                'rate' => $validatedData['rate'] ?? 1,
            ]);
            $payload = $this->applyEmploymentAssignment($payload);

            $this->overlapValidator->validate(
                $payload['employeeId'] ?? null,
                $payload['startDate'],
                $payload['endDate'],
                $payload['startTime'],
                $payload['endTime'],
            );

            $scheduledWork = ScheduledWork::create($payload);
            $this->recalculateScheduledWorkImpact($this->scheduledWorkPayload($scheduledWork));

            return response()->json([
                'message' => 'Scheduled work created successfully',
                'data' => $scheduledWork->load(self::RELATIONS),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function show(ScheduledWork $scheduledWork): JsonResponse
    {
        return response()->json($scheduledWork->load(self::RELATIONS));
    }

    public function update(Request $request, ScheduledWork $scheduledWork): JsonResponse
    {
        try {
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $scheduledWork,
                ], 200);
            }

            $this->normalizeTimeFields($request);
            $validatedData = $request->validate($this->rules(true));
            $before = $this->scheduledWorkPayload($scheduledWork);
            $payload = $this->normalizePayload(array_merge(
                $scheduledWork->only([
                    'startDate',
                    'endDate',
                    'startTime',
                    'endTime',
                    'employeeId',
                    'employmentDetailId',
                    'departmentId',
                    'worksiteId',
                    'includeLunchHour',
                    'lunchHourHours',
                    'description',
                    'rate',
                ]),
                $validatedData,
            ));
            $payload = $this->applyEmploymentAssignment($payload);

            $this->overlapValidator->validate(
                $payload['employeeId'] ?? null,
                $payload['startDate'],
                $payload['endDate'],
                $payload['startTime'],
                $payload['endTime'],
                $scheduledWork->id,
            );

            $scheduledWork->update($payload);
            $this->recalculateScheduledWorkImpact(
                $before,
                $this->scheduledWorkPayload($scheduledWork->fresh()),
            );

            return response()->json([
                'message' => 'Scheduled work updated successfully',
                'data' => $scheduledWork->fresh(self::RELATIONS),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function destroy(ScheduledWork $scheduledWork): JsonResponse
    {
        $before = $this->scheduledWorkPayload($scheduledWork);
        $scheduledWork->delete();
        $this->recalculateScheduledWorkImpact($before);

        return response()->json(['message' => 'Scheduled work deleted successfully.']);
    }

    /**
     * @return array{employeeId:?string,departmentId:?int,startDate:string,endDate:string}
     */
    private function scheduledWorkPayload(ScheduledWork $scheduledWork): array
    {
        return [
            'employeeId' => $scheduledWork->employeeId ? (string) $scheduledWork->employeeId : null,
            'departmentId' => $scheduledWork->departmentId ? (int) $scheduledWork->departmentId : null,
            'startDate' => Carbon::parse($scheduledWork->startDate)->toDateString(),
            'endDate' => Carbon::parse($scheduledWork->endDate)->toDateString(),
        ];
    }

    /**
     * @param array{employeeId:?string,departmentId:?int,startDate:string,endDate:string} ...$payloads
     */
    private function recalculateScheduledWorkImpact(array ...$payloads): void
    {
        if ($payloads === []) {
            return;
        }

        $startDate = collect($payloads)->min('startDate');
        $endDate = collect($payloads)->max('endDate');
        $allActiveCompensation = false;
        $employeeIds = [];

        foreach ($payloads as $payload) {
            if ($payload['employeeId']) {
                $employeeIds[] = $payload['employeeId'];
                continue;
            }

            if ($payload['departmentId']) {
                $employeeIds = array_merge(
                    $employeeIds,
                    Timesheet::query()
                        ->where('departmentId', $payload['departmentId'])
                        ->whereDate('date', '>=', $payload['startDate'])
                        ->whereDate('date', '<=', $payload['endDate'])
                        ->pluck('employeeId')
                        ->filter()
                        ->map(fn ($employeeId) => (string) $employeeId)
                        ->all(),
                );
                continue;
            }

            $allActiveCompensation = true;
        }

        $this->timesheetRecalculationService->recalculate(
            $allActiveCompensation ? [] : array_values(array_unique($employeeIds)),
            $startDate,
            $endDate,
            $allActiveCompensation,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyEmploymentAssignment(array $payload): array
    {
        $contractId = $this->contractAssignmentService->resolveContractId(
            $payload['employeeId'] ?? null,
            $payload['employmentDetailId'] ?? null,
            isset($payload['departmentId']) ? (int) $payload['departmentId'] : null,
            (string) $payload['startDate'],
            (string) $payload['endDate'],
        );

        if (!$contractId) {
            return $payload;
        }

        $payload['employmentDetailId'] = $contractId;

        return $payload;
    }
}
