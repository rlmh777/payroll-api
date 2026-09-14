<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Permission;
use App\Services\EmployeeFormAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    public function __construct(
        private readonly EmployeeFormAccessService $employeeFormAccess,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Role::query();

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

        $paginator = $query->paginate($request->input('per_page', 15));
        $paginator->getCollection()->transform(function (Role $role) {
            $role->setAttribute(
                'employee_form_access',
                $this->employeeFormAccess->profileForRole($role),
            );

            return $role;
        });

        return $paginator;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:128', 'unique:roles,name'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['exists:permissions,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $role = Role::create($request->only('name'));
        $role->update([
            'employee_form_access' => $this->employeeFormAccess->defaultForRoleName($role->name),
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->input('permissions'));
        }

        $role->load('permissions');
        $role->setAttribute(
            'employee_form_access',
            $this->employeeFormAccess->profileForRole($role),
        );

        return response()->json($role, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role)
    {
        $role->load('permissions');
        $role->setAttribute(
            'employee_form_access',
            $this->employeeFormAccess->profileForRole($role),
        );

        return $role;
    }

    public function employeeFormAccessCatalog()
    {
        return response()->json([
            'tabs' => $this->employeeFormAccess->tabCatalog(),
            'fields' => $this->employeeFormAccess->fieldCatalog(),
            'tabModes' => EmployeeFormAccessService::TAB_MODES,
            'fieldModes' => EmployeeFormAccessService::FIELD_MODES,
        ]);
    }

    public function updateEmployeeFormAccess(Request $request, Role $role)
    {
        $validator = Validator::make($request->all(), [
            'tabs' => ['required', 'array'],
            'tabs.*' => ['string', 'in:'.implode(',', EmployeeFormAccessService::TAB_MODES)],
            'fields' => ['required', 'array'],
            'fields.*' => ['string', 'in:'.implode(',', EmployeeFormAccessService::FIELD_MODES)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $this->employeeFormAccess->normalize($validator->validated());
        $role->update(['employee_form_access' => $profile]);

        $role->load('permissions');
        $role->setAttribute('employee_form_access', $profile);

        return response()->json($role);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Role $role)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:128', 'unique:roles,name,' . $role->id],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['exists:permissions,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $role->update($request->only('name'));

        if ($request->has('permissions')) {
            $role->syncPermissions($request->input('permissions'));
        }

        return response()->json($role->load('permissions'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        $role->delete();
        return response()->json(null, 204);
    }

    public function assignPermissions(Request $request, Role $role)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $role->syncPermissions($request->input('permissions'));

        return response()->json($role->load('permissions'));
    }

    public function removePermissions(Request $request, Role $role)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Use the permissions relationship to detach multiple permissions efficiently
        $role->permissions()->detach($request->input('permissions'));

        // Clear the cache to ensure fresh data
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json($role->load('permissions'));
    }
}
