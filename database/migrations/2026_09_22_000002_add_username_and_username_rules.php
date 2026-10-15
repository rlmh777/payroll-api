<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->after('name');
        });

        $used = [];
        foreach (DB::table('users')->orderBy('email')->get(['id', 'email', 'username']) as $user) {
            $existing = strtolower(trim((string) ($user->username ?? '')));
            if ($existing !== '' && ! isset($used[$existing])) {
                $used[$existing] = true;
                if ($existing !== (string) $user->username) {
                    DB::table('users')->where('id', $user->id)->update(['username' => $existing]);
                }
                continue;
            }

            $local = strtolower((string) strstr((string) $user->email, '@', true));
            $base = preg_replace('/[^a-z0-9._-]/', '', $local) ?: 'user';
            $base = substr($base, 0, 60);
            $username = $base;
            $suffix = 2;
            while (isset($used[$username])) {
                $username = substr($base, 0, 60).$suffix;
                $suffix++;
            }
            $used[$username] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });

        Schema::table('auth_settings', function (Blueprint $table) {
            $table->string('username_pattern', 40)->default('first_last');
            $table->string('username_separator', 8)->default('.');
            $table->boolean('username_include_middle_initial')->default(true);
            $table->string('employee_login_domain', 255)->nullable();
        });

        if (! Schema::hasTable('menus')) {
            return;
        }

        $adminSettingsId = Menu::query()->where('system_key', 'admin.settings')->value('id');
        if (! $adminSettingsId) {
            return;
        }

        Menu::query()->updateOrCreate(
            ['system_key' => 'admin.login'],
            [
                'parent_id' => $adminSettingsId,
                'title' => 'Login',
                'route' => '/admin/settings/login',
                'icon' => 'lock',
                'permission' => 'manager-users',
                'order' => 9,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'admin.login')->delete();
        }

        Schema::table('auth_settings', function (Blueprint $table) {
            $table->dropColumn([
                'username_pattern',
                'username_separator',
                'username_include_middle_initial',
                'employee_login_domain',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
