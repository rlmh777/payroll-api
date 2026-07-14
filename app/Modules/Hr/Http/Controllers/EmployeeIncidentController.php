<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeIncident;
use App\Models\EmployeeIncidentAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeIncidentController extends Controller
{
    private const RELATIONS = [
        'employee.person',
        'reportedBy.person',
        'department',
        'worksite',
        'attachments',
    ];

    private const ATTACHMENT_RULES = [
        'attachments' => ['nullable', 'array', 'max:10'],
        'attachments.*' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp,xls,xlsx,txt', 'max:20480'],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = EmployeeIncident::query()->with(self::RELATIONS);

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->input('status')));
        }

        if ($request->filled('severity')) {
            $query->where('severity', strtoupper((string) $request->input('severity')));
        }

        if ($request->filled('incidentType')) {
            $query->where('incidentType', strtoupper((string) $request->input('incidentType')));
        }

        $sortField = $request->input('sortBy', 'incidentDate');
        $sortDirection = $request->input('sortDirection', 'desc');
        $allowedSorts = ['incidentDate', 'reportedDate', 'severity', 'status', 'created_at', 'title'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'incidentDate';
        }

        $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');

        return response()->json(
            $query->paginate((int) $request->input('per_page', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->nullifyEmptyStrings($request);

        $validator = Validator::make($request->all(), array_merge([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'incidentDate' => ['required', 'date'],
            'reportedDate' => ['nullable', 'date'],
            'incidentType' => ['required', 'string', Rule::in(EmployeeIncident::TYPES)],
            'severity' => ['required', 'string', Rule::in(EmployeeIncident::SEVERITIES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(EmployeeIncident::STATUSES)],
            'reportedByEmployeeId' => ['nullable', 'uuid', 'exists:employee,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'worksiteId' => ['nullable', 'integer', 'exists:worksite,id'],
            'actionTaken' => ['nullable', 'string', Rule::in(EmployeeIncident::ACTIONS)],
            'actionDate' => ['nullable', 'date'],
            'followUpDate' => ['nullable', 'date'],
            'resolutionNotes' => ['nullable', 'string'],
            'employeeAcknowledged' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ], self::ATTACHMENT_RULES));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->normalizePayload($validator->validated(), $request);
        if (empty($data['reportedDate'])) {
            $data['reportedDate'] = now()->toDateString();
        }
        if (empty($data['status'])) {
            $data['status'] = 'REPORTED';
        }
        if (empty($data['reportedByEmployeeId']) && $request->user()) {
            $actor = Employee::query()->where('user_id', $request->user()->id)->first();
            if ($actor) {
                $data['reportedByEmployeeId'] = $actor->id;
            }
        }

        $incident = EmployeeIncident::query()->create($data);
        $this->attachUploadedFiles($incident, $this->uploadedAttachmentFiles($request));

        return response()->json([
            'message' => 'Incident created successfully',
            'data' => $incident->load(self::RELATIONS),
        ], 201);
    }

    public function show(EmployeeIncident $employeeIncident): JsonResponse
    {
        return response()->json($employeeIncident->load(self::RELATIONS));
    }

    public function update(Request $request, EmployeeIncident $employeeIncident): JsonResponse
    {
        $this->nullifyEmptyStrings($request);

        if ($request->isMethod('put') && empty($request->all()) && !$request->hasFile('attachments')) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), array_merge([
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'incidentDate' => ['sometimes', 'date'],
            'reportedDate' => ['nullable', 'date'],
            'incidentType' => ['sometimes', 'string', Rule::in(EmployeeIncident::TYPES)],
            'severity' => ['sometimes', 'string', Rule::in(EmployeeIncident::SEVERITIES)],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(EmployeeIncident::STATUSES)],
            'reportedByEmployeeId' => ['nullable', 'uuid', 'exists:employee,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'worksiteId' => ['nullable', 'integer', 'exists:worksite,id'],
            'actionTaken' => ['nullable', 'string', Rule::in(EmployeeIncident::ACTIONS)],
            'actionDate' => ['nullable', 'date'],
            'followUpDate' => ['nullable', 'date'],
            'resolutionNotes' => ['nullable', 'string'],
            'employeeAcknowledged' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ], self::ATTACHMENT_RULES));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->normalizePayload($validator->validated(), $request, $employeeIncident);
        $employeeIncident->update($data);
        $this->attachUploadedFiles($employeeIncident, $this->uploadedAttachmentFiles($request));

        return response()->json([
            'message' => 'Incident updated successfully',
            'data' => $employeeIncident->fresh()->load(self::RELATIONS),
        ]);
    }

    public function destroy(EmployeeIncident $employeeIncident): JsonResponse
    {
        $paths = $employeeIncident->attachments()->pluck('filePath')->all();
        $employeeIncident->attachments()->delete();
        $employeeIncident->delete();
        $this->deleteUnreferencedPaths($paths);

        return response()->json(['message' => 'Incident deleted successfully']);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'types' => EmployeeIncident::TYPES,
            'severities' => EmployeeIncident::SEVERITIES,
            'statuses' => EmployeeIncident::STATUSES,
            'actions' => EmployeeIncident::ACTIONS,
        ]);
    }

    private function nullifyEmptyStrings(Request $request): void
    {
        $keys = [
            'reportedDate',
            'description',
            'reportedByEmployeeId',
            'departmentId',
            'worksiteId',
            'actionTaken',
            'actionDate',
            'followUpDate',
            'resolutionNotes',
            'notes',
        ];

        $merge = [];
        foreach ($keys as $key) {
            if ($request->has($key) && $request->input($key) === '') {
                $merge[$key] = null;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data, Request $request, ?EmployeeIncident $existing = null): array
    {
        unset($data['attachments']);

        foreach (['departmentId', 'worksiteId'] as $key) {
            if (array_key_exists($key, $data) && ($data[$key] === '' || $data[$key] === null)) {
                $data[$key] = null;
            }
        }

        foreach (['reportedByEmployeeId', 'actionTaken', 'description', 'resolutionNotes', 'notes'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        foreach (['incidentType', 'severity', 'status', 'actionTaken'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $data[$key] = strtoupper($data[$key]);
            }
        }

        if ($request->has('employeeAcknowledged')) {
            $acknowledged = filter_var(
                $request->input('employeeAcknowledged'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
            if ($acknowledged !== null) {
                $data['employeeAcknowledged'] = $acknowledged;
                if ($acknowledged && !($existing?->employeeAcknowledged)) {
                    $data['acknowledgedAt'] = now();
                }
                if (!$acknowledged) {
                    $data['acknowledgedAt'] = null;
                }
            }
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
     */
    private function attachUploadedFiles(EmployeeIncident $incident, array $files): void
    {
        foreach ($files as $file) {
            $extension = $file->getClientOriginalExtension() ?: 'bin';
            $storedName = 'incident_'.Str::uuid().'.'.$extension;
            $filePath = $file->storeAs('incident-attachments', $storedName, 'public');

            EmployeeIncidentAttachment::query()->create([
                'employeeIncidentId' => $incident->id,
                'filePath' => $filePath,
                'fileName' => $file->getClientOriginalName(),
                'mimeType' => $file->getClientMimeType(),
                'fileSize' => $file->getSize(),
            ]);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function deleteUnreferencedPaths(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            $stillReferenced = EmployeeIncidentAttachment::query()
                ->where('filePath', $path)
                ->exists();

            if (!$stillReferenced && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
