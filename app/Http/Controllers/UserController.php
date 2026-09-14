<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRole;
use App\Modules\Hr\Services\Leave\LeaveSupervisorAuthorizationService;
use App\Services\CompanyModuleService;
use App\Services\MenuAuthorizationService;
use App\Models\Role;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(
        private readonly LeaveSupervisorAuthorizationService $supervisorAuthorization,
    ) {
    }
    /**
     * Display a listing of users with their roles.
     */
    public function index(Request $request)
    {
        $this->assertCanListUsers($request->user());

        $query = User::with(['rolesManyToMany', 'employee']);

        if ($this->mustScopeUsersToSubordinates($request->user())) {
            $subordinateIds = $this->supervisorAuthorization
                ->subordinateEmployeeIds($request->user())
                ->map(fn ($id) => (string) $id)
                ->all();

            $query->whereHas('employee', function ($q) use ($subordinateIds) {
                $q->whereIn('id', $subordinateIds);
            });
        }

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
        $this->assertCanManageUsers($request->user());

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
            $this->attachUserRoles($user, $request->input('roles', []));
        }

        return response()->json($user->load('rolesManyToMany'), 201);
    }

    /**
     * Display the specified user with their roles.
     */
    public function show(Request $request, User $user)
    {
        $this->assertCanViewUser($request->user(), $user);

        return $user->load(['rolesManyToMany', 'employee']);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $this->assertCanManageUsers($request->user());

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
            $this->syncUserRoles($user, $request->input('roles', []));
        }

        return response()->json($user->load('rolesManyToMany'));
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user)
    {
        $this->assertCanManageUsers($request->user());

        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Assign roles to a user.
     */
    public function assignRoles(Request $request, User $user)
    {
        $this->assertCanManageUsers($request->user());

        $validator = Validator::make($request->all(), [
            'roles' => ['required', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $this->attachUserRoles($user, $request->input('roles', []));

        return response()->json($user->load('rolesManyToMany'), 200);
    }

    /**
     * Add roles to a user (alias of assignRoles for PUT /users/{user}/roles).
     */
    public function addRoles(Request $request, User $user)
    {
        return $this->assignRoles($request, $user);
    }

    /**
     * Remove specific roles from a user.
     */
    public function removeRoles(Request $request, User $user)
    {
        $this->assertCanManageUsers($request->user());

        $validator = Validator::make($request->all(), [
            'roles' => ['required', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $this->detachUserRoles($user, $request->input('roles', []));

        return response()->json([
            'message' => 'Roles removed successfully',
            'user' => $user->load('rolesManyToMany')
        ], 200);
    }

    /**
     * Remove all roles from a user.
     */
    public function removeAllRoles(Request $request, User $user)
    {
        $this->assertCanManageUsers($request->user());

        $this->syncUserRoles($user, []);

        return response()->json([
            'message' => 'All roles removed successfully',
            'user' => $user->load('rolesManyToMany')
        ], 200);
    }

    /**
     * Get all available roles.
     */
    public function availableRoles(Request $request)
    {
        $this->assertCanManageUsers($request->user());

        $roles = Role::orderBy('name', 'asc')->get(['id', 'name']);
        return response()->json($roles);
    }

    /**
     * Get users by role.
     */
    public function getUsersByRole(Request $request, Role $role)
    {
        $this->assertCanManageUsers($request->user());

        $users = $role->usersManyToMany()->with('rolesManyToMany')->get();
        
        return response()->json([
            'role' => $role,
            'users' => $users
        ]);
    }

    /**
     * Update user password.
     */
    public function updatePassword(Request $request, User $user)
    {
        $this->assertCanManageUserPassword($request->user(), $user);

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'message' => 'Password updated successfully',
            'user' => $user->load(['rolesManyToMany', 'employee']),
        ]);
    }

    /**
     * Link or unlink user to employee.
     */
    public function linkEmployee(Request $request, User $user)
    {
        $this->assertCanManageUsers($request->user());

        $validator = Validator::make($request->all(), [
            'employee_id' => ['nullable', 'uuid', 'exists:employee,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee is already linked to another user
        if ($request->has('employee_id') && $request->input('employee_id')) {
            $employeeId = $request->input('employee_id');
            $existingUser = \App\Models\Employee::where('id', $employeeId)
                ->whereNotNull('user_id')
                ->where('user_id', '!=', $user->id)
                ->first();
            
            if ($existingUser) {
                return response()->json([
                    'errors' => ['employee_id' => ['This employee is already linked to another user']]
                ], 422);
            }
        }

        // Update employee's user_id
        if ($request->has('employee_id') && $request->input('employee_id')) {
            \App\Models\Employee::where('id', $request->input('employee_id'))
                ->update(['user_id' => $user->id]);
        } else {
            // Unlink: set employee's user_id to null
            \App\Models\Employee::where('user_id', $user->id)
                ->update(['user_id' => null]);
        }

        return response()->json([
            'message' => 'Employee link updated successfully',
            'user' => $user->load(['rolesManyToMany', 'employee'])
        ]);
    }

    /**
     * Send password reset email to user.
     */
    public function sendPasswordResetEmail(Request $request, User $user)
    {
        $this->assertCanManageUserPassword($request->user(), $user);

        $status = Password::sendResetLink(
            ['email' => $user->email]
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset email sent successfully',
            ]);
        }

        return response()->json([
            'message' => 'Failed to send password reset email',
            'error' => $status,
        ], 400);
    }

    /**
     * Reset password using token from email.
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'exists:users,email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successfully',
            ]);
        }

        return response()->json([
            'message' => 'Failed to reset password',
            'error' => $status
        ], 400);
    }

    /**
     * Get top-level menus accessible to the authenticated user based on permissions.
     */
    public function topLevelMenus(Request $request, MenuAuthorizationService $menuAuthorizationService, CompanyModuleService $companyModuleService)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $tree = $menuAuthorizationService->menuTreeForUser($user, $companyModuleService->enabledModuleCodes());

        return response()->json(collect($tree)->map(function (array $menu) {
            return [
                'id' => $menu['id'],
                'title' => $menu['title'],
                'route' => $menu['route'],
                'icon' => $menu['icon'],
                'order' => $menu['order'],
                'children' => collect($menu['children'] ?? [])->map(fn (array $child) => [
                    'id' => $child['id'],
                    'title' => $child['title'],
                    'route' => $child['route'],
                    'icon' => $child['icon'],
                    'order' => $child['order'],
                ])->values(),
            ];
        })->values());
    }

    /**
     * Get the full menu tree accessible to the authenticated user.
     */
    public function userMenus(Request $request, MenuAuthorizationService $menuAuthorizationService, CompanyModuleService $companyModuleService)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $enabledCodes = $companyModuleService->enabledModuleCodes();

        return response()->json($menuAuthorizationService->menuTreeForUser($user, $enabledCodes));
    }

    /**
     * Attach roles without removing existing ones.
     * Keeps both the UUID user_roles pivot and Spatie model_has_roles in sync.
     *
     * @param  list<string>  $roleIds
     */
    private function attachUserRoles(User $user, array $roleIds): void
    {
        $roleIds = $this->normalizeRoleIds($roleIds);
        if ($roleIds === []) {
            return;
        }

        $roles = Role::query()->whereIn('id', $roleIds)->get();

        foreach ($roles as $role) {
            UserRole::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ],
                [
                    'id' => (string) Str::uuid(),
                ]
            );

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }

    /**
     * Replace a user's roles (both pivots).
     *
     * @param  list<string>  $roleIds
     */
    private function syncUserRoles(User $user, array $roleIds): void
    {
        $roleIds = $this->normalizeRoleIds($roleIds);
        $roles = Role::query()->whereIn('id', $roleIds)->get();

        $stale = UserRole::query()->where('user_id', $user->id);
        if ($roleIds !== []) {
            $stale->whereNotIn('role_id', $roleIds);
        }
        $stale->delete();

        foreach ($roles as $role) {
            UserRole::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ],
                [
                    'id' => (string) Str::uuid(),
                ]
            );
        }

        $user->syncRoles($roles);
    }

    /**
     * @param  list<string>  $roleIds
     */
    private function detachUserRoles(User $user, array $roleIds): void
    {
        $roleIds = $this->normalizeRoleIds($roleIds);
        if ($roleIds === []) {
            return;
        }

        $roles = Role::query()->whereIn('id', $roleIds)->get();

        UserRole::query()
            ->where('user_id', $user->id)
            ->whereIn('role_id', $roleIds)
            ->delete();

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                $user->removeRole($role);
            }
        }
    }

    /**
     * @param  list<mixed>  $roleIds
     * @return list<string>
     */
    private function normalizeRoleIds(array $roleIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_string($id) || is_numeric($id) ? (string) $id : null,
            $roleIds
        ))));
    }

    private function assertCanListUsers(?User $actor): void
    {
        if (! $actor) {
            abort(401, 'Unauthenticated.');
        }

        if (Access::canManageAllUserPasswords($actor) || Access::can($actor, 'reset-subordinate-passwords')) {
            return;
        }

        abort(403, 'You are not allowed to view users.');
    }

    private function assertCanViewUser(?User $actor, User $target): void
    {
        $this->assertCanListUsers($actor);

        if (! $this->mustScopeUsersToSubordinates($actor)) {
            return;
        }

        $target->loadMissing('employee');
        $employeeId = $target->employee?->id;

        if (! $employeeId || ! $this->supervisorAuthorization->isDirectSupervisorOf($actor, (string) $employeeId)) {
            abort(403, 'You can only view users linked to employees who report to you.');
        }
    }

    private function assertCanManageUsers(?User $actor): void
    {
        if (! $actor) {
            abort(401, 'Unauthenticated.');
        }

        if (! Access::canManageAllUserPasswords($actor)) {
            abort(403, 'You are not allowed to manage users.');
        }
    }

    private function mustScopeUsersToSubordinates(?User $actor): bool
    {
        return $actor
            && ! Access::canManageAllUserPasswords($actor)
            && Access::can($actor, 'reset-subordinate-passwords');
    }

    private function assertCanManageUserPassword(?User $actor, User $target): void
    {
        if (! $actor) {
            abort(401, 'Unauthenticated.');
        }

        if (Access::canManageAllUserPasswords($actor)) {
            return;
        }

        if (! Access::can($actor, 'reset-subordinate-passwords')) {
            abort(403, 'You are not allowed to reset passwords.');
        }

        $target->loadMissing('employee');
        $employeeId = $target->employee?->id;

        if (! $employeeId || ! $this->supervisorAuthorization->isDirectSupervisorOf($actor, (string) $employeeId)) {
            abort(403, 'You can only reset passwords for employees who report to you.');
        }

        if ((string) $actor->id === (string) $target->id) {
            abort(403, 'You cannot reset your own password with this action.');
        }
    }
}
