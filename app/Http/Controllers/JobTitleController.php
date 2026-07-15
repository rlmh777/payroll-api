<?php

namespace App\Http\Controllers;

use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobTitleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JobTitle::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'ilike', "%{$search}%")
                    ->orWhere('payScale', 'ilike', "%{$search}%");
            });
        }

        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['name', 'payScale'], true)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        return response()->json($query->paginate((int) $request->get('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:job_title,name'],
                'payScale' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
                'jobDescription' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            ]);

            $data = [
                'name' => trim($validated['name']),
                'payScale' => isset($validated['payScale']) ? trim((string) $validated['payScale']) ?: null : null,
                'notes' => $this->normalizeNotes($validated['notes'] ?? null),
            ];

            $data = $this->applyJobDescriptionUpload($request, $data);

            $jobTitle = JobTitle::create($data);

            return response()->json([
                'message' => 'Job title created successfully',
                'data' => $jobTitle->fresh(),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function show(JobTitle $jobTitle): JsonResponse
    {
        return response()->json($jobTitle);
    }

    public function update(Request $request, JobTitle $jobTitle): JsonResponse
    {
        try {
            if (empty($request->all()) && !$request->hasFile('jobDescription')) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $jobTitle,
                ]);
            }

            $validated = $request->validate([
                'name' => [
                    'sometimes',
                    'string',
                    'max:255',
                    Rule::unique('job_title', 'name')->ignore($jobTitle->id),
                ],
                'payScale' => ['sometimes', 'nullable', 'string', 'max:255'],
                'notes' => ['sometimes', 'nullable', 'string'],
                'jobDescription' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
                'removeJobDescription' => ['sometimes', 'boolean'],
            ]);

            $data = [];
            if (array_key_exists('name', $validated)) {
                $data['name'] = trim($validated['name']);
            }
            if (array_key_exists('payScale', $validated)) {
                $data['payScale'] = trim((string) ($validated['payScale'] ?? '')) ?: null;
            }
            if (array_key_exists('notes', $validated)) {
                $data['notes'] = $this->normalizeNotes($validated['notes'] ?? null);
            }

            if ($request->boolean('removeJobDescription') && !$request->hasFile('jobDescription')) {
                $this->deleteJobDescriptionFile($jobTitle->jobDescriptionPath);
                $data['jobDescriptionPath'] = null;
            }

            $data = $this->applyJobDescriptionUpload($request, $data, $jobTitle->jobDescriptionPath);

            if ($data !== []) {
                $jobTitle->update($data);
            }

            return response()->json([
                'message' => 'Job title updated successfully',
                'data' => $jobTitle->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        if ($jobTitle->employmentDetails()->exists()) {
            return response()->json([
                'error' => 'Cannot delete job title with associated employment contracts',
            ], 422);
        }

        $this->deleteJobDescriptionFile($jobTitle->jobDescriptionPath);
        $jobTitle->delete();

        return response()->json(['message' => 'Job title deleted successfully.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyJobDescriptionUpload(
        Request $request,
        array $data,
        ?string $existingPath = null
    ): array {
        unset($data['jobDescription'], $data['removeJobDescription']);

        if (!$request->hasFile('jobDescription')) {
            return $data;
        }

        $this->deleteJobDescriptionFile($existingPath);

        $file = $request->file('jobDescription');
        $fileName = 'job_description_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $data['jobDescriptionPath'] = $file->storeAs('job-descriptions', $fileName, 'public');

        return $data;
    }

    private function deleteJobDescriptionFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function normalizeNotes(mixed $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $value = trim((string) $notes);
        $plain = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $plain === '' ? null : $value;
    }
}
