<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\EmployeeCertification;
use App\Support\PublicFileUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeCertificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeCertification::query()->with('employee');

        if ($request->filled('employeeId') || $request->filled('employee_id')) {
            $query->where(
                'employeeId',
                $request->input('employeeId', $request->input('employee_id')),
            );
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'ilike', $search)
                    ->orWhere('issuingOrganization', 'ilike', $search)
                    ->orWhere('credentialId', 'ilike', $search)
                    ->orWhere('fileName', 'ilike', $search);
            });
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return response()->json(
            $query->orderByDesc('issuedOn')->orderBy('name')->paginate($perPage),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'name' => ['required', 'string', 'max:255'],
            'issuingOrganization' => ['nullable', 'string', 'max:255'],
            'credentialId' => ['nullable', 'string', 'max:255'],
            'issuedOn' => ['nullable', 'date'],
            'expiresOn' => ['nullable', 'date', 'after_or_equal:issuedOn'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachmentFile' => PublicFileUpload::optionalRules(),
        ]);

        $data = PublicFileUpload::apply(
            $request,
            $validated,
            'employee-certifications',
            'attachmentFile',
            null,
            'cert_',
        );
        $data = $this->normalizeOptionalStrings($data);

        $record = EmployeeCertification::create($data);

        return response()->json([
            'message' => 'Certification created successfully.',
            'data' => $record->load('employee'),
        ], 201);
    }

    public function show(EmployeeCertification $employeeCertification): JsonResponse
    {
        return response()->json($employeeCertification->load('employee'));
    }

    public function update(Request $request, EmployeeCertification $employeeCertification): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'issuingOrganization' => ['nullable', 'string', 'max:255'],
            'credentialId' => ['nullable', 'string', 'max:255'],
            'issuedOn' => ['nullable', 'date'],
            'expiresOn' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachmentFile' => PublicFileUpload::optionalRules(),
        ]);

        $data = PublicFileUpload::apply(
            $request,
            $validated,
            'employee-certifications',
            'attachmentFile',
            $employeeCertification->filePath,
            'cert_',
        );
        $data = $this->normalizeOptionalStrings($data);

        $employeeCertification->update($data);

        return response()->json([
            'message' => 'Certification updated successfully.',
            'data' => $employeeCertification->fresh()->load('employee'),
        ]);
    }

    public function destroy(EmployeeCertification $employeeCertification): JsonResponse
    {
        PublicFileUpload::delete($employeeCertification->filePath);
        $employeeCertification->delete();

        return response()->json([
            'message' => 'Certification deleted successfully.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeOptionalStrings(array $data): array
    {
        foreach (['issuingOrganization', 'credentialId', 'issuedOn', 'expiresOn', 'notes'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
