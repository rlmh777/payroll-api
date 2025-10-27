<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRoleController extends Controller
{
    /**
     * Display a listing of users with their roles.
     */
    public function index(Request $request)
    {
        $query = User::with('roles');

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role_id')) {
            $query->whereHas('roles', function ($q) use ($request) {
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
     * Display the specified user with their roles.
     */
    public function show(User $user)
    {
        return $user->load('roles');
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

        $user->syncRoles($request->input('roles'));

        return response()->json([
            'message' => 'Roles assigned successfully',
            'user' => $user->load('roles')
        ], 200);
    }

    /**
     * Add roles to a user (without removing existing ones).
     */
    public function addRoles(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'roles' => ['required', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->assignRole($request->input('roles'));

        return response()->json([
            'message' => 'Roles added successfully',
            'user' => $user->load('roles')
        ], 200);
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

        $user->removeRole($request->input('roles'));

        return response()->json([
            'message' => 'Roles removed successfully',
            'user' => $user->load('roles')
        ], 200);
    }

    /**
     * Remove all roles from a user.
     */
    public function removeAllRoles(User $user)
    {
        $user->syncRoles([]);

        return response()->json([
            'message' => 'All roles removed successfully',
            'user' => $user->load('roles')
        ], 200);
    }

    /**
     * Get all available roles.
     */
    public function availableRoles()
    {
        $roles = Role::orderBy('name', 'asc')->get();
        
        return response()->json($roles);
    }

    /**
     * Get users by role.
     */
    public function getUsersByRole(Role $role)
    {
        $users = $role->users()->with('roles')->get();
        
        return response()->json([
            'role' => $role,
            'users' => $users
        ]);
    }
}
