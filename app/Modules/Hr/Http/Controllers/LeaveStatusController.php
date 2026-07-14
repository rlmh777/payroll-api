<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\LeaveStatus;
use Illuminate\Http\JsonResponse;

class LeaveStatusController extends Controller
{
    public function index(): JsonResponse
    {
        $statuses = LeaveStatus::query()
            ->orderBy('sortOrder')
            ->orderBy('name')
            ->get();

        return response()->json($statuses);
    }
}
