<?php

use App\Models\Permission;
use App\Models\PayrollEarningCode;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            foreach (['view-pool-distribution-types', 'pool-distribution-type-crud'] as $permissionName) {
                Permission::firstOrCreate(['name' => $permissionName]);
            }

            foreach (['super-admin', 'admin', 'hr-admin', 'payroll-admin'] as $roleName) {
                $role = Role::query()->where('name', $roleName)->first();
                if ($role) {
                    $role->givePermissionTo([
                        'view-pool-distribution-types',
                        'pool-distribution-type-crud',
                    ]);
                }
            }
        }

        if (Schema::hasTable('menus')) {
            $payrollSettingsId = DB::table('menus')
                ->where('title', 'Payroll')
                ->where('type', 'submenu')
                ->orderBy('id')
                ->value('id');

            $parentId = $payrollSettingsId
                ?: DB::table('menus')
                    ->where('title', 'General')
                    ->where('type', 'submenu')
                    ->orderBy('id')
                    ->value('id');

            if ($parentId) {
                $existing = DB::table('menus')->where('route', '/settings/pool-distribution-types')->first();
                $payload = [
                    'parent_id' => $parentId,
                    'title' => 'Pool Distribution',
                    'icon' => 'pie_chart',
                    'permission' => 'view-pool-distribution-types',
                    'order' => 42,
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
                        'route' => '/settings/pool-distribution-types',
                        'created_at' => now(),
                    ]);
                }
            }
        }

        if (!Schema::hasTable('pool_distribution_type')) {
            return;
        }

        $tipsEarningCodeId = PayrollEarningCode::query()->where('code', 'TIPS')->value('id');
        $otherEarningCodeId = PayrollEarningCode::query()->where('code', 'OTHER')->value('id');

        $defaults = [
            [
                'code' => 'TIPS',
                'name' => 'Tips',
                'calculation_mode' => 'weighted_points',
                'payroll_earning_code_id' => $tipsEarningCodeId,
                'sort_order' => 1,
            ],
            [
                'code' => 'SHARES',
                'name' => 'Shares',
                'calculation_mode' => 'weighted_points',
                'payroll_earning_code_id' => $otherEarningCodeId,
                'sort_order' => 2,
            ],
        ];

        foreach ($defaults as $row) {
            $exists = DB::table('pool_distribution_type')->where('code', $row['code'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('pool_distribution_type')->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'calculation_mode' => $row['calculation_mode'],
                'is_active' => true,
                'requires_hours_eligibility' => true,
                'payroll_earning_code_id' => $row['payroll_earning_code_id'],
                'allowance_id' => null,
                'is_taxable' => true,
                'is_ss_subject' => true,
                'sort_order' => $row['sort_order'],
                'notes' => 'Sample pool type — rename, disable, or change calculation mode as needed.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/settings/pool-distribution-types')->delete();
        }
    }
};
