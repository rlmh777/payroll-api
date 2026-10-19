<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\ReportDocumentHeader;
use App\Modules\Payroll\Services\ReportDocumentHeaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReportDocumentHeaderController extends Controller
{
    public function __construct(
        private readonly ReportDocumentHeaderService $reportDocumentHeaderService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->reportDocumentHeaderService->index());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'canvas' => ['required', 'array'],
        ]);

        try {
            $header = $this->reportDocumentHeaderService->create(
                trim((string) $validated['name']),
                $validated['canvas'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->reportDocumentHeaderService->present($header), 201);
    }

    public function update(Request $request, ReportDocumentHeader $reportDocumentHeader): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'canvas' => ['required', 'array'],
        ]);

        try {
            $header = $this->reportDocumentHeaderService->update(
                $reportDocumentHeader,
                trim((string) $validated['name']),
                $validated['canvas'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->reportDocumentHeaderService->present($header));
    }

    public function destroy(ReportDocumentHeader $reportDocumentHeader): JsonResponse
    {
        try {
            $this->reportDocumentHeaderService->delete($reportDocumentHeader);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Header deleted.']);
    }
}
