<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\EmploymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmploymentStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmploymentStatus::query()->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->input('search') . '%');
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:employment_status,name'],
        ]);

        $status = EmploymentStatus::create($validated);

        return response()->json(['message' => 'Employment status created', 'data' => $status], 201);
    }

    public function show(EmploymentStatus $employmentStatus): JsonResponse
    {
        return response()->json($employmentStatus);
    }

    public function update(Request $request, EmploymentStatus $employmentStatus): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('employment_status', 'name')->ignore($employmentStatus->id)],
        ]);

        $employmentStatus->update($validated);

        return response()->json(['message' => 'Employment status updated', 'data' => $employmentStatus->fresh()]);
    }

    public function destroy(EmploymentStatus $employmentStatus): JsonResponse
    {
        if ($employmentStatus->employees()->exists()) {
            return response()->json(['message' => 'Cannot delete employment status in use'], 422);
        }

        $employmentStatus->delete();

        return response()->json(['message' => 'Employment status deleted']);
    }
}
