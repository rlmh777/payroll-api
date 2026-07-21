<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Enums\LeaveStatusCode;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeLeaveAttachment;
use App\Models\LeaveType;
use App\Helpers\EmployeeLeaveHelper;
use App\Modules\Hr\Services\Leave\LeaveEntitlementService;
use App\Modules\Hr\Services\Leave\LeaveTeamListService;
use App\Modules\Hr\Services\Leave\LeaveWorkflowService;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use App\Modules\Payroll\Services\EmployeeHoursBankService;
use App\Models\EmployeeCompensation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmployeeLeaveController extends Controller
{
    private const LEAVE_RELATIONS = ['employee.person', 'leaveType', 'leaveStatus', 'approver', 'attachments'];

    private const ATTACHMENT_RULES = [
        'attachments' => ['nullable', 'array', 'max:10'],
        'attachments.*' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp,xls,xlsx,txt', 'max:20480'],
    ];

    public function __construct(
        private readonly LeaveEntitlementService $entitlementService,
        private readonly LeaveWorkflowService $workflowService,
        private readonly LeaveTeamListService $teamListService,
        private readonly EmployeeHoursBankService $hoursBankService,
        private readonly EmployeeCompensationResolver $compensationResolver,
    ) {
    }

    public function teamAccess(Request $request): JsonResponse
    {
        return response()->json($this->teamListService->accessPayload($request->user()));
    }

    public function teamIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($request->boolean('export')) {
            return response()->json([
                'data' => $this->teamListService->exportRows($request, $user),
            ]);
        }

        return response()->json($this->teamListService->paginate($request, $user));
    }

    public function approve(Request $request, EmployeeLeave $employeeLeave): JsonResponse
    {
        $status = strtolower((string) $request->input('status', ''));
        $allowed = ['approved', 'rejected', 'cancelled'];

        if (!$status || !in_array($status, $allowed, true)) {
            return response()->json(['error' => 'status must be approved, rejected, or cancelled'], 422);
        }

        $note = $request->input('note');
        $note = is_string($note) ? $note : null;

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

            $leave = $this->workflowService->approve($employeeLeave, $request->user(), $note);
        } elseif ($status === 'rejected') {
            $leave = $this->workflowService->reject($employeeLeave, $request->user(), $note);
        } else {
            $leave = $this->workflowService->cancel($employeeLeave, $request->user(), $note);
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
            'approve' => $this->workflowService->approve($employeeLeave, $request->user(), $note),
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
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'applyHoursBank' => ['sometimes', 'boolean'],
            'leaveHours' => ['nullable', 'numeric', 'min:0'],
            ...self::ATTACHMENT_RULES,
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
        $multiplier = $this->multiplierForLeaveType((int) $request->input('leaveTypeId'));
        $uploadedFiles = $this->uploadedAttachmentFiles($request);

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
            $employeeLeave = EmployeeLeave::create($this->prepareLeaveData([
                ...$request->except('attachments'),
                'multiplier' => $multiplier,
            ]));
            $this->attachUploadedFiles($employeeLeave, $uploadedFiles);
            $hoursBank = $this->maybeApplyHoursBank($request, $employeeLeave);

            return response()->json([
                ...$employeeLeave->load(self::LEAVE_RELATIONS)->toArray(),
                'hoursBank' => $hoursBank,
            ], 201);
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
                'multiplier' => $multiplier,
                'departmentId' => $request->input('departmentId'),
            ]);

            $employeeLeave = EmployeeLeave::create($leaveData);
            $this->attachUploadedFiles($employeeLeave, $uploadedFiles);
            $hoursBank = $this->maybeApplyHoursBank($request, $employeeLeave);

            return response()->json([
                ...$employeeLeave->load(self::LEAVE_RELATIONS)->toArray(),
                'hoursBank' => $hoursBank,
            ], 201);
        }

        $workingDays = EmployeeLeaveHelper::getWorkingDays($startDate, $endDate);
        $createdLeaves = [];
        $storedFiles = $this->storeUploadedFilesOnce($uploadedFiles);

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
                'multiplier' => $multiplier,
                'departmentId' => $request->input('departmentId'),
            ]);

            $leave = EmployeeLeave::create($leaveData);
            $this->linkStoredFiles($leave, $storedFiles);
            $createdLeaves[] = $leave->load(self::LEAVE_RELATIONS);
        }

        if (empty($createdLeaves)) {
            return response()->json([
                'errors' => ['dateRange' => ['No working days found in the selected date range.']],
            ], 422);
        }

        $this->maybeApplyHoursBank($request, $createdLeaves[0]);

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
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            ...self::ATTACHMENT_RULES,
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
        $leaveTypeId = (int) $request->input('leaveTypeId', $employeeLeave->leaveTypeId);

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

        $payload = $request->only([
            'employeeId',
            'leaveTypeId',
            'startDate',
            'endDate',
            'fromTime',
            'toTime',
            'duration',
            'totalDays',
            'notes',
            'departmentId',
        ]);
        $payload['multiplier'] = $this->multiplierForLeaveType($leaveTypeId);

        $employeeLeave->update($payload);
        $this->attachUploadedFiles($employeeLeave, $this->uploadedAttachmentFiles($request));

        return response()->json($employeeLeave->fresh()->load(self::LEAVE_RELATIONS));
    }

    public function destroy(EmployeeLeave $employeeLeave): JsonResponse
    {
        $paths = $employeeLeave->attachments()->pluck('filePath')->all();
        $employeeLeave->attachments()->delete();
        $employeeLeave->delete();
        $this->deleteUnreferencedPaths($paths);

        return response()->json(null, 204);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareLeaveData(array $data): array
    {
        unset($data['attachments']);

        $employee = isset($data['employeeId'])
            ? Employee::query()->find($data['employeeId'])
            : null;

        $data['leaveStatusId'] = $this->workflowService->initialStatusIdForEmployee($employee);

        if (isset($data['leaveTypeId']) && !array_key_exists('multiplier', $data)) {
            $data['multiplier'] = $this->multiplierForLeaveType((int) $data['leaveTypeId']);
        }

        return $data;
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedAttachmentFiles(Request $request): array
    {
        $files = $request->file('attachments', []);
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return array_values(array_filter(
            is_array($files) ? $files : [],
            fn ($file) => $file instanceof UploadedFile,
        ));
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<array{filePath: string, fileName: string, mimeType: ?string, fileSize: ?int}>
     */
    private function storeUploadedFilesOnce(array $files): array
    {
        $stored = [];

        foreach ($files as $file) {
            $extension = $file->getClientOriginalExtension() ?: 'bin';
            $storedName = 'leave_'.Str::uuid().'.'.$extension;
            $filePath = $file->storeAs('leave-attachments', $storedName, 'public');

            $stored[] = [
                'filePath' => $filePath,
                'fileName' => $file->getClientOriginalName(),
                'mimeType' => $file->getClientMimeType(),
                'fileSize' => $file->getSize(),
            ];
        }

        return $stored;
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function attachUploadedFiles(EmployeeLeave $leave, array $files): void
    {
        if ($files === []) {
            return;
        }

        $this->linkStoredFiles($leave, $this->storeUploadedFilesOnce($files));
    }

    /**
     * @param list<array{filePath: string, fileName: string, mimeType: ?string, fileSize: ?int}> $storedFiles
     */
    private function linkStoredFiles(EmployeeLeave $leave, array $storedFiles): void
    {
        foreach ($storedFiles as $stored) {
            EmployeeLeaveAttachment::query()->create([
                'employeeLeaveId' => $leave->id,
                'filePath' => $stored['filePath'],
                'fileName' => $stored['fileName'],
                'mimeType' => $stored['mimeType'],
                'fileSize' => $stored['fileSize'],
            ]);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function deleteUnreferencedPaths(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            $stillReferenced = EmployeeLeaveAttachment::query()
                ->where('filePath', $path)
                ->exists();

            if (!$stillReferenced && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function multiplierForLeaveType(int $leaveTypeId): float
    {
        $leaveType = LeaveType::query()->find($leaveTypeId);

        if (!$leaveType) {
            return 1.0;
        }

        return $leaveType->isPaid ? 1.0 : 0.0;
    }

    /**
     * @return array<string, float>|null
     */
    private function maybeApplyHoursBank(Request $request, EmployeeLeave $employeeLeave): ?array
    {
        if (!$request->boolean('applyHoursBank')) {
            return [
                'balanceHours' => $this->hoursBankService->balance((string) $employeeLeave->employeeId),
                'appliedHours' => 0.0,
                'deficitHours' => 0.0,
            ];
        }

        $leaveHours = $request->filled('leaveHours')
            ? (float) $request->input('leaveHours')
            : $this->estimateLeaveHours(
                (string) $employeeLeave->employeeId,
                (float) $employeeLeave->totalDays,
                Carbon::parse($employeeLeave->startDate),
            );

        return $this->hoursBankService->applyToLeave(
            (string) $employeeLeave->employeeId,
            (string) $employeeLeave->id,
            $leaveHours,
            Carbon::parse($employeeLeave->startDate),
            'Applied on leave request',
        );
    }

    private function estimateLeaveHours(string $employeeId, float $totalDays, Carbon $asOf): float
    {
        $compensation = $this->compensationResolver->compensationForDate(
            EmployeeCompensation::query()->where('employeeId', $employeeId)->get(),
            $asOf,
        );
        $weekly = $this->compensationResolver->standardWeeklyHours($compensation);
        $daily = $weekly > 0 ? $weekly / 5 : 8.0;

        return round($totalDays * $daily, 4);
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
