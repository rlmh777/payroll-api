<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('model', 64)->default('DS-K1T342');
            $table->string('serial_number', 128)->nullable()->unique();
            $table->string('location')->nullable();
            $table->string('timezone', 64)->default('America/Belize');
            $table->string('protocol', 32)->default('http_push');
            $table->string('auth_token_hash');
            $table->string('auth_token_prefix', 16);
            $table->unsignedInteger('heartbeat_timeout_seconds')->default(90);
            $table->boolean('is_active')->default(true);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('firmware')->nullable();
            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('biometric_device_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_id')->unique();
            $table->foreignUuid('biometric_device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('employee_no');
            $table->uuid('employee_id')->nullable();
            $table->boolean('identified')->default(false);
            $table->dateTime('event_time');
            $table->string('punch_type', 16)->nullable();
            $table->uuid('clocking_log_id')->nullable();
            $table->boolean('duplicate')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['biometric_device_id', 'event_time']);
            $table->index('employee_no');
        });

        $view = Permission::firstOrCreate([
            'name' => 'view-biometric-devices',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'biometric-devices-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin'])
            ->get()
            ->each(function (Role $role) use ($view, $crud) {
                $role->givePermissionTo([$view, $crud]);
                $role->users()->get()->each(
                    fn ($user) => $user->givePermissionTo([$view, $crud]),
                );
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Schema::hasTable('menus')) {
            return;
        }

        $adminSettingsId = Menu::query()->where('system_key', 'admin.settings')->value('id');
        if (! $adminSettingsId) {
            return;
        }

        Menu::query()->updateOrCreate(
            ['system_key' => 'admin.biometric_devices'],
            [
                'parent_id' => $adminSettingsId,
                'title' => 'Biometric Devices',
                'route' => '/admin/settings/biometric-devices',
                'icon' => 'fingerprint',
                'permission' => 'view-biometric-devices',
                'order' => 12,
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
            Menu::query()->where('system_key', 'admin.biometric_devices')->delete();
        }

        Permission::query()->whereIn('name', [
            'view-biometric-devices',
            'biometric-devices-crud',
        ])->delete();

        Schema::dropIfExists('biometric_device_events');
        Schema::dropIfExists('biometric_devices');
    }
};
