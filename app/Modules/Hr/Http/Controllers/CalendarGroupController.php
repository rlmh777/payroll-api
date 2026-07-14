<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\CalendarGroup;
use Illuminate\Http\JsonResponse;

class CalendarGroupController extends Controller
{
    /**
     * Display a listing of calendar groups.
     */
    public function index(): JsonResponse
    {
        $groups = CalendarGroup::query()
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($groups);
    }
}
