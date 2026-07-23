<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\DatabaseBackup;
use App\Modules\Core\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function __construct(
        private readonly DatabaseBackupService $databaseBackupService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->databaseBackupService->list());
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->databaseBackupService->settings());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $backup = $this->databaseBackupService->create(
                'manual',
                $validated['label'] ?? null,
                $request->user(),
            );
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Failed to create database backup.',
            ], 500);
        }

        return response()->json([
            'message' => 'Database backup created successfully.',
            'data' => $backup,
        ], 201);
    }

    public function download(DatabaseBackup $databaseBackup): StreamedResponse|JsonResponse
    {
        try {
            $this->databaseBackupService->absolutePath($databaseBackup);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return Storage::disk($databaseBackup->disk)->download(
            $databaseBackup->path,
            $databaseBackup->filename,
            ['Content-Type' => 'application/octet-stream'],
        );
    }

    public function restore(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ]);

        try {
            $this->databaseBackupService->restore($databaseBackup);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Failed to restore database backup.',
            ], 500);
        }

        return response()->json([
            'message' => 'Database restored successfully from backup.',
            'data' => $databaseBackup->fresh(['creator:id,name,email']),
        ]);
    }

    public function restoreUpload(Request $request): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:RESTORE'],
            'file' => ['required', 'file', 'max:512000'], // ~500 MB
        ]);

        try {
            $backup = $this->databaseBackupService->restoreUpload($request->file('file'));
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Failed to restore uploaded backup.',
            ], 500);
        }

        return response()->json([
            'message' => 'Database restored successfully from uploaded backup.',
            'data' => $backup,
        ]);
    }

    public function destroy(DatabaseBackup $databaseBackup): JsonResponse
    {
        $this->databaseBackupService->delete($databaseBackup);

        return response()->json(['message' => 'Database backup deleted successfully.']);
    }
}
