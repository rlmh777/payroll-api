<?php

namespace App\Modules\Hr\Services\Leave;

use App\Enums\LeaveStatusCode;
use App\Models\Department;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LeaveTeamListService
{
    public function __construct(
        private readonly LeaveSupervisorAuthorizationService $authorization,
        private readonly LeaveEntitlementService $entitlementService,
    ) {
    }

    public function accessPayload(?User $user): array
    {
        $canAccess = $this->authorization->canAccessLeaveList($user);

        return [
            'canAccess' => $canAccess,
            'isLeaveAdmin' => $this->authorization->isLeaveAdmin($user),
            'subordinateCount' => $this->authorization->subordinateEmployeeIds($user)->count(),
        ];
    }

    public function paginate(Request $request, User $user): LengthAwarePaginator
    {
        $query = $this->scopedQuery($request, $user);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate((int) $request->input('per_page', 15));

        $balanceCache = [];

        $paginator->setCollection(
            $paginator->getCollection()->map(function (EmployeeLeave $leave) use ($user, &$balanceCache) {
                return $this->transformLeave($leave, $user, $balanceCache);
            })
        );

        return $paginator;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function exportRows(Request $request, User $user): Collection
    {
        $balanceCache = [];

        return $this->scopedQuery($request, $user)
            ->limit(5000)
            ->get()
            ->map(fn (EmployeeLeave $leave) => $this->transformLeave($leave, $user, $balanceCache))
            ->values();
    }

    private function scopedQuery(Request $request, User $user): Builder
    {
        if (!$this->authorization->canAccessLeaveList($user)) {
            abort(403, 'Leave list is only available to supervisors with subordinates, or leave administrators.');
        }

        $query = EmployeeLeave::query()
            ->with([
                'employee.person',
                'employee.employmentDetails' => fn ($builder) => $builder
                    ->where('isActive', true)
                    ->with(['department', 'worksite']),
                'leaveType',
                'leaveStatus',
                'approver.person',
                'department',
                'attachments',
            ]);

        $visibleIds = $this->authorization->visibleEmployeeIdsForLeaveList($user);
        if ($visibleIds !== null) {
            if ($visibleIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('employeeId', $visibleIds->all());
            }
        }

        $this->applyFilters($query, $request);

        $sortField = $request->input('sortBy', 'startDate');
        $sortDirection = $request->input('sortDirection', 'desc');
        $allowedSorts = ['startDate', 'endDate', 'totalDays', 'created_at'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'startDate';
        }
        $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');

        return $query;
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('fromDate')) {
            $query->whereDate('endDate', '>=', $request->input('fromDate'));
        }

        if ($request->filled('toDate')) {
            $query->whereDate('startDate', '<=', $request->input('toDate'));
        }

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->string('employeeId'));
        }

        if ($request->filled('leaveTypeId')) {
            $query->where('leaveTypeId', $request->integer('leaveTypeId'));
        }

        if ($request->filled('statusCode')) {
            $query->whereHas('leaveStatus', fn ($builder) => $builder->where(
                'code',
                strtoupper((string) $request->input('statusCode')),
            ));
        }

        if ($request->filled('leaveStatusId')) {
            $query->where('leaveStatusId', $request->integer('leaveStatusId'));
        }

        if ($request->filled('departmentId')) {
            $departmentIds = $this->departmentIdsIncludingDescendants($request->integer('departmentId'));
            $query->where(function (Builder $builder) use ($departmentIds) {
                $builder
                    ->whereIn('departmentId', $departmentIds)
                    ->orWhereHas('employee.employmentDetails', function (Builder $employment) use ($departmentIds) {
                        $employment->where('isActive', true)
                            ->whereIn('departmentId', $departmentIds);
                    });
            });
        }

        if ($request->filled('worksiteId')) {
            $worksiteId = $request->integer('worksiteId');
            $query->whereHas('employee.employmentDetails', function (Builder $employment) use ($worksiteId) {
                $employment->where('isActive', true)
                    ->where('worksiteId', $worksiteId);
            });
        }

        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->whereHas('employee.person', function ($person) use ($search) {
                $person->where('firstName', 'ilike', $search)
                    ->orWhere('lastName', 'ilike', $search)
                    ->orWhere('middleName', 'ilike', $search);
            });
        }
    }

    /**
     * @return array<int, int>
     */
    private function departmentIdsIncludingDescendants(int $departmentId): array
    {
        $ids = [$departmentId];
        $frontier = [$departmentId];

        while ($frontier !== []) {
            $children = Department::query()
                ->whereIn('parentId', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $frontier = [];
            foreach ($children as $childId) {
                if (!in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $frontier[] = $childId;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, float|null> $balanceCache
     * @return array<string, mixed>
     */
    private function transformLeave(EmployeeLeave $leave, User $user, array &$balanceCache): array
    {
        $employee = $leave->employee;
        $person = $employee?->person;
        $statusCode = $leave->leaveStatus?->code;
        $activeEmployment = $employee?->employmentDetails
            ?->first(fn ($detail) => (bool) $detail->isActive)
            ?? $employee?->employmentDetails?->first();

        $lastName = trim((string) ($person?->lastName ?? $employee?->lastName ?? ''));
        $firstName = trim((string) ($person?->firstName ?? $employee?->firstName ?? ''));
        $middleName = trim((string) ($person?->middleName ?? $employee?->middleName ?? ''));

        $nameParts = array_values(array_filter([$lastName, $firstName, $middleName], fn ($part) => $part !== ''));
        if ($lastName !== '' && ($firstName !== '' || $middleName !== '')) {
            $given = trim(implode(' ', array_filter([$firstName, $middleName])));
            $employeeName = "{$lastName}, {$given}";
        } else {
            $employeeName = implode(' ', $nameParts) ?: 'Unknown employee';
        }

        $start = $leave->startDate?->format('Y-m-d');
        $end = $leave->endDate?->format('Y-m-d');
        $dateRange = $start && $end && $start !== $end
            ? "{$start} – {$end}"
            : ($start ?? $end ?? '—');

        $cacheKey = $leave->employeeId.'|'.$leave->leaveTypeId.'|'.($start ?? 'today');
        if (!array_key_exists($cacheKey, $balanceCache)) {
            $leaveType = $leave->leaveType ?? LeaveType::query()->find($leave->leaveTypeId);
            if ($leaveType) {
                $asOf = $start ? Carbon::parse($start)->startOfDay() : Carbon::today()->startOfDay();
                $balance = $this->entitlementService->balanceForType(
                    (string) $leave->employeeId,
                    $leaveType,
                    null,
                    $asOf,
                );
                $balanceCache[$cacheKey] = $leaveType->affectsBalance
                    ? (float) $balance['availableDays']
                    : null;
            } else {
                $balanceCache[$cacheKey] = null;
            }
        }

        $canManage = $this->authorization->canManageLeave($user, $leave);
        $pending = in_array($statusCode, [
            LeaveStatusCode::PendingSupervisorApproval->value,
            LeaveStatusCode::PendingApproval->value,
        ], true);
        $cancellable = in_array($statusCode, [
            LeaveStatusCode::PendingSupervisorApproval->value,
            LeaveStatusCode::PendingApproval->value,
            LeaveStatusCode::Scheduled->value,
        ], true);

        return [
            'id' => $leave->id,
            'employeeId' => $leave->employeeId,
            'employeeName' => $employeeName,
            'employeeCode' => $employee?->code,
            'dateRange' => $dateRange,
            'startDate' => $start,
            'endDate' => $end,
            'leaveTypeId' => $leave->leaveTypeId,
            'leaveType' => $leave->leaveType?->name,
            'departmentId' => $leave->departmentId ?? $activeEmployment?->departmentId,
            'departmentName' => $leave->department?->name
                ?? $activeEmployment?->department?->name,
            'worksiteId' => $activeEmployment?->worksiteId,
            'worksiteName' => $activeEmployment?->worksite?->name,
            'netLeaveBalance' => $balanceCache[$cacheKey],
            'requestedDays' => (float) $leave->totalDays,
            'statusCode' => $statusCode,
            'statusName' => $leave->leaveStatus?->name,
            'notes' => $leave->notes,
            'statusNote' => $leave->statusNote,
            'attachments' => $leave->attachments?->map(fn ($attachment) => [
                'id' => $attachment->id,
                'employeeLeaveId' => $attachment->employeeLeaveId,
                'fileName' => $attachment->fileName,
                'filePath' => $attachment->filePath,
                'mimeType' => $attachment->mimeType,
                'fileSize' => $attachment->fileSize,
                'fileUrl' => $attachment->fileUrl,
            ])->values()->all() ?? [],
            'canApprove' => $canManage && $pending,
            'canReject' => $canManage && $pending,
            'canCancel' => $canManage && $cancellable,
            'duration' => $leave->duration,
            'approvalDate' => $leave->approvalDate?->format('Y-m-d'),
        ];
    }
}
