<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\Qualification;
use App\Support\PublicFileUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QualificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Qualification::with(['employee', 'institution', 'degree']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%");
            })->orWhereHas('institution', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('employeeId') || $request->filled('employee_id')) {
            $query->where(
                'employeeId',
                $request->input('employeeId', $request->input('employee_id')),
            );
        }

        if ($request->has('institution_id')) {
            $query->where('institutionId', $request->input('institution_id'));
        }

        if ($request->has('degree_id')) {
            $query->where('degreeId', $request->input('degree_id'));
        }

        $sortBy = $request->input('sort_by', 'from');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        $perPage = $request->input('per_page', 20);
        $qualifications = $query->paginate($perPage);

        return response()->json($qualifications);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => 'required|uuid|exists:employee,id',
            'institutionId' => 'required|exists:institution,id',
            'degreeId' => 'required|exists:degree,id',
            'from' => 'required|date',
            'to' => 'nullable|date|after_or_equal:from',
            'note' => 'nullable|string|max:1000',
            'attachmentFile' => PublicFileUpload::optionalRules(),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = PublicFileUpload::apply(
            $request,
            $validator->validated(),
            'employee-qualifications',
            'attachmentFile',
            null,
            'qual_',
        );
        $data = $this->normalizeOptionalStrings($data);

        $qualification = Qualification::create($data);

        return response()->json([
            'message' => 'Qualification created successfully',
            'data' => $qualification->load(['employee', 'institution', 'degree']),
        ], 201);
    }

    public function show(Qualification $qualification)
    {
        return response()->json($qualification->load(['employee', 'institution', 'degree']));
    }

    public function update(Request $request, Qualification $qualification)
    {
        if ($request->isMethod('put') && empty($request->all()) && ! $request->hasFile('attachmentFile')) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => 'sometimes|uuid|exists:employee,id',
            'institutionId' => 'sometimes|exists:institution,id',
            'degreeId' => 'sometimes|exists:degree,id',
            'from' => 'sometimes|date',
            'to' => 'nullable|date|after_or_equal:from',
            'note' => 'nullable|string|max:1000',
            'attachmentFile' => PublicFileUpload::optionalRules(),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = PublicFileUpload::apply(
            $request,
            $validator->validated(),
            'employee-qualifications',
            'attachmentFile',
            $qualification->filePath,
            'qual_',
        );
        $data = $this->normalizeOptionalStrings($data);

        $qualification->update($data);

        return response()->json([
            'message' => 'Qualification updated successfully',
            'data' => $qualification->fresh()->load(['employee', 'institution', 'degree']),
        ]);
    }

    public function destroy(Qualification $qualification)
    {
        PublicFileUpload::delete($qualification->filePath);
        $qualification->delete();

        return response()->json([
            'message' => 'Qualification deleted successfully',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeOptionalStrings(array $data): array
    {
        foreach (['to', 'note'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
