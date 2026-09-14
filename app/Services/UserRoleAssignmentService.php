<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Str;

class UserRoleAssignmentService
{
    /**
     * Attach roles without removing existing ones.
     * Keeps both the UUID user_roles pivot and Spatie model_has_roles in sync.
     *
     * @param  list<string>  $roleIds
     */
    public function attach(User $user, array $roleIds): void
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
    public function sync(User $user, array $roleIds): void
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
    public function detach(User $user, array $roleIds): void
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
}
