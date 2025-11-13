<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of users with their roles.
     */
    public function index(Request $request)
    {
        $query = User::with('rolesManyToMany');

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role_id')) {
            $query->whereHas('rolesManyToMany', function ($q) use ($request) {
                $q->where('roles.id', $request->input('role_id'));
            });
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

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        if ($request->has('roles')) {
            $user->rolesManyToMany()->syncWithoutDetaching($request->input('roles'));
            $user->assignRole($request->input('roles'));
        }

        return response()->json($user->load('rolesManyToMany'), 201);
    }

    /**
     * Display the specified user with their roles.
     */
    public function show(User $user)
    {
        return $user->load('rolesManyToMany');
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $updateData = $request->only(['name', 'email']);
        
        if ($request->has('password')) {
            $updateData['password'] = Hash::make($request->input('password'));
        }

        $user->update($updateData);

        if ($request->has('roles')) {
            $user->rolesManyToMany()->sync($request->input('roles'));
            $existingRoles = $user->getRoleNames();
            $newRoles = array_diff($request->input('roles'), $existingRoles->toArray());
            $user->assignRole($newRoles);
        }

        return response()->json($user->load('rolesManyToMany'));
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Assign roles to a user.
     */
    public function assignRoles(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'roles' => ['required', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->rolesManyToMany()->syncWithoutDetaching($request->input('roles'));
        $existingRoles = $user->getRoleNames();
        $newRoles = array_diff($request->input('roles'), $existingRoles->toArray());
        $user->assignRole($newRoles, 'syncWithoutDetaching');

        return response()->json($user->load('rolesManyToMany'), 200);
    }

    /**
     * Remove specific roles from a user.
     */
    public function removeRoles(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'roles' => ['required', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->rolesManyToMany()->detach($request->input('roles'));
        $user->removeRole($request->input('roles'));

        return response()->json([
            'message' => 'Roles removed successfully',
            'user' => $user->load('rolesManyToMany')
        ], 200);
    }

    /**
     * Remove all roles from a user.
     */
    public function removeAllRoles(User $user)
    {
        $user->rolesManyToMany()->sync([]);
        $user->removeAllRoles();

        return response()->json([
            'message' => 'All roles removed successfully',
            'user' => $user->load('rolesManyToMany')
        ], 200);
    }

    /**
     * Get all available roles.
     */
    public function availableRoles()
    {
        $roles = Role::orderBy('name', 'asc')->get(['id', 'name']);
        return response()->json($roles);
    }

    /**
     * Get users by role.
     */
    public function getUsersByRole(Role $role)
    {
        $users = $role->usersManyToMany()->with('rolesManyToMany')->get();
        
        return response()->json([
            'role' => $role,
            'users' => $users
        ]);
    }

    /**
     * Get top-level menus accessible to the authenticated user based on permissions.
     */
    public function topLevelMenus(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $permissionNames = $user->getAllPermissions()->pluck('name');

        $menus = \App\Models\Menu::active()
            ->rootMenus()
            ->whereIn('permission', $permissionNames)
            ->orderBy('order')
            ->get(['id', 'title', 'route', 'icon', 'order']);

        return response()->json($menus);
    }
}
