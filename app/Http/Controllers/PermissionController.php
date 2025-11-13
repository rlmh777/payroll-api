<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $query = Permission::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:128', 'unique:permissions,name'],
            'guard_name' => ['sometimes', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $permission = Permission::create($request->all());

        return response()->json($permission, 201);
    }

    public function show(Permission $permission)
    {
        return $permission;
    }

    public function update(Request $request, Permission $permission)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:128', 'unique:permissions,name,' . $permission->id],
            'guard_name' => ['sometimes', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $permission->update($request->all());

        return response()->json($permission);
    }

    public function destroy(Permission $permission)
    {
        $permission->delete();
        return response()->json(null, 204);
    }
} 