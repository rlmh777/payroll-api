<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\EmploymentDetail;
use App\Modules\Hr\Services\Employment\EmploymentDetailVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class EmploymentDetailsController extends Controller
{
    private const RELATIONS = [
        'employee',
        'department',
        'worksite',
        'contractType',
        'chartOfAccount',
        'defaultPayPeriodGroup',
    ];

    public function __construct(
        private readonly EmploymentDetailVersionService $versionService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = EmploymentDetail::with(self::RELATIONS);

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->filled('departmentId')) {
            $query->where('departmentId', $request->input('departmentId'));
        }

        if ($request->has('isActive')) {
            $query->where('isActive', $request->boolean('isActive'));
        }

        $sortField = $request->input('sortBy', 'startDate');
        $sortDirection = $request->input('sortDirection', 'desc');
        $query->orderBy($sortField, $sortDirection);

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->applyContractAgreementUpload($request, $validator->validated());
        $data = $this->normalizeOptionalTextFields($data, defaultWhenMissing: true);
        $data = $this->normalizeOptionalDates($data);

        $employmentDetails = EmploymentDetail::create(array_merge($data, [
            'id' => (string) Str::uuid(),
        ]));

        return response()->json([
            'message' => 'Employment detail created successfully',
            'data' => $employmentDetails->load(self::RELATIONS),
        ], 201);
    }

    public function show(EmploymentDetail $employmentDetails): JsonResponse
    {
        return response()->json($employmentDetails->load(self::RELATIONS));
    }

    public function update(Request $request, EmploymentDetail $employmentDetails): JsonResponse
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), $this->rules(partial: true));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->applyContractAgreementUpload(
            $request,
            $validator->validated(),
            $employmentDetails->contractAgreementPath
        );
        $data = $this->normalizeOptionalTextFields($data);
        $data = $this->normalizeOptionalDates($data);

        if (!$employmentDetails->isActive) {
            if ($this->versionService->assignmentFieldsChanged($employmentDetails, $data)) {
                return response()->json([
                    'message' => 'Department, work site, and pay period group can only be changed on the active employment contract.',
                ], 422);
            }

            $employmentDetails->update($data);

            return response()->json([
                'message' => 'Employment detail updated successfully',
                'data' => $employmentDetails->fresh()->load(self::RELATIONS),
                'revised' => false,
            ]);
        }

        if ($this->versionService->assignmentFieldsChanged($employmentDetails, $data)) {
            $successor = $this->versionService->revise($employmentDetails, $data);

            return response()->json([
                'message' => 'Employment contract revised with updated assignment.',
                'data' => $successor->load(self::RELATIONS),
                'revised' => true,
            ]);
        }

        $employmentDetails->update($data);

        return response()->json([
            'message' => 'Employment detail updated successfully',
            'data' => $employmentDetails->fresh()->load(self::RELATIONS),
            'revised' => false,
        ]);
    }

    public function destroy(EmploymentDetail $employmentDetails): JsonResponse
    {
        if ($employmentDetails->isActive) {
            return response()->json([
                'message' => 'End the active employment contract before deleting it.',
            ], 422);
        }

        $employmentDetails->delete();

        return response()->json(['message' => 'Employment detail deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'employeeId' => [$required, 'uuid', 'exists:employee,id'],
            'departmentId' => [$required, 'integer', 'exists:department,id'],
            'worksiteId' => [$required, 'integer', 'exists:worksite,id'],
            'accountId' => [$required, 'uuid', 'exists:accounts,id'],
            'contractTypeId' => [$required, 'integer', 'exists:contract_type,id'],
            'defaultPayPeriodGroupId' => [$required, 'uuid', 'exists:pay_period_groups,id'],
            'employmentPolicies' => ['nullable', 'string'],
            'contractAgreementPath' => ['nullable', 'string', 'max:1024'],
            'contractAgreement' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'requiresClocking' => ['sometimes', 'boolean'],
            'startDate' => [$required, 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'isActive' => ['boolean'],
            'jobTitle' => ['nullable', 'string', 'max:255'],
            'benefits' => ['nullable', 'string'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyContractAgreementUpload(
        Request $request,
        array $data,
        ?string $existingPath = null
    ): array {
        unset($data['contractAgreement']);

        if (!$request->hasFile('contractAgreement')) {
            return $data;
        }

        if ($existingPath && Storage::disk('public')->exists($existingPath)) {
            Storage::disk('public')->delete($existingPath);
        }

        $file = $request->file('contractAgreement');
        $fileName = 'contract_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $data['contractAgreementPath'] = $file->storeAs('employment-contracts', $fileName, 'public');

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeOptionalTextFields(array $data, bool $defaultWhenMissing = false): array
    {
        foreach (['employmentPolicies', 'benefits'] as $field) {
            if (array_key_exists($field, $data) || $defaultWhenMissing) {
                $data[$field] = trim((string) ($data[$field] ?? ''));
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeOptionalDates(array $data): array
    {
        if (array_key_exists('endDate', $data) && ($data['endDate'] === '' || $data['endDate'] === null)) {
            $data['endDate'] = null;
        }

        return $data;
    }

}
