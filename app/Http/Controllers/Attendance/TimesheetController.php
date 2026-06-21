<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ApproveTimesheetRequest;
use App\Http\Requests\Attendance\BulkApproveTimesheetRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Timesheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimesheetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Timesheet::query()
            ->with([
                'employee',
                'approver',
                'department:id,name',
                'worksite:id,name',
            ]);

        $this->applyFilters($query, $request);
        $summary = $this->buildSummary($query);

        $perPage = (int) $request->integer('per_page', 50);
        $timesheets = $query
            ->orderByDesc('date')
            ->orderBy('employeeId')
            ->paginate(max(1, min($perPage, 500)));

        $timesheets->getCollection()->transform(function (Timesheet $timesheet) {
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

            return [
                'id' => $timesheet->id,
                'employeeId' => $timesheet->employeeId,
                'employeeCode' => $timesheet->employee?->code,
                'employeeName' => $employeeName !== '' ? $employeeName : null,
                'date' => $timesheet->date?->format('Y-m-d'),
                'clockInTime' => $timesheet->clockInTime?->format('Y-m-d H:i:s'),
                'clockInDeviceId' => $timesheet->clockInDeviceId,
                'clockOutTime' => $timesheet->clockOutTime?->format('Y-m-d H:i:s'),
                'clockOutDeviceId' => $timesheet->clockOutDeviceId,
                'hoursWorked' => (float) $timesheet->hoursWorked,
                'regularHours' => (float) ($timesheet->regularHours ?? 0),
                'overtimeHours' => (float) ($timesheet->overtimeHours ?? 0),
                'holidayHours' => (float) ($timesheet->holidayHours ?? 0),
                'unpaidHours' => (float) ($timesheet->unpaidHours ?? 0),
                'workingStatus' => strtoupper((string) $timesheet->workingStatus),
                'departmentId' => $timesheet->departmentId,
                'departmentName' => $timesheet->department?->name,
                'worksiteId' => $timesheet->worksiteId,
                'worksiteName' => $timesheet->worksite?->name,
                'payType' => $timesheet->payType ? strtoupper((string) $timesheet->payType) : null,
                'hourlyRate' => $timesheet->hourlyRate !== null ? (float) $timesheet->hourlyRate : null,
                'baseSalary' => $timesheet->baseSalary !== null ? (float) $timesheet->baseSalary : null,
                'approvalStatus' => strtoupper((string) $timesheet->approvalStatus),
                'approvedBy' => $timesheet->approvedBy,
                'approvedByName' => $approverName !== '' ? $approverName : null,
                'approvedAt' => $timesheet->approvedAt?->format('Y-m-d H:i:s'),
                'remarks' => $timesheet->remarks,
                'hasIssues' => strtoupper((string) $timesheet->approvalStatus) !== 'APPROVED'
                    && !empty(trim((string) $timesheet->remarks)),
                'createdAt' => $timesheet->created_at?->format('Y-m-d H:i:s'),
                'updatedAt' => $timesheet->updated_at?->format('Y-m-d H:i:s'),
            ];
        });

        $payload = $timesheets->toArray();
        $payload['summary'] = $this->formatSummary($summary);

        return response()->json($payload);
    }

    public function employeeSummary(Request $request): JsonResponse
    {
        $filteredQuery = Timesheet::query();
        $this->applyFilters($filteredQuery, $request);
        $summary = $this->buildSummary($filteredQuery);

        $perPage = (int) $request->integer('per_page', 25);
        $employeeSummaries = (clone $filteredQuery)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('
                "employeeId",
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
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'PENDING\' THEN 1 ELSE 0 END), 0) as "pendingCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'APPROVED\' THEN 1 ELSE 0 END), 0) as "approvedCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'REJECTED\' THEN 1 ELSE 0 END), 0) as "rejectedCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") != \'APPROVED\' AND "remarks" IS NOT NULL AND TRIM("remarks") != \'\' THEN 1 ELSE 0 END), 0) as "issueCount"
            ')
            ->groupBy('employeeId')
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

        $employeeSummaries->getCollection()->transform(function ($row) use ($employees, $departments) {
            $employee = $employees->get($row->employeeId);
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

    public function updateApproval(ApproveTimesheetRequest $request, Timesheet $timesheet): JsonResponse
    {
        $validated = $request->validated();

        $timesheet->approvalStatus = $validated['approvalStatus'];
        $timesheet->approvedAt = now();

        $approver = $request->user()
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : null;

        $timesheet->approvedBy = $approver?->id;

        if (isset($validated['remarks']) && trim((string) $validated['remarks']) !== '') {
            $newRemarks = trim((string) $validated['remarks']);
            $existing = trim((string) $timesheet->remarks);
            $timesheet->remarks = $existing === '' ? $newRemarks : "{$existing}\n{$newRemarks}";
        }

        $timesheet->save();

        return response()->json([
            'message' => 'Timesheet approval updated.',
            'data' => $timesheet->load([
                'employee',
                'approver',
                'department:id,name',
                'worksite:id,name',
            ]),
        ]);
    }

    public function updateBulkApproval(BulkApproveTimesheetRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $approver = $request->user()
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : null;
        $reviewedAt = now();
        $remarks = trim((string) ($validated['remarks'] ?? ''));

        $updatedCount = DB::transaction(function () use ($validated, $approver, $reviewedAt, $remarks) {
            $timesheets = Timesheet::query()
                ->whereIn('id', $validated['timesheetIds'])
                ->whereRaw('UPPER("approvalStatus") = ?', ['PENDING'])
                ->where(function ($query) {
                    $query->whereNull('remarks')->orWhereRaw('TRIM("remarks") = ?', ['']);
                })
                ->lockForUpdate()
                ->get();

            foreach ($timesheets as $timesheet) {
                $timesheet->approvalStatus = $validated['approvalStatus'];
                $timesheet->approvedAt = $reviewedAt;
                $timesheet->approvedBy = $approver?->id;

                if ($remarks !== '') {
                    $existing = trim((string) $timesheet->remarks);
                    $timesheet->remarks = $existing === '' ? $remarks : "{$existing}\n{$remarks}";
                }

                $timesheet->save();
            }

            return $timesheets->count();
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

        if ($request->filled('approvalStatus')) {
            $status = strtoupper((string) $request->string('approvalStatus'));
            $query->whereRaw('UPPER("approvalStatus") = ?', [$status]);
        }

        if ($request->filled('workingStatus')) {
            $status = strtoupper((string) $request->string('workingStatus'));
            $query->whereRaw('UPPER("workingStatus") = ?', [$status]);
        }

        if ($request->filled('departmentId')) {
            $query->where('departmentId', $request->integer('departmentId'));
        }

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

    private function buildSummary(Builder $query): ?Timesheet
    {
        return (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('
                COUNT(*) as "timesheetCount",
                COUNT(DISTINCT "employeeId") as "employeeCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'PENDING\' THEN 1 ELSE 0 END), 0) as "pendingCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") = \'APPROVED\' THEN 1 ELSE 0 END), 0) as "approvedCount",
                COALESCE(SUM(CASE WHEN UPPER("approvalStatus") != \'APPROVED\' AND "remarks" IS NOT NULL AND TRIM("remarks") != \'\' THEN 1 ELSE 0 END), 0) as "issueCount",
                COALESCE(SUM("hoursWorked"), 0) as "hoursWorked",
                COALESCE(SUM("regularHours"), 0) as "regularHours",
                COALESCE(SUM("overtimeHours"), 0) as "overtimeHours",
                COALESCE(SUM("holidayHours"), 0) as "holidayHours",
                COALESCE(SUM("unpaidHours"), 0) as "unpaidHours"
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
}
