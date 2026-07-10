<?php

namespace App\Http\Controllers;

use App\Enums\LeaveStatusCode;
use App\Models\EmployeeLeave;
use App\Helpers\EmployeeLeaveHelper;
use App\Services\Leave\LeaveEntitlementService;
use App\Services\Leave\LeaveWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EmployeeLeaveController extends Controller
{
    private const LEAVE_RELATIONS = ['employee', 'leaveType', 'leaveStatus', 'approver'];

    public function __construct(
        private readonly LeaveEntitlementService $entitlementService,
        private readonly LeaveWorkflowService $workflowService,
    ) {
    }

    public function approve(Request $request, EmployeeLeave $employeeLeave): JsonResponse
    {
        $status = strtolower((string) $request->input('status', ''));
        $allowed = ['approved', 'rejected', 'cancelled'];

        if (!$status || !in_array($status, $allowed, true)) {
            return response()->json(['error' => 'status must be approved, rejected, or cancelled'], 422);
        }

        $note = $request->input('note');

        if ($status === 'approved') {
            $balanceError = $this->entitlementService->assertSufficientBalance(
                $employeeLeave->employeeId,
                (int) $employeeLeave->leaveTypeId,
                (float) $employeeLeave->totalDays,
                Carbon::parse($employeeLeave->startDate),
            );

            if ($balanceError) {
                return response()->json(['errors' => ['balance' => [$balanceError]]], 422);
            }

            $leave = $this->workflowService->approve($employeeLeave, $request->user());
        } elseif ($status === 'rejected') {
            $leave = $this->workflowService->reject($employeeLeave, $request->user(), is_string($note) ? $note : null);
        } else {
            $leave = $this->workflowService->cancel($employeeLeave, $request->user(), is_string($note) ? $note : null);
        }

        return response()->json($leave);
    }

    public function updateStatus(Request $request, EmployeeLeave $employeeLeave): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject,cancel,submit_for_approval'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = $validated['note'] ?? null;

        if ($validated['action'] === 'approve') {
            $balanceError = $this->entitlementService->assertSufficientBalance(
                $employeeLeave->employeeId,
                (int) $employeeLeave->leaveTypeId,
                (float) $employeeLeave->totalDays,
                Carbon::parse($employeeLeave->startDate),
            );

            if ($balanceError) {
                return response()->json(['errors' => ['balance' => [$balanceError]]], 422);
            }
        }

        $leave = match ($validated['action']) {
            'approve' => $this->workflowService->approve($employeeLeave, $request->user()),
            'reject' => $this->workflowService->reject($employeeLeave, $request->user(), $note),
            'cancel' => $this->workflowService->cancel($employeeLeave, $request->user(), $note),
            'submit_for_approval' => $this->workflowService->submitForFinalApproval($employeeLeave, $request->user()),
        };

        return response()->json($leave);
    }

    public function index(Request $request): JsonResponse
    {
        $query = EmployeeLeave::with(self::LEAVE_RELATIONS);

        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->has('leaveTypeId')) {
            $query->where('leaveTypeId', $request->input('leaveTypeId'));
        }

        if ($request->filled('leaveStatusId')) {
            $query->where('leaveStatusId', $request->integer('leaveStatusId'));
        }

        if ($request->filled('statusCode')) {
            $query->whereHas('leaveStatus', fn ($builder) => $builder->where(
                'code',
                strtoupper((string) $request->input('statusCode')),
            ));
        }

        if ($request->has('startDate')) {
            $query->where('startDate', '>=', $request->input('startDate'));
        }

        if ($request->has('endDate')) {
            $query->where('endDate', '<=', $request->input('endDate'));
        }

        $sortField = $request->input('sortBy', 'startDate');
        $sortDirection = $request->input('sortDirection', 'desc');
        $query->orderBy($sortField, $sortDirection);

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'leaveTypeId' => ['required', 'integer', 'exists:leave_type,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'fromTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'toTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'duration' => ['required', 'in:Full Day,All Days,Morning,Afternoon,Custom'],
            'totalDays' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $balanceError = $this->entitlementService->assertSufficientBalance(
            $request->input('employeeId'),
            (int) $request->input('leaveTypeId'),
            (float) $request->input('totalDays'),
            Carbon::parse($request->input('startDate')),
        );

        if ($balanceError) {
            return response()->json(['errors' => ['balance' => [$balanceError]]], 422);
        }

        $startDate = Carbon::parse($request->input('startDate'));
        $endDate = Carbon::parse($request->input('endDate'));
        $duration = $request->input('duration');
        $employeeId = $request->input('employeeId');

        $overlappingLeaves = $this->checkForOverlappingLeaves(
            $employeeId,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $duration,
            $request->input('fromTime'),
            $request->input('toTime'),
        );

        if (!empty($overlappingLeaves)) {
            return response()->json([
                'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']],
            ], 422);
        }

        $workingDaysCount = EmployeeLeaveHelper::countWorkingDays($startDate, $endDate);

        if ($workingDaysCount <= 1) {
            $employeeLeave = EmployeeLeave::create($this->prepareLeaveData($request->all()));

            return response()->json($employeeLeave->load(self::LEAVE_RELATIONS), 201);
        }

        $isFullDay = in_array($duration, ['Full Day', 'All Days'], true);
        $areConsecutive = EmployeeLeaveHelper::areWorkingDaysConsecutive($startDate, $endDate);

        if ($isFullDay && $areConsecutive) {
            $overlappingLeaves = $this->checkForOverlappingLeaves(
                $employeeId,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $duration,
                $request->input('fromTime'),
                $request->input('toTime'),
            );

            if (!empty($overlappingLeaves)) {
                return response()->json([
                    'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']],
                ], 422);
            }

            $leaveData = $this->prepareLeaveData([
                'employeeId' => $request->input('employeeId'),
                'leaveTypeId' => $request->input('leaveTypeId'),
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
                'fromTime' => $request->input('fromTime'),
                'toTime' => $request->input('toTime'),
                'duration' => $duration,
                'totalDays' => $workingDaysCount * 1.0,
                'notes' => $request->input('notes'),
                'multiplier' => $request->input('multiplier', 1),
                'departmentId' => $request->input('departmentId'),
            ]);

            $employeeLeave = EmployeeLeave::create($leaveData);

            return response()->json($employeeLeave->load(self::LEAVE_RELATIONS), 201);
        }

        $workingDays = EmployeeLeaveHelper::getWorkingDays($startDate, $endDate);
        $createdLeaves = [];

        foreach ($workingDays as $currentDate) {
            $dayStart = $currentDate->format('Y-m-d');
            $dayEnd = $currentDate->format('Y-m-d');

            $overlappingLeaves = $this->checkForOverlappingLeaves(
                $employeeId,
                $dayStart,
                $dayEnd,
                $duration,
                $request->input('fromTime'),
                $request->input('toTime'),
            );

            if (!empty($overlappingLeaves)) {
                return response()->json([
                    'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']],
                ], 422);
            }

            $leaveData = $this->prepareLeaveData([
                'employeeId' => $request->input('employeeId'),
                'leaveTypeId' => $request->input('leaveTypeId'),
                'startDate' => $dayStart,
                'endDate' => $dayEnd,
                'fromTime' => $request->input('fromTime'),
                'toTime' => $request->input('toTime'),
                'duration' => $duration,
                'totalDays' => EmployeeLeaveHelper::calculateTotalDaysForDuration(
                    $duration,
                    $request->input('fromTime'),
                    $request->input('toTime'),
                ),
                'notes' => $request->input('notes'),
                'multiplier' => $request->input('multiplier', 1),
                'departmentId' => $request->input('departmentId'),
            ]);

            $createdLeaves[] = EmployeeLeave::create($leaveData)->load(self::LEAVE_RELATIONS);
        }

        if (empty($createdLeaves)) {
            return response()->json([
                'errors' => ['dateRange' => ['No working days found in the selected date range.']],
            ], 422);
        }

        return response()->json($createdLeaves, 201);
    }

    public function show(EmployeeLeave $employeeLeave): JsonResponse
    {
        return response()->json($employeeLeave->load(self::LEAVE_RELATIONS));
    }

    public function update(Request $request, EmployeeLeave $employeeLeave): JsonResponse
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'leaveTypeId' => ['sometimes', 'integer', 'exists:leave_type,id'],
            'startDate' => ['sometimes', 'date'],
            'endDate' => ['sometimes', 'date'],
            'fromTime' => ['sometimes', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'toTime' => ['sometimes', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'duration' => ['sometimes', 'in:Full Day,All Days,Morning,Afternoon,Custom'],
            'totalDays' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $startDate = $request->input('startDate', $employeeLeave->startDate);
        $endDate = $request->input('endDate', $employeeLeave->endDate);
        $duration = $request->input('duration', $employeeLeave->duration);
        $fromTime = $request->input('fromTime', $employeeLeave->fromTime);
        $toTime = $request->input('toTime', $employeeLeave->toTime);
        $employeeId = $request->input('employeeId', $employeeLeave->employeeId);

        if ($startDate && $endDate && Carbon::parse($endDate)->lt(Carbon::parse($startDate))) {
            return response()->json([
                'errors' => ['endDate' => ['The end date must be after or equal to the start date.']],
            ], 422);
        }

        $overlappingLeaves = $this->checkForOverlappingLeaves(
            $employeeId,
            (string) $startDate,
            (string) $endDate,
            $duration,
            $fromTime,
            $toTime,
            $employeeLeave->id,
        );

        if (!empty($overlappingLeaves)) {
            return response()->json([
                'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']],
            ], 422);
        }

        $employeeLeave->update($request->only([
            'employeeId',
            'leaveTypeId',
            'startDate',
            'endDate',
            'fromTime',
            'toTime',
            'duration',
            'totalDays',
            'notes',
            'multiplier',
            'departmentId',
        ]));

        return response()->json($employeeLeave->fresh()->load(self::LEAVE_RELATIONS));
    }

    public function destroy(EmployeeLeave $employeeLeave): JsonResponse
    {
        $employeeLeave->delete();

        return response()->json(null, 204);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareLeaveData(array $data): array
    {
        $data['leaveStatusId'] = $this->workflowService->defaultStatusId();

        return $data;
    }

    /**
     * @return array<int, EmployeeLeave>
     */
    private function checkForOverlappingLeaves(
        string $employeeId,
        string $startDate,
        string $endDate,
        string $duration,
        ?string $fromTime,
        ?string $toTime,
        ?string $excludeLeaveId = null,
    ): array {
        $query = EmployeeLeave::query()
            ->where('employeeId', $employeeId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->where('startDate', '<=', $endDate)
                    ->where('endDate', '>=', $startDate);
            })
            ->whereHas('leaveStatus', fn ($builder) => $builder->whereNotIn('code', [
                LeaveStatusCode::Cancelled->value,
                LeaveStatusCode::Rejected->value,
            ]));

        if ($excludeLeaveId) {
            $query->where('id', '!=', $excludeLeaveId);
        }

        $existingLeaves = $query->get();
        $overlappingLeaves = [];

        foreach ($existingLeaves as $existingLeave) {
            if (EmployeeLeaveHelper::doLeavesOverlap(
                $startDate,
                $endDate,
                $duration,
                $fromTime,
                $toTime,
                $existingLeave->startDate,
                $existingLeave->endDate,
                $existingLeave->duration,
                $existingLeave->fromTime,
                $existingLeave->toTime,
            )) {
                $overlappingLeaves[] = $existingLeave;
            }
        }

        return $overlappingLeaves;
    }
}
