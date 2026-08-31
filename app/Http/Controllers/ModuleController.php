<?php

namespace App\Http\Controllers;

use App\Services\CompanyModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    public function __construct(
        private readonly CompanyModuleService $companyModuleService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->companyModuleService->modulesForCompany()->values());
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $this->companyModuleService->setEnabled($code, $request->boolean('enabled'), $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module updated successfully',
            'modules' => $this->companyModuleService->modulesForCompany()->values(),
        ]);
    }
}
