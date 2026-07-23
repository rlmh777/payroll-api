<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SupervisorUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supervisorUser = User::updateOrCreate(
            ['email' => 'supervisor@example.com'],
            [
                'name' => 'Supervisor User',
                'password' => Hash::make('Password123!'),
            ]
        );

        $employeeUser = User::updateOrCreate(
            ['email' => 'employee@example.com'],
            [
                'name' => 'Employee User',
                'password' => Hash::make('Password123!'),
            ]
        );

        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee']);

        if (!$supervisorUser->hasRole($supervisorRole->name)) {
            $supervisorUser->assignRole($supervisorRole->name);
        }
        if (!$employeeUser->hasRole($employeeRole->name)) {
            $employeeUser->assignRole($employeeRole->name);
        }

        UserRole::firstOrCreate(
            [
                'user_id' => $supervisorUser->id,
                'role_id' => $supervisorRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );

        UserRole::firstOrCreate(
            [
                'user_id' => $employeeUser->id,
                'role_id' => $employeeRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );

        $supervisorEmployee = Employee::query()->where('code', '100001')->first()
            ?? Employee::query()->orderBy('code')->first();
        if ($supervisorEmployee) {
            $supervisorEmployee->update([
                'user_id' => $supervisorUser->id,
            ]);
        }

        $employeeRecord = Employee::query()->where('code', '100002')->first()
            ?? Employee::query()->where('code', '!=', $supervisorEmployee?->code)->orderBy('code')->first();
        if ($employeeRecord) {
            $employeeRecord->update([
                'user_id' => $employeeUser->id,
            ]);
        }

        $adminUser = User::query()->where('email', 'johndoe@gmail.com')->first();
        if ($adminUser) {
            $adminEmployee = Employee::query()
                ->whereNull('user_id')
                ->orderBy('code')
                ->first();

            if ($adminEmployee) {
                $adminEmployee->update([
                    'user_id' => $adminUser->id,
                ]);
            }
        }

        if ($supervisorEmployee) {
            $subordinates = Employee::query()
                ->where('id', '!=', $supervisorEmployee->id)
                ->orderBy('code')
                ->take(6)
                ->pluck('id');

            foreach ($subordinates as $subordinateId) {
                EmployeeReporting::firstOrCreate(
                    [
                        'supervisor_id' => $supervisorEmployee->id,
                        'subordinate_id' => $subordinateId,
                        'reporting_method' => 'direct',
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
