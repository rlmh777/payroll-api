<?php

namespace App\Modules\Hr\Http\Controllers\Attendance;

use App\Enums\LeaveStatusCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ApproveTimesheetRequest;
use App\Http\Requests\Attendance\BulkApproveTimesheetRequest;
use App\Http\Requests\Attendance\ResolveTimesheetLeaveConflictRequest;
use App\Http\Requests\Attendance\UpdateTimesheetCommentRequest;
use App\Http\Requests\Attendance\UpdateTimesheetLunchHoursRequest;
use App\Http\Requests\Attendance\UpdateTimesheetPaidStatusRequest;
use App\Http\Requests\Attendance\UpdateTimesheetRoundOffRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\AttendanceSetting;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetEditLockService;
use App\Modules\Hr\Services\Attendance\TimesheetIssueNotifier;
use App\Modules\Hr\Services\Attendance\TimesheetLeaveConflictResolver;
use App\Modules\Hr\Services\Attendance\TimesheetLeaveConflictService;
use App\Modules\Hr\Services\Attendance\TimesheetLunchBreakResolver;
use App\Modules\Hr\Services\Attendance\TimesheetCompensationRecalculationService;
use App\Modules\Hr\Services\Attendance\TimesheetPayrollPaidStatusService;
use App\Modules\Hr\Services\Attendance\TimesheetProcessingService;
use App\Modules\Hr\Services\Attendance\TimesheetOverlapValidator;
use App\Modules\Hr\Services\Attendance\TimesheetPayFields;
use App\Modules\Hr\Services\Attendance\TimesheetRoundOffService;
use App\Modules\Hr\Services\Attendance\TimesheetScheduleCoverageService;
use App\Modules\Hr\Services\Attendance\TimesheetScopeService;
use App\Modules\Payroll\Services\PayrollRunFrequencyResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimesheetController extends Controller
{
    public function __construct(
        private readonly TimesheetScopeService $timesheetScope,
        private readonly TimesheetRoundOffService $timesheetRoundOffService,
        private readonly TimesheetScheduleCoverageService $scheduleCoverage,
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
        private readonly TimesheetOverlapValidator $timesheetOverlapValidator,
        private readonly TimesheetLeaveConflictService $leaveConflictService,
        private readonly TimesheetLeaveConflictResolver $leaveConflictResolver,
        private readonly TimesheetIssueNotifier $issueNotifier,
        private readonly TimesheetCompensationRecalculationService $compensationRecalculationService,
        private readonly TimesheetProcessingService $timesheetProcessingService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
        private readonly TimesheetEditLockService $timesheetEditLockService,
        private readonly TimesheetPayrollPaidStatusService $timesheetPayrollPaidStatusService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->recalculateCurrentAndFutureTimesheets($request);

        $query = Timesheet::query()
            ->with([
                'employee',
                'approver',
                'updater:id,name,email',
                'department:id,name',
                'worksite:id,name',
                'employmentDetail.department',
                'employmentDetail.worksite',
                'employmentDetail.contractType',
                'employmentDetail.defaultPayPeriodGroup',
                'employeeCompensation',
            ]);

        $this->applyFilters($query, $request);
        $summary = $this->buildSummary($query);

        $perPage = (int) $request->integer('per_page', 50);
        $timesheets = $query
            ->orderByDesc('date')
            ->orderBy('employeeId')
            ->orderBy('slotIndex')
            ->paginate(max(1, min($perPage, 500)));

        $scheduleSlots = $this->scheduleCoverage->buildScheduleSlots($timesheets->getCollection());
        $this->timesheetEditLockService->warmCache($timesheets->getCollection());
        $this->timesheetPayrollPaidStatusService->warmCache($timesheets->getCollection());

        $timesheets->getCollection()->transform(function (Timesheet $timesheet) use ($scheduleSlots) {
            return $this->transformTimesheet($timesheet, $scheduleSlots);
        });

        $payload = $timesheets->toArray();
        $payload['summary'] = $this->formatSummary($summary);
        $payload['scheduleComparisonSource'] = AttendanceSetting::current()->scheduleComparisonSourceEnum()->value;

        return response()->json($payload);
    }

    public function employeeSummary(Request $request): JsonResponse
    {
        $this->recalculateCurrentAndFutureTimesheets($request);

        $filteredQuery = Timesheet::query();
        $this->applyFilters($filteredQuery, $request);
        $summary = $this->buildSummary($filteredQuery);

        $perPage = (int) $request->integer('per_page', 25);
        $employeeSummaries = (clone $filteredQuery)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('
                "employeeId",
                "employmentDetailId",
                MAX("departmentId") as "departmentId",
                MAX("payType") as "payType",
                MIN("date") as "periodStart",
                MAX("date") as "periodEnd",
                COUNT(DISTINCT "date") as "workDays",
                COALESCE(SUM("hoursWorked"), 0) as "hoursWorked",
                COALESCE(SUM("regularHours"), 0) as "regularHours",
                COALESCE(SUM("overtimeHours"), 0) as "overtimeHours",
                COALESCE(SUM("holidayHours"), 0) as "holidayHours",
                COALESCE(SUM("unpaidHours"), 0) as "unpaidHours",
                COALESCE(SUM("paidHours"), 0) as "paidHours",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") IN (\'PENDING\', \'PENDING_SUPERVISOR\') THEN 1 ELSE 0 END), 0) as "pendingCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'APPROVED\' THEN 1 ELSE 0 END), 0) as "approvedCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'REJECTED\' THEN 1 ELSE 0 END), 0) as "rejectedCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") != \'APPROVED\' AND "remarks" IS NOT NULL AND TRIM("remarks") != \'\' THEN 1 ELSE 0 END), 0) as "issueCount"
            ')
            ->groupBy('employeeId', 'employmentDetailId')
            ->orderBy('employeeId')
            ->paginate(max(1, min($perPage, 100)));

        $employeeIds = $employeeSummaries->getCollection()
            ->pluck('employeeId')
            ->filter()
            ->values();
        $departmentIds = $employeeSummaries->getCollection()
            ->pluck('departmentId')
            ->filter()
            ->unique()
            ->values();
        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');
        $departments = Department::query()
            ->whereIn('id', $departmentIds)
            ->get()
            ->keyBy('id');
        $employmentDetails = EmploymentDetail::query()
            ->with(['department', 'worksite', 'contractType', 'defaultPayPeriodGroup'])
            ->whereIn('id', $employeeSummaries->getCollection()->pluck('employmentDetailId')->filter()->values())
            ->get()
            ->keyBy('id');

        $employeeSummaries->getCollection()->transform(function ($row) use ($employees, $departments, $employmentDetails) {
            $employee = $employees->get($row->employeeId);
            $employmentDetail = $row->employmentDetailId ? $employmentDetails->get($row->employmentDetailId) : null;
            $employeeName = trim(sprintf(
                '%s %s',
                $employee?->firstName ?? '',
                $employee?->lastName ?? ''
            ));
            $pendingCount = (int) $row->pendingCount;
            $approvedCount = (int) $row->approvedCount;
            $rejectedCount = (int) $row->rejectedCount;
            $issueCount = (int) $row->issueCount;

            return [
                'employeeId' => $row->employeeId,
                'employmentDetailId' => $row->employmentDetailId,
                'employmentContractLabel' => $employmentDetail ? $this->employmentContractLabel($employmentDetail) : null,
                'employeeCode' => $employee?->code,
                'employeeName' => $employeeName !== '' ? $employeeName : null,
                'departmentId' => $row->departmentId,
                'departmentName' => $departments->get($row->departmentId)?->name,
                'payType' => $row->payType ? strtoupper((string) $row->payType) : null,
                'periodStart' => $row->periodStart,
                'periodEnd' => $row->periodEnd,
                'workDays' => (int) $row->workDays,
                'hoursWorked' => (float) $row->hoursWorked,
                'regularHours' => (float) $row->regularHours,
                'overtimeHours' => (float) $row->overtimeHours,
                'holidayHours' => (float) $row->holidayHours,
                'unpaidHours' => (float) $row->unpaidHours,
                'paidHours' => (float) ($row->paidHours ?? 0),
                'pendingCount' => $pendingCount,
                'approvedCount' => $approvedCount,
                'rejectedCount' => $rejectedCount,
                'issueCount' => $issueCount,
                'approvalStatus' => $this->summaryApprovalStatus(
                    $pendingCount,
                    $approvedCount,
                    $rejectedCount,
                    $issueCount
                ),
            ];
        });

        $payload = $employeeSummaries->toArray();
        $payload['summary'] = $this->formatSummary($summary);

        return response()->json($payload);
    }

    public function updatePaidStatus(UpdateTimesheetPaidStatusRequest $request, Timesheet $timesheet): JsonResponse
    {
        $this->ensureTimesheetInScope($timesheet, $request);

        if ($lockedResponse = $this->lockedTimesheetResponse($timesheet)) {
            return $lockedResponse;
        }

        if (strtoupper((string) $timesheet->approvalStatus) === 'APPROVED') {
            return response()->json([
                'message' => 'Approved timesheets cannot be edited.',
            ], 422);
        }

        $isPaid = (bool) $request->validated()['isPaid'];
        $payFields = TimesheetPayFields::fromStoredHours(
            $isPaid,
            (float) ($timesheet->regularHours ?? 0),
            (float) ($timesheet->overtimeHours ?? 0),
            (float) ($timesheet->holidayHours ?? 0),
            (float) ($timesheet->hoursWorked ?? 0),
        );

        $timesheet->isPaid = $payFields['isPaid'];
        $timesheet->paidHours = $payFields['paidHours'];
        $timesheet->unpaidHours = $payFields['unpaidHours'];

        if (!in_array(strtoupper((string) $timesheet->approvalStatus), ['APPROVED', 'REJECTED'], true)) {
            $timesheet->approvalStatus = $this->pendingApprovalStatusFor($timesheet);
        }

        $this->markTimesheetUpdated($timesheet, $request);
        $timesheet->save();

        return response()->json($this->buildTimesheetMutationResponse(
            'Timesheet paid status updated.',
            $timesheet,
        ));
    }

    public function updateRoundOff(UpdateTimesheetRoundOffRequest $request, Timesheet $timesheet): JsonResponse
    {
        $this->ensureTimesheetInScope($timesheet, $request);

        if ($lockedResponse = $this->lockedTimesheetResponse($timesheet)) {
            return $lockedResponse;
        }

        if (strtoupper((string) $timesheet->approvalStatus) === 'APPROVED') {
            return response()->json([
                'message' => 'Approved timesheets cannot be edited.',
            ], 422);
        }

        $validated = $request->validated();
        $roundOffIn = !empty($validated['roundOffClockInTime'])
            ? Carbon::parse($validated['roundOffClockInTime'])
            : null;
        $roundOffOut = !empty($validated['roundOffClockOutTime'])
            ? Carbon::parse($validated['roundOffClockOutTime'])
            : null;

        $this->timesheetOverlapValidator->assertNoOverlapWithExisting(
            $timesheet->employeeId,
            $timesheet->date->format('Y-m-d'),
            $roundOffIn,
            $roundOffOut,
            $timesheet->id,
        );

        $this->timesheetRoundOffService->recalculate($timesheet, $roundOffIn, $roundOffOut);
        $timesheet->refresh();

        if (!in_array(strtoupper((string) $timesheet->approvalStatus), ['APPROVED', 'REJECTED'], true)) {
            $timesheet->approvalStatus = $this->pendingApprovalStatusFor($timesheet);
            $this->markTimesheetUpdated($timesheet, $request);
            $timesheet->save();
        } else {
            $this->markTimesheetUpdated($timesheet, $request);
            $timesheet->save();
        }

        $this->applyLeaveConflictIfNeeded($timesheet, $roundOffIn, $roundOffOut);

        return response()->json($this->buildTimesheetMutationResponse(
            'Timesheet round-off times updated.',
            $timesheet->fresh(),
        ));
    }

    public function updateLunchHours(UpdateTimesheetLunchHoursRequest $request, Timesheet $timesheet): JsonResponse
    {
        $this->ensureTimesheetInScope($timesheet, $request);

        if ($lockedResponse = $this->lockedTimesheetResponse($timesheet)) {
            return $lockedResponse;
        }

        if (strtoupper((string) $timesheet->approvalStatus) === 'APPROVED') {
            return response()->json([
                'message' => 'Approved timesheets cannot be edited.',
            ], 422);
        }

        $hours = round((float) $request->validated()['lunchHourHours'], 2);
        $timesheet->includeLunchHour = $hours > 0;
        $timesheet->lunchHourHours = $hours;
        $timesheet->save();

        $this->timesheetRoundOffService->recalculate(
            $timesheet,
            $timesheet->roundOffClockInTime ? Carbon::parse($timesheet->roundOffClockInTime) : null,
            $timesheet->roundOffClockOutTime ? Carbon::parse($timesheet->roundOffClockOutTime) : null,
        );
        $timesheet->refresh();

        if (!in_array(strtoupper((string) $timesheet->approvalStatus), ['APPROVED', 'REJECTED'], true)) {
            $timesheet->approvalStatus = $this->pendingApprovalStatusFor($timesheet);
            $this->markTimesheetUpdated($timesheet, $request);
            $timesheet->save();
        } else {
            $this->markTimesheetUpdated($timesheet, $request);
            $timesheet->save();
        }

        return response()->json($this->buildTimesheetMutationResponse(
            'Timesheet lunch hours updated.',
            $timesheet->fresh(),
        ));
    }

    public function updateComment(UpdateTimesheetCommentRequest $request, Timesheet $timesheet): JsonResponse
    {
        $this->ensureTimesheetInScope($timesheet, $request);

        if ($lockedResponse = $this->lockedTimesheetResponse($timesheet)) {
            return $lockedResponse;
        }

        $comment = trim((string) ($request->validated()['comment'] ?? ''));
        $timesheet->comment = $comment === '' ? null : $comment;
        $this->markTimesheetUpdated($timesheet, $request);
        $timesheet->save();

        return response()->json($this->buildTimesheetMutationResponse(
            'Timesheet comment updated.',
            $timesheet,
        ));
    }

    public function updateApproval(ApproveTimesheetRequest $request, Timesheet $timesheet): JsonResponse
    {
        $this->ensureTimesheetInScope($timesheet, $request);

        if ($lockedResponse = $this->lockedTimesheetResponse($timesheet)) {
            return $lockedResponse;
        }

        $validated = $request->validated();
        $requestedStatus = strtoupper((string) $validated['approvalStatus']);
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (
            $requestedStatus === 'APPROVED'
            && $this->leaveConflictResolver->hasUnresolvedLeaveConflict($timesheet)
        ) {
            return response()->json([
                'message' => 'Resolve the leave conflict before approving this timesheet.',
            ], 422);
        }

        $approvalStatus = $this->timesheetScope->resolveApprovalTarget($user, $timesheet, $requestedStatus);
        $timesheet->approvalStatus = $approvalStatus;

        if (in_array($approvalStatus, ['PENDING', 'PENDING_SUPERVISOR'], true)) {
            $timesheet->approvedAt = null;
            $timesheet->approvedBy = null;
        } else {
            $timesheet->approvedAt = now();
            $timesheet->approvedBy = $this->resolveApproverId($request, $validated['approvedBy'] ?? null);
        }

        if (isset($validated['remarks']) && trim((string) $validated['remarks']) !== '') {
            $newRemarks = trim((string) $validated['remarks']);
            $existing = trim((string) $timesheet->remarks);
            $timesheet->remarks = $existing === '' ? $newRemarks : "{$existing}\n{$newRemarks}";
        }

        $this->markTimesheetUpdated($timesheet, $request);
        $timesheet->save();

        $freshTimesheet = $timesheet->fresh([
            'employee',
            'approver',
            'updater:id,name,email',
            'department:id,name',
            'worksite:id,name',
        ]);
        $scheduleSlots = $this->scheduleCoverage->buildScheduleSlots(collect([$freshTimesheet]));

        return response()->json([
            'message' => 'Timesheet approval updated.',
            'data' => $this->transformTimesheet($freshTimesheet, $scheduleSlots),
        ]);
    }

    public function recalculateCompensation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeIds' => ['nullable', 'array'],
            'employeeIds.*' => ['uuid', 'exists:employee,id'],
            'allActiveCompensation' => ['nullable', 'boolean'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'payPeriodGroupId' => ['nullable', 'uuid', 'exists:pay_period_groups,id'],
        ]);

        $employeeIds = $this->employeeIdsForRecalculation($request, $validated['employeeIds'] ?? []);

        $result = $this->compensationRecalculationService->recalculate(
            $employeeIds,
            $validated['startDate'] ?? null,
            $validated['endDate'] ?? null,
            false,
            true,
        );

        return response()->json([
            'message' => 'Timesheet compensation recalculated.',
            ...$result,
        ]);
    }

    public function resolveLeaveConflict(
        ResolveTimesheetLeaveConflictRequest $request,
        Timesheet $timesheet,
    ): JsonResponse {
        $this->ensureTimesheetInScope($timesheet, $request);

        if ($lockedResponse = $this->lockedTimesheetResponse($timesheet)) {
            return $lockedResponse;
        }

        if (strtoupper((string) $timesheet->approvalStatus) === 'APPROVED') {
            return response()->json([
                'message' => 'Approved timesheets cannot be updated.',
            ], 422);
        }

        $resolver = $request->user()
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : null;

        if (!$resolver) {
            return response()->json([
                'message' => 'An employee profile is required to resolve leave conflicts.',
            ], 422);
        }

        try {
            $resolved = $this->leaveConflictResolver->authorizeWork(
                $timesheet,
                $resolver,
                trim((string) $request->validated()['note']),
            );
        } catch (ValidationException $exception) {
            return response()->json(['errors' => $exception->errors()], 422);
        }

        $this->markTimesheetUpdated($resolved, $request);
        $resolved->save();

        $freshTimesheet = $resolved->fresh([
            'employee',
            'approver',
            'updater:id,name,email',
            'department:id,name',
            'worksite:id,name',
        ]);
        $scheduleSlots = $this->scheduleCoverage->buildScheduleSlots(collect([$freshTimesheet]));

        return response()->json([
            'message' => 'Leave conflict resolved. Conflicting leave was cancelled and timesheet hours were recalculated.',
            'data' => $this->transformTimesheet($freshTimesheet, $scheduleSlots),
        ]);
    }

    public function updateBulkApproval(BulkApproveTimesheetRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $approverId = $this->resolveApproverId($request, $validated['approvedBy'] ?? null);
        $reviewedAt = now();
        $remarks = trim((string) ($validated['remarks'] ?? ''));

        $updatedCount = DB::transaction(function () use ($validated, $approverId, $reviewedAt, $remarks, $request) {
            $query = Timesheet::query()
                ->whereIn('id', $validated['timesheetIds'])
                ->where(function ($query) {
                    $query->whereRaw('UPPER("approvalStatus") = ?', ['PENDING'])
                        ->orWhereRaw('UPPER("approvalStatus") = ?', ['PENDING_SUPERVISOR']);
                })
                ->where(function ($query) {
                    $query->whereNull('remarks')->orWhereRaw('TRIM("remarks") = ?', ['']);
                });

            $user = $request->user();
            if ($user) {
                $this->timesheetScope->applyScope($query, $user);
            } else {
                $query->whereRaw('1 = 0');
            }

            $timesheets = $query->with('employee')->lockForUpdate()->get();

            $updatedCount = 0;

            foreach ($timesheets as $timesheet) {
                if ($this->timesheetEditLockService->isLocked($timesheet)) {
                    continue;
                }

                if (!$user) {
                    continue;
                }

                try {
                    $nextStatus = $this->timesheetScope->resolveApprovalTarget(
                        $user,
                        $timesheet,
                        $validated['approvalStatus'],
                    );
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    continue;
                }

                $timesheet->approvalStatus = $nextStatus;

                if (in_array($nextStatus, ['PENDING', 'PENDING_SUPERVISOR'], true)) {
                    $timesheet->approvedAt = null;
                    $timesheet->approvedBy = null;
                } else {
                    $timesheet->approvedAt = $reviewedAt;
                    $timesheet->approvedBy = $approverId;
                }

                if ($remarks !== '') {
                    $existing = trim((string) $timesheet->remarks);
                    $timesheet->remarks = $existing === '' ? $remarks : "{$existing}\n{$remarks}";
                }

                $this->markTimesheetUpdated($timesheet, $request);
                $timesheet->save();
                $updatedCount++;
            }

            return $updatedCount;
        });

        return response()->json([
            'message' => 'Timesheet approvals updated.',
            'updatedCount' => $updatedCount,
            'skippedCount' => count($validated['timesheetIds']) - $updatedCount,
            'approvalStatus' => $validated['approvalStatus'],
        ]);
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('startDate')) {
            $query->whereDate('date', '>=', $request->string('startDate'));
        }

        if ($request->filled('endDate')) {
            $query->whereDate('date', '<=', $request->string('endDate'));
        }

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->string('employeeId'));
        }

        if ($request->filled('employeeIds')) {
            $ids = collect($request->input('employeeIds'))
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->unique()
                ->values()
                ->all();

            if ($ids !== []) {
                $query->whereIn('employeeId', $ids);
            }
        }

        if ($request->filled('employmentDetailId')) {
            $query->where('employmentDetailId', $request->string('employmentDetailId'));
        }

        if ($request->filled('payPeriodGroupId')) {
            $this->applyPayPeriodGroupFilter($query, (string) $request->string('payPeriodGroupId'));
        }

        if ($request->filled('approvalStatus')) {
            $status = strtoupper((string) $request->string('approvalStatus'));
            if ($status === 'PENDING') {
                $query->where(function (Builder $pending) {
                    $pending->whereRaw('UPPER("approvalStatus") = ?', ['PENDING'])
                        ->orWhereRaw('UPPER("approvalStatus") = ?', ['PENDING_SUPERVISOR']);
                });
            } else {
                $query->whereRaw('UPPER("approvalStatus") = ?', [$status]);
            }
        }

        if ($request->filled('workingStatus')) {
            $status = strtoupper((string) $request->string('workingStatus'));
            $query->whereRaw('UPPER("workingStatus") = ?', [$status]);
        }

        if ($request->filled('departmentId')) {
            $query->where('departmentId', $request->integer('departmentId'));
        }

        $this->applyTimesheetScope($query, $request);

        if ($request->filled('payType')) {
            $payType = strtoupper((string) $request->string('payType'));
            $query->whereRaw('UPPER("payType") = ?', [$payType]);
        }

        if ($request->boolean('issuesOnly')) {
            $query
                ->whereRaw('UPPER("approvalStatus") != ?', ['APPROVED'])
                ->whereNotNull('remarks')
                ->whereRaw('TRIM("remarks") != ?', ['']);
        }

        return $query;
    }

    private function recalculateCurrentAndFutureTimesheets(Request $request): void
    {
        if (!$request->filled('startDate') && !$request->filled('endDate')) {
            return;
        }

        $startDate = $request->filled('startDate') ? (string) $request->string('startDate') : null;
        $endDate = $request->filled('endDate') ? (string) $request->string('endDate') : null;

        if ($startDate && $endDate) {
            $this->timesheetProcessingService->process(array_filter([
                'startDate' => $startDate,
                'endDate' => $endDate,
                'payPeriodGroupId' => $request->filled('payPeriodGroupId')
                    ? (string) $request->string('payPeriodGroupId')
                    : null,
            ], fn ($value) => is_string($value) && $value !== ''));
        }

        $employeeIds = $this->employeeIdsForRecalculation($request);
        if ($employeeIds === []) {
            return;
        }

        $this->compensationRecalculationService->recalculate(
            $employeeIds,
            $startDate,
            $endDate,
            false,
        );
    }

    /**
     * @param array<int, string> $requestedEmployeeIds
     * @return array<int, string>
     */
    private function employeeIdsForRecalculation(Request $request, array $requestedEmployeeIds = []): array
    {
        $query = Timesheet::query()
            ->select('employeeId')
            ->whereNotNull('employeeId')
            ->distinct();

        if ($request->filled('startDate')) {
            $query->whereDate('date', '>=', (string) $request->string('startDate'));
        }

        if ($request->filled('endDate')) {
            $query->whereDate('date', '<=', (string) $request->string('endDate'));
        }

        if ($request->filled('payPeriodGroupId')) {
            $this->applyPayPeriodGroupFilter($query, (string) $request->string('payPeriodGroupId'));
        }

        if ($requestedEmployeeIds !== []) {
            $query->whereIn('employeeId', $requestedEmployeeIds);
        } elseif ($request->filled('employeeId')) {
            $query->where('employeeId', (string) $request->string('employeeId'));
        }

        $this->applyTimesheetScope($query, $request);

        return $query
            ->pluck('employeeId')
            ->map(fn ($employeeId) => (string) $employeeId)
            ->values()
            ->all();
    }

    private function applyPayPeriodGroupFilter(Builder $query, string $payPeriodGroupId): void
    {
        $query->whereHas('employmentDetail', function (Builder $employmentDetailQuery) use ($payPeriodGroupId) {
            $employmentDetailQuery
                ->where('defaultPayPeriodGroupId', $payPeriodGroupId)
                ->where('isActive', true);
        });

        $frequencyId = $this->payrollRunFrequencyResolver->resolveFromGroupName(
            (string) (\App\Models\PayPeriodGroup::query()->whereKey($payPeriodGroupId)->value('name') ?? ''),
        );

        if ($frequencyId !== null) {
            $query->whereHas('employee', function (Builder $employeeQuery) use ($frequencyId) {
                $employeeQuery->where('payrateFrequencyId', $frequencyId);
            });
        }
    }

    private function applyTimesheetScope(Builder $query, Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->timesheetScope->applyScope($query, $user);
    }

    private function buildSummary(Builder $query): ?Timesheet
    {
        return (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('
                COUNT(*) as "timesheetCount",
                COUNT(DISTINCT "employeeId") as "employeeCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") IN (\'PENDING\', \'PENDING_SUPERVISOR\') THEN 1 ELSE 0 END), 0) as "pendingCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'APPROVED\' THEN 1 ELSE 0 END), 0) as "approvedCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") != \'APPROVED\' AND "remarks" IS NOT NULL AND TRIM("remarks") != \'\' THEN 1 ELSE 0 END), 0) as "issueCount",
                COALESCE(SUM("hoursWorked"), 0) as "hoursWorked",
                COALESCE(SUM("regularHours"), 0) as "regularHours",
                COALESCE(SUM("overtimeHours"), 0) as "overtimeHours",
                COALESCE(SUM("holidayHours"), 0) as "holidayHours",
                COALESCE(SUM("unpaidHours"), 0) as "unpaidHours",
                COALESCE(SUM("paidHours"), 0) as "paidHours"
            ')
            ->first();
    }

    private function formatSummary(?Timesheet $summary): array
    {
        return [
            'timesheetCount' => (int) ($summary?->timesheetCount ?? 0),
            'employeeCount' => (int) ($summary?->employeeCount ?? 0),
            'pendingCount' => (int) ($summary?->pendingCount ?? 0),
            'approvedCount' => (int) ($summary?->approvedCount ?? 0),
            'issueCount' => (int) ($summary?->issueCount ?? 0),
            'hoursWorked' => (float) ($summary?->hoursWorked ?? 0),
            'regularHours' => (float) ($summary?->regularHours ?? 0),
            'overtimeHours' => (float) ($summary?->overtimeHours ?? 0),
            'holidayHours' => (float) ($summary?->holidayHours ?? 0),
            'unpaidHours' => (float) ($summary?->unpaidHours ?? 0),
            'paidHours' => (float) ($summary?->paidHours ?? 0),
        ];
    }

    private function summaryApprovalStatus(
        int $pendingCount,
        int $approvedCount,
        int $rejectedCount,
        int $issueCount
    ): string {
        if ($issueCount > 0) {
            return 'REVIEW_REQUIRED';
        }

        if ($rejectedCount > 0) {
            return 'REJECTED';
        }

        if ($pendingCount > 0) {
            return $approvedCount > 0 ? 'PARTIALLY_APPROVED' : 'PENDING';
        }

        return 'APPROVED';
    }

    private function resolveApproverId(Request $request, ?string $approvedBy = null): ?string
    {
        if ($approvedBy) {
            return $approvedBy;
        }

        $approver = $request->user()
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : null;

        return $approver?->id;
    }

    private function pendingApprovalStatusFor(Timesheet $timesheet): string
    {
        $timesheet->loadMissing('employee');

        return $this->timesheetScope->initialApprovalStatus($timesheet->employee);
    }

    private function ensureTimesheetInScope(Timesheet $timesheet, Request $request): void
    {
        $user = $request->user();
        if (!$user || !$this->timesheetScope->canAccessTimesheet($user, $timesheet)) {
            abort(403, 'You are not allowed to update this timesheet.');
        }
    }

    private function applyLeaveConflictIfNeeded(
        Timesheet $timesheet,
        ?Carbon $roundOffIn,
        ?Carbon $roundOffOut,
    ): void {
        $workDate = $timesheet->date->format('Y-m-d');
        $approvedLeaves = EmployeeLeave::query()
            ->where('employeeId', $timesheet->employeeId)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $workDate)
            ->whereDate('endDate', '>=', $workDate)
            ->get()
            ->all();

        $conflicts = $this->leaveConflictService->conflictingLeaves(
            $approvedLeaves,
            $workDate,
            $roundOffIn?->format('Y-m-d H:i:s'),
            $roundOffOut?->format('Y-m-d H:i:s'),
        );

        if ($conflicts->isEmpty()) {
            return;
        }

        $message = TimesheetLeaveConflictService::CONFLICT_MESSAGE;
        $remarks = trim((string) $timesheet->remarks);
        $timesheet->remarks = $remarks === '' || !str_contains($remarks, $message)
            ? trim($remarks.' '.$message)
            : $remarks;
        $timesheet->hoursWorked = 0;
        $timesheet->regularHours = 0;
        $timesheet->overtimeHours = 0;
        $timesheet->isPaid = false;
        $timesheet->paidHours = 0;
        $timesheet->save();

        $employee = Employee::query()->find($timesheet->employeeId);
        if ($employee) {
            $this->issueNotifier->notifyLeaveConflict($timesheet, $employee, $message);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transformEmployeeWeekTimesheets(Timesheet $timesheet): array
    {
        $weekStart = Carbon::parse($timesheet->date)->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::parse($timesheet->date)->endOfWeek(Carbon::SUNDAY);

        $timesheets = Timesheet::query()
            ->with([
                'employee',
                'approver',
                'updater:id,name,email',
                'department:id,name',
                'worksite:id,name',
                'employmentDetail.department',
                'employmentDetail.worksite',
                'employmentDetail.contractType',
                'employmentDetail.defaultPayPeriodGroup',
                'employeeCompensation',
            ])
            ->where('employeeId', $timesheet->employeeId)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')
            ->orderBy('slotIndex')
            ->get();

        $scheduleSlots = $this->scheduleCoverage->buildScheduleSlots($timesheets);
        $this->timesheetEditLockService->warmCache($timesheets);
        $this->timesheetPayrollPaidStatusService->warmCache($timesheets);

        return $timesheets
            ->map(fn (Timesheet $row) => $this->transformTimesheet($row, $scheduleSlots))
            ->values()
            ->all();
    }

    /**
     * @return array{message:string, data:array<string, mixed>, affected:array<int, array<string, mixed>>}
     */
    private function buildTimesheetMutationResponse(string $message, Timesheet $timesheet): array
    {
        $freshTimesheet = $timesheet->fresh([
            'employee',
            'approver',
            'updater:id,name,email',
            'department:id,name',
            'worksite:id,name',
            'employmentDetail.department',
            'employmentDetail.worksite',
            'employmentDetail.contractType',
            'employmentDetail.defaultPayPeriodGroup',
            'employeeCompensation',
        ]);
        $scheduleSlots = $this->scheduleCoverage->buildScheduleSlots(collect([$freshTimesheet]));
        $this->timesheetEditLockService->warmCache(collect([$freshTimesheet]));
        $this->timesheetPayrollPaidStatusService->warmCache(collect([$freshTimesheet]));

        return [
            'message' => $message,
            'data' => $this->transformTimesheet($freshTimesheet, $scheduleSlots),
            'affected' => $this->transformEmployeeWeekTimesheets($freshTimesheet),
        ];
    }

    private function markTimesheetUpdated(Timesheet $timesheet, Request $request): void
    {
        $userId = $request->user()?->id;
        if ($userId) {
            $timesheet->updatedBy = $userId;
        }
    }

    private function transformTimesheet(Timesheet $timesheet, array $scheduleSlots = []): array
    {
        $employeeName = trim(sprintf(
            '%s %s',
            $timesheet->employee?->firstName ?? '',
            $timesheet->employee?->lastName ?? ''
        ));

        $approverName = trim(sprintf(
            '%s %s',
            $timesheet->approver?->firstName ?? '',
            $timesheet->approver?->lastName ?? ''
        ));

        $lunchSettings = $this->lunchBreakResolver->lunchSettingsForTimesheet($timesheet);
        $lockInfo = $this->timesheetEditLockService->lockInfo($timesheet);

        return [
            'id' => $timesheet->id,
            'employeeId' => $timesheet->employeeId,
            'employmentDetailId' => $timesheet->employmentDetailId,
            'employmentContractLabel' => $timesheet->employmentDetail
                ? $this->employmentContractLabel($timesheet->employmentDetail)
                : null,
            'employeeCompensationId' => $timesheet->employeeCompensationId,
            'compensationLabel' => $timesheet->employeeCompensation
                ? $this->compensationLabel($timesheet->employeeCompensation)
                : null,
            'slotIndex' => (int) ($timesheet->slotIndex ?? 0),
            'employeeCode' => $timesheet->employee?->code,
            'employeeName' => $employeeName !== '' ? $employeeName : null,
            'date' => $timesheet->date?->format('Y-m-d'),
            'clockInTime' => $timesheet->clockInTime?->format('Y-m-d H:i:s'),
            'clockInDeviceId' => $timesheet->clockInDeviceId,
            'clockOutTime' => $timesheet->clockOutTime?->format('Y-m-d H:i:s'),
            'clockOutDeviceId' => $timesheet->clockOutDeviceId,
            'roundOffClockInTime' => $timesheet->roundOffClockInTime?->format('Y-m-d H:i:s'),
            'roundOffClockOutTime' => $timesheet->roundOffClockOutTime?->format('Y-m-d H:i:s'),
            'clockedHoursWorked' => (float) ($timesheet->clockedHoursWorked ?? 0),
            'includeLunchHour' => $lunchSettings['include_lunch_hour'],
            'lunchHourHours' => (float) $lunchSettings['lunch_hour_hours'],
            'hoursWorked' => (float) $timesheet->hoursWorked,
            'regularHours' => (float) ($timesheet->regularHours ?? 0),
            'overtimeHours' => (float) ($timesheet->overtimeHours ?? 0),
            'holidayHours' => (float) ($timesheet->holidayHours ?? 0),
            'unpaidHours' => (float) ($timesheet->unpaidHours ?? 0),
            'isPaid' => (bool) ($timesheet->isPaid ?? true),
            'hasBeenPaid' => $this->timesheetPayrollPaidStatusService->hasBeenPaid($timesheet),
            'payrollPayment' => $this->timesheetPayrollPaidStatusService->paymentInfo($timesheet),
            'paidHours' => (float) ($timesheet->paidHours ?? 0),
            'workingStatus' => strtoupper((string) $timesheet->workingStatus),
            'departmentId' => $timesheet->departmentId,
            'departmentName' => $timesheet->department?->name,
            'worksiteId' => $timesheet->worksiteId,
            'worksiteName' => $timesheet->worksite?->name,
            'payType' => $timesheet->payType ? strtoupper((string) $timesheet->payType) : null,
            'hourlyRate' => $timesheet->hourlyRate !== null ? (float) $timesheet->hourlyRate : null,
            'weeklySalary' => $timesheet->weeklySalary !== null ? (float) $timesheet->weeklySalary : null,
            'baseSalary' => $timesheet->baseSalary !== null ? (float) $timesheet->baseSalary : null,
            'approvalStatus' => strtoupper((string) $timesheet->approvalStatus),
            'approvedBy' => $timesheet->approvedBy,
            'approvedByName' => $approverName !== '' ? $approverName : null,
            'approvedAt' => $timesheet->approvedAt?->format('Y-m-d H:i:s'),
            'remarks' => $timesheet->remarks,
            'comment' => $timesheet->comment,
            'updatedBy' => $timesheet->updatedBy,
            'updatedByName' => $timesheet->updater?->name,
            'hasLeaveConflict' => $this->leaveConflictResolver->hasUnresolvedLeaveConflict($timesheet),
            'hasIssues' => strtoupper((string) $timesheet->approvalStatus) !== 'APPROVED'
                && !empty(trim((string) $timesheet->remarks)),
            'isOutsideSchedule' => $this->scheduleCoverage->isOutsideSchedule($timesheet, $scheduleSlots),
            'isLocked' => $lockInfo['isLocked'],
            'isPayDatePassed' => $lockInfo['isPayDatePassed'],
            'isDateUnlocked' => $lockInfo['isDateUnlocked'],
            'lockReason' => $lockInfo['lockReason'],
            'payDate' => $lockInfo['payDate'],
            'createdAt' => $timesheet->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $timesheet->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function lockedTimesheetResponse(Timesheet $timesheet): ?JsonResponse
    {
        $lockInfo = $this->timesheetEditLockService->lockInfo($timesheet);
        if (!$lockInfo['isLocked']) {
            return null;
        }

        return response()->json([
            'message' => $lockInfo['lockReason'] ?? 'This timesheet is locked and cannot be edited.',
            'isLocked' => true,
            'payDate' => $lockInfo['payDate'],
        ], 422);
    }

    private function employmentContractLabel(EmploymentDetail $employmentDetail): string
    {
        $title = $employmentDetail->jobTitle
            ?: $employmentDetail->contractType?->name
            ?: 'Contract';
        $department = $employmentDetail->department?->name ?: 'No department';
        $payPeriodGroup = $employmentDetail->defaultPayPeriodGroup?->name ?: 'No pay period group';

        return "{$title} - {$department} - {$payPeriodGroup}";
    }

    private function compensationLabel($compensation): string
    {
        $method = strtoupper((string) $compensation->compensationMethod);
        $rate = $compensation->yearlyRate > 0
            ? number_format((float) $compensation->yearlyRate, 2)
            : number_format((float) $compensation->hourlyRate, 2);

        return "{$method} @ {$rate}";
    }
}
