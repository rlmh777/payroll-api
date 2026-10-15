<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Enums\CompensationMethod;
use App\Enums\CompensationReason;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmploymentDetail;
use App\Modules\Hr\Services\Attendance\TimesheetCompensationRecalculationService;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeCompensationController extends Controller
{
    private const RELATIONS = [
        'employee',
        'employmentDetail.department',
        'employmentDetail.worksite',
        'employmentDetail.contractType',
        'employmentDetail.defaultPayPeriodGroup',
        'approvedBy',
    ];

    public function __construct(
        private readonly TimesheetCompensationRecalculationService $timesheetRecalculationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = EmployeeCompensation::query()->with(self::RELATIONS);

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->string('employeeId'));
        }

        if ($request->filled('employmentDetailId')) {
            $query->where('employmentDetailId', $request->string('employmentDetailId'));
        }

        if ($request->has('isActive')) {
            $query->where('isActive', $request->boolean('isActive'));
        }

        $sortField = $request->input('sortBy', 'effectiveDate');
        $sortDirection = $request->input('sortDirection', 'desc');
        $query->orderBy($sortField, $sortDirection);

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $payload = $this->normalizeCompensationFields($validated);
        $contractError = $this->validateEmploymentContract($payload);
        if ($contractError) {
            return $contractError;
        }
        $compensationError = $this->validateCompensationRates($payload);
        if ($compensationError) {
            return $compensationError;
        }

        if (!empty($payload['isActive'])) {
            $exists = EmployeeCompensation::query()
                ->where('employmentDetailId', $payload['employmentDetailId'])
                ->where('isActive', true)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This employment contract already has active compensation.',
                ], 422);
            }
        }

        $approver = $request->user()
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : null;

        $record = EmployeeCompensation::create(array_merge($payload, [
            'id' => (string) Str::uuid(),
            'approvedById' => $approver?->id,
        ]));
        $this->recalculateEmployeeTimesheets($record);

        return response()->json([
            'message' => 'Compensation record created successfully.',
            'data' => $record->load(self::RELATIONS),
        ], 201);
    }

    public function show(EmployeeCompensation $employeeCompensation): JsonResponse
    {
        return response()->json($employeeCompensation->load(self::RELATIONS));
    }

    public function update(Request $request, EmployeeCompensation $employeeCompensation): JsonResponse
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validated = $request->validate($this->rules(partial: true));
        $payload = $this->normalizeCompensationFields($validated, $employeeCompensation);
        $contractError = $this->validateEmploymentContract($payload, $employeeCompensation);
        if ($contractError) {
            return $contractError;
        }
        $compensationError = $this->validateCompensationRates($payload, $employeeCompensation);
        if ($compensationError) {
            return $compensationError;
        }

        $employmentDetailId = $payload['employmentDetailId'] ?? $employeeCompensation->employmentDetailId;
        $isActive = $payload['isActive'] ?? $employeeCompensation->isActive;

        if ($isActive) {
            $exists = EmployeeCompensation::query()
                ->where('employmentDetailId', $employmentDetailId)
                ->where('isActive', true)
                ->where('id', '!=', $employeeCompensation->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This employment contract already has active compensation.',
                ], 422);
            }
        }

        $approver = $request->user()
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : null;
        if ($approver) {
            $payload['approvedById'] = $approver->id;
        }

        if (!$employeeCompensation->isActive) {
            $employeeCompensation->update($payload);
            $this->recalculateEmployeeTimesheets($employeeCompensation->fresh());

            return response()->json([
                'message' => 'Compensation record updated successfully.',
                'data' => $employeeCompensation->fresh()->load(self::RELATIONS),
                'revised' => false,
            ]);
        }

        $employeeCompensation->update($payload);
        $this->recalculateEmployeeTimesheets($employeeCompensation->fresh());

        return response()->json([
            'message' => 'Compensation record updated successfully.',
            'data' => $employeeCompensation->fresh()->load(self::RELATIONS),
            'revised' => false,
        ]);
    }

    public function destroy(EmployeeCompensation $employeeCompensation): JsonResponse
    {
        if ($employeeCompensation->isActive) {
            return response()->json([
                'message' => 'End the active compensation record before deleting it.',
            ], 422);
        }

        $employeeCompensation->delete();

        return response()->json(['message' => 'Compensation record deleted successfully.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'employeeId' => [$required, 'uuid', 'exists:employee,id'],
            'employmentDetailId' => [$required, 'uuid', 'exists:employment_detail,id'],
            'effectiveDate' => [$required, 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:effectiveDate'],
            'isActive' => ['boolean'],
            'compensationMethod' => [$required, Rule::in(CompensationMethod::values())],
            'requiresClocking' => ['boolean'],
            'hourlyRate' => ['nullable', 'numeric', 'min:0'],
            'yearlyRate' => ['nullable', 'numeric', 'min:0'],
            'dailyRate' => ['nullable', 'numeric', 'min:0'],
            'standardWeeklyHours' => ['nullable', 'numeric', 'min:0.5', 'max:168'],
            'payscale' => ['nullable', 'string', 'max:16'],
            'payscalePoint' => ['nullable', 'string', 'max:8'],
            'reasonType' => [$required, Rule::in(CompensationReason::values())],
            'reasonNote' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateCompensationRates(array $data, ?EmployeeCompensation $existing = null): ?JsonResponse
    {
        $method = CompensationMethod::fromStored(
            $data['compensationMethod'] ?? $existing?->compensationMethod
        );
        $hourlyRate = (float) ($data['hourlyRate'] ?? $existing?->hourlyRate ?? 0);
        $yearlyRate = (float) ($data['yearlyRate'] ?? $existing?->yearlyRate ?? 0);

        if ($method->isHourlyBased() && $hourlyRate <= 0) {
            return response()->json([
                'errors' => ['hourlyRate' => ['Hourly rate is required for hourly pay methods.']],
            ], 422);
        }

        if ($method->isBaseBased() && $yearlyRate <= 0) {
            return response()->json([
                'errors' => ['yearlyRate' => ['Annual base rate is required for base-rate pay methods.']],
            ], 422);
        }

        $dailyRate = (float) ($data['dailyRate'] ?? $existing?->dailyRate ?? 0);
        if ($method->isDailyRateBased() && $dailyRate <= 0) {
            return response()->json([
                'errors' => ['dailyRate' => ['Daily rate is required for day / trip pay methods.']],
            ], 422);
        }

        if (! $method->isDailyRateBased()) {
            $standardWeeklyHours = (float) (
                $data['standardWeeklyHours']
                ?? $existing?->standardWeeklyHours
                ?? EmployeeCompensationResolver::DEFAULT_STANDARD_WEEKLY_HOURS
            );
            if ($standardWeeklyHours <= 0) {
                return response()->json([
                    'errors' => ['standardWeeklyHours' => ['Standard weekly hours must be greater than zero.']],
                ], 422);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateEmploymentContract(array $data, ?EmployeeCompensation $existing = null): ?JsonResponse
    {
        $employeeId = $data['employeeId'] ?? $existing?->employeeId;
        $employmentDetailId = $data['employmentDetailId'] ?? $existing?->employmentDetailId;

        if (!$employeeId || !$employmentDetailId) {
            return null;
        }

        $contract = EmploymentDetail::query()->find($employmentDetailId);
        if (!$contract || (string) $contract->employeeId !== (string) $employeeId) {
            return response()->json([
                'errors' => [
                    'employmentDetailId' => ['Select an employment contract that belongs to this employee.'],
                ],
            ], 422);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeCompensationFields(array $data, ?EmployeeCompensation $existing = null): array
    {
        $method = CompensationMethod::fromStored(
            $data['compensationMethod'] ?? $existing?->compensationMethod
        );

        $data['compensationMethod'] = $method->storedPayType();

        $resolver = app(EmployeeCompensationResolver::class);

        if ($method->isHourlyBased()) {
            $hourlyRate = (float) ($data['hourlyRate'] ?? $existing?->hourlyRate ?? 0);
            $standardWeeklyHours = (float) (
                $data['standardWeeklyHours']
                ?? $existing?->standardWeeklyHours
                ?? EmployeeCompensationResolver::DEFAULT_STANDARD_WEEKLY_HOURS
            );
            $compensation = $existing ?? new EmployeeCompensation();
            $compensation->standardWeeklyHours = $standardWeeklyHours;

            $data['standardWeeklyHours'] = $standardWeeklyHours;
            $data['hourlyRate'] = $hourlyRate;
            $data['yearlyRate'] = (float) (
                $resolver->derivedYearlyRateFromHourly($hourlyRate, $compensation)
                ?? 0.0
            );
            $data['dailyRate'] = 0;
        } elseif ($method->isDailyRateBased()) {
            $dailyRate = (float) ($data['dailyRate'] ?? $existing?->dailyRate ?? 0);
            $data['dailyRate'] = $dailyRate;
            $data['hourlyRate'] = 0;
            $data['yearlyRate'] = 0;
            $data['standardWeeklyHours'] = (float) (
                $data['standardWeeklyHours']
                ?? $existing?->standardWeeklyHours
                ?? EmployeeCompensationResolver::DEFAULT_STANDARD_WEEKLY_HOURS
            );
            $data['requiresClocking'] = false;
        } else {
            $hourlyRate = (float) ($data['hourlyRate'] ?? $existing?->hourlyRate ?? 0);
            $yearlyRate = (float) ($data['yearlyRate'] ?? $existing?->yearlyRate ?? 0);
            $standardWeeklyHours = (float) (
                $data['standardWeeklyHours']
                ?? $existing?->standardWeeklyHours
                ?? EmployeeCompensationResolver::DEFAULT_STANDARD_WEEKLY_HOURS
            );

            $data['standardWeeklyHours'] = $standardWeeklyHours;

            if ($hourlyRate <= 0 && $yearlyRate > 0) {
                $compensation = $existing ?? new EmployeeCompensation();
                $compensation->standardWeeklyHours = $standardWeeklyHours;
                $hourlyRate = (float) (
                    $resolver->derivedHourlyRateFromYearly($yearlyRate, $compensation)
                    ?? 0.0
                );
            }

            $data['hourlyRate'] = $hourlyRate;
            $data['yearlyRate'] = $yearlyRate;
            $data['dailyRate'] = 0;
        }

        if (! array_key_exists('requiresClocking', $data)) {
            $data['requiresClocking'] = $existing?->requiresClocking ?? $method->defaultRequiresClocking();
        } else {
            $data['requiresClocking'] = (bool) $data['requiresClocking'];
        }

        if ($method->isHourlyBased()) {
            $data['requiresClocking'] = true;
        }

        if ($method->isDailyRateBased()) {
            $data['requiresClocking'] = false;
        }

        if (array_key_exists('endDate', $data) && ($data['endDate'] === '' || $data['endDate'] === null)) {
            $data['endDate'] = null;
        }

        if (array_key_exists('payscale', $data)) {
            $data['payscale'] = trim((string) ($data['payscale'] ?? '')) ?: null;
        }

        if (array_key_exists('payscalePoint', $data)) {
            $data['payscalePoint'] = trim((string) ($data['payscalePoint'] ?? '')) ?: null;
        }

        if (array_key_exists('reasonNote', $data)) {
            $data['reasonNote'] = trim((string) ($data['reasonNote'] ?? '')) ?: null;
        }

        return $data;
    }

    private function recalculateEmployeeTimesheets(?EmployeeCompensation $compensation): void
    {
        if (!$compensation?->employeeId) {
            return;
        }

        $this->timesheetRecalculationService->recalculate(
            [(string) $compensation->employeeId],
            $compensation->effectiveDate ? Carbon::parse($compensation->effectiveDate)->toDateString() : null,
        );
    }
}
