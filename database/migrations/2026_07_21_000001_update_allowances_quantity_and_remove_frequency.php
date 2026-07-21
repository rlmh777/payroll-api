<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_default_allowance')) {
            Schema::table('employee_default_allowance', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_default_allowance', 'quantity')) {
                    $table->decimal('quantity', 12, 4)->default(1)->after('accountId');
                }
                if (! Schema::hasColumn('employee_default_allowance', 'unitAmount')) {
                    $table->decimal('unitAmount', 12, 2)->default(0)->after('quantity');
                }
            });

            DB::statement('UPDATE employee_default_allowance SET quantity = 1 WHERE COALESCE(quantity, 0) <= 0');
            DB::statement('UPDATE employee_default_allowance SET "unitAmount" = amount WHERE COALESCE("unitAmount", 0) = 0');

            if (Schema::hasColumn('employee_default_allowance', 'frequencyId')) {
                try {
                    DB::statement('ALTER TABLE employee_default_allowance DROP CONSTRAINT IF EXISTS employee_default_allowance_frequencyid_foreign');
                } catch (\Throwable) {
                    // Ignore missing constraint names.
                }

                Schema::table('employee_default_allowance', function (Blueprint $table) {
                    $table->dropColumn('frequencyId');
                });
            }
        }

        if (Schema::hasTable('historical_employee_allowance')) {
            Schema::table('historical_employee_allowance', function (Blueprint $table) {
                if (! Schema::hasColumn('historical_employee_allowance', 'quantity')) {
                    $table->decimal('quantity', 12, 4)->default(1)->after('account_id');
                }
                if (! Schema::hasColumn('historical_employee_allowance', 'unitAmount')) {
                    $table->decimal('unitAmount', 14, 2)->default(0)->after('quantity');
                }
            });

            DB::statement('ALTER TABLE historical_employee_allowance ALTER COLUMN amount TYPE numeric(14,2)');
            DB::statement('UPDATE historical_employee_allowance SET quantity = 1 WHERE COALESCE(quantity, 0) <= 0');
            DB::statement('UPDATE historical_employee_allowance SET "unitAmount" = amount WHERE COALESCE("unitAmount", 0) = 0');
        }

        if (Schema::hasTable('permissions')) {
            foreach (['view-payroll-allowances', 'payroll-allowances-crud'] as $permissionName) {
                Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            }

            foreach ([
                'admin',
                'hr-admin',
                'payroll-admin',
                'accountant',
                'payroll-accountant',
                'supervisor',
            ] as $roleName) {
                $role = Role::query()->where('name', $roleName)->first();
                if ($role) {
                    $role->givePermissionTo([
                        'view-payroll-allowances',
                        'payroll-allowances-crud',
                    ]);
                }
            }
        }

        if (! Schema::hasTable('menus')) {
            return;
        }

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (! $payrollMenuId) {
            return;
        }

        $existing = DB::table('menus')->where('route', '/payroll/allowances')->first();
        $payload = [
            'parent_id' => $payrollMenuId,
            'title' => 'Allowances',
            'icon' => 'fas fa-hand-holding-usd',
            'permission' => 'view-payroll-allowances',
            'order' => 4,
            'type' => 'submenu',
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('menus')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                ...$payload,
                'route' => '/payroll/allowances',
                'created_at' => now(),
            ]);
        }

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/generate-payslip')
            ->update(['order' => 5]);

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/taxes-filing')
            ->update(['order' => 6]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/payroll/allowances')->delete();

            $payrollMenuId = DB::table('menus')
                ->where('route', '/payroll')
                ->where('type', 'menu')
                ->value('id');

            if ($payrollMenuId) {
                DB::table('menus')
                    ->where('parent_id', $payrollMenuId)
                    ->where('route', '/payroll/generate-payslip')
                    ->update(['order' => 4]);

                DB::table('menus')
                    ->where('parent_id', $payrollMenuId)
                    ->where('route', '/payroll/taxes-filing')
                    ->update(['order' => 5]);
            }
        }

        if (Schema::hasTable('historical_employee_allowance')) {
            Schema::table('historical_employee_allowance', function (Blueprint $table) {
                if (Schema::hasColumn('historical_employee_allowance', 'quantity')) {
                    $table->dropColumn('quantity');
                }
                if (Schema::hasColumn('historical_employee_allowance', 'unitAmount')) {
                    $table->dropColumn('unitAmount');
                }
            });
        }

        if (Schema::hasTable('employee_default_allowance')) {
            Schema::table('employee_default_allowance', function (Blueprint $table) {
                if (Schema::hasColumn('employee_default_allowance', 'quantity')) {
                    $table->dropColumn('quantity');
                }
                if (Schema::hasColumn('employee_default_allowance', 'unitAmount')) {
                    $table->dropColumn('unitAmount');
                }
                if (! Schema::hasColumn('employee_default_allowance', 'frequencyId')) {
                    $table->foreignId('frequencyId')
                        ->nullable()
                        ->constrained('payrate_frequency')
                        ->nullOnDelete();
                }
            });
        }
    }
};
