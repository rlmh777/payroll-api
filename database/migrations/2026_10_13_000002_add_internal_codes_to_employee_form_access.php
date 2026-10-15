<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()->each(function (Role $role) {
            $profile = $role->employee_form_access;
            if (! is_array($profile) || $profile === []) {
                return;
            }

            $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
            $mode = $fields['firstName'] ?? $fields['code'] ?? 'hidden';
            if (! in_array($mode, ['hidden', 'view', 'locked', 'edit'], true)) {
                $mode = 'hidden';
            }

            $changed = false;
            foreach (['internalId1', 'internalId2'] as $key) {
                if (! array_key_exists($key, $fields)) {
                    $fields[$key] = $mode;
                    $changed = true;
                }
            }

            if (! $changed) {
                return;
            }

            $profile['fields'] = $fields;
            $role->employee_form_access = $profile;
            $role->save();
        });
    }

    public function down(): void
    {
        Role::query()->each(function (Role $role) {
            $profile = $role->employee_form_access;
            if (! is_array($profile) || ! is_array($profile['fields'] ?? null)) {
                return;
            }

            unset($profile['fields']['internalId1'], $profile['fields']['internalId2']);
            $role->employee_form_access = $profile;
            $role->save();
        });
    }
};
