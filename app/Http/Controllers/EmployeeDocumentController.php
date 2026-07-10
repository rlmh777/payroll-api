<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmployeeDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeDocument::query()->with(['employee', 'documentTag.parent']);

        if ($request->filled('employeeId') || $request->filled('employee_id')) {
            $query->where(
                'employeeId',
                $request->input('employeeId', $request->input('employee_id')),
            );
        }

        if ($request->filled('documentTagId') || $request->filled('document_tag_id')) {
            $query->where(
                'documentTagId',
                $request->input('documentTagId', $request->input('document_tag_id')),
            );
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'ilike', $search)
                    ->orWhere('description', 'ilike', $search)
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
            'documentTagId' => ['nullable', 'uuid', 'exists:document_tag,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'documentFile' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp,xls,xlsx,txt', 'max:20480'],
        ]);

        $data = $this->applyDocumentUpload($request, $validated);
        $data = $this->normalizeDocumentFields($data);

        $record = EmployeeDocument::create($data);

        return response()->json([
            'message' => 'Document created successfully.',
            'data' => $record->load(['employee', 'documentTag.parent']),
        ], 201);
    }

    public function show(EmployeeDocument $employeeDocument): JsonResponse
    {
        return response()->json($employeeDocument->load(['employee', 'documentTag.parent']));
    }

    public function update(Request $request, EmployeeDocument $employeeDocument): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'documentTagId' => ['nullable', 'uuid', 'exists:document_tag,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'documentFile' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp,xls,xlsx,txt', 'max:20480'],
        ]);

        $data = $this->applyDocumentUpload($request, $validated, $employeeDocument->filePath);
        $data = $this->normalizeDocumentFields($data);

        $employeeDocument->update($data);

        return response()->json([
            'message' => 'Document updated successfully.',
            'data' => $employeeDocument->fresh()->load(['employee', 'documentTag.parent']),
        ]);
    }

    public function destroy(EmployeeDocument $employeeDocument): JsonResponse
    {
        $this->deleteStoredFile($employeeDocument->filePath);
        $employeeDocument->delete();

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyDocumentUpload(
        Request $request,
        array $data,
        ?string $existingPath = null,
    ): array {
        unset($data['documentFile']);

        if (!$request->hasFile('documentFile')) {
            return $data;
        }

        $this->deleteStoredFile($existingPath);

        $file = $request->file('documentFile');
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $storedName = 'doc_'.Str::uuid().'.'.$extension;
        $filePath = $file->storeAs('employee-documents', $storedName, 'public');

        $data['filePath'] = $filePath;
        $data['fileName'] = $file->getClientOriginalName();
        $data['mimeType'] = $file->getClientMimeType();
        $data['fileSize'] = $file->getSize();

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDocumentFields(array $data): array
    {
        if (array_key_exists('documentTagId', $data) && $data['documentTagId'] === '') {
            $data['documentTagId'] = null;
        }

        if (array_key_exists('description', $data) && $data['description'] === '') {
            $data['description'] = null;
        }

        return $data;
    }

    private function deleteStoredFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
