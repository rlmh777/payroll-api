<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\EmployeeCertification;
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
                    ->orWhere('credentialId', 'ilike', $search);
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
        ]);

        $record = EmployeeCertification::create($validated);

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
        ]);

        $employeeCertification->update($validated);

        return response()->json([
            'message' => 'Certification updated successfully.',
            'data' => $employeeCertification->fresh()->load('employee'),
        ]);
    }

    public function destroy(EmployeeCertification $employeeCertification): JsonResponse
    {
        $employeeCertification->delete();

        return response()->json([
            'message' => 'Certification deleted successfully.',
        ]);
    }
}
