<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\EmployeeSkill;
use App\Support\PublicFileUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeSkillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeSkill::query()->with('employee');

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
                    ->orWhere('proficiencyLevel', 'ilike', $search)
                    ->orWhere('fileName', 'ilike', $search);
            });
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return response()->json(
            $query->orderBy('name')->paginate($perPage),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'name' => ['required', 'string', 'max:255'],
            'proficiencyLevel' => ['nullable', 'string', 'max:64'],
            'yearsExperience' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachmentFile' => PublicFileUpload::optionalRules(),
        ]);

        $data = PublicFileUpload::apply(
            $request,
            $validated,
            'employee-skills',
            'attachmentFile',
            null,
            'skill_',
        );
        $data = $this->normalizeOptionalStrings($data);

        $record = EmployeeSkill::create($data);

        return response()->json([
            'message' => 'Skill created successfully.',
            'data' => $record->load('employee'),
        ], 201);
    }

    public function show(EmployeeSkill $employeeSkill): JsonResponse
    {
        return response()->json($employeeSkill->load('employee'));
    }

    public function update(Request $request, EmployeeSkill $employeeSkill): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'proficiencyLevel' => ['nullable', 'string', 'max:64'],
            'yearsExperience' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachmentFile' => PublicFileUpload::optionalRules(),
        ]);

        $data = PublicFileUpload::apply(
            $request,
            $validated,
            'employee-skills',
            'attachmentFile',
            $employeeSkill->filePath,
            'skill_',
        );
        $data = $this->normalizeOptionalStrings($data);

        $employeeSkill->update($data);

        return response()->json([
            'message' => 'Skill updated successfully.',
            'data' => $employeeSkill->fresh()->load('employee'),
        ]);
    }

    public function destroy(EmployeeSkill $employeeSkill): JsonResponse
    {
        PublicFileUpload::delete($employeeSkill->filePath);
        $employeeSkill->delete();

        return response()->json([
            'message' => 'Skill deleted successfully.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeOptionalStrings(array $data): array
    {
        foreach (['proficiencyLevel', 'notes'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        if (array_key_exists('yearsExperience', $data) && $data['yearsExperience'] === '') {
            $data['yearsExperience'] = null;
        }

        return $data;
    }
}
