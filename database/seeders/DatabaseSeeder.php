<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;


class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            GenderSeeder::class,
            HonorificSeeder::class,
            CitizenshipStatusSeeder::class,
            PaymentMethodSeeder::class,
            PayrateFrequencySeeder::class,
            CountrySeeder::class,
            DistrictSeeder::class,
            localitiesSeeder::class,
            EmployeeModelSeeder::class,
            RoleAndPermissionSeeder::class,
            UserRoleSeeder::class,
            SupervisorUserSeeder::class,
            DegreeSeeder::class,
            EmployeeStatusSeeder::class,
            ContractTypeSeeder::class,
            EmploymentStatusSeeder::class,
            RelationshipSeeder::class,
            MenuSeeder::class,
            AccountTypeSeeder::class,
            BankAccountTypeSeeder::class,
            SocialSecuritySeeder::class,
            SocialSecurityContributionRuleSeeder::class,
            PersonalReliefSeeder::class,
            SsBenefitTypeSeeder::class,
            PayrollChartOfAccountsSeeder::class,
            PayrollEarningCodeSeeder::class,
            CompanySeeder::class,
            DepartmentSeeder::class,
            WorksiteSeeder::class,
            CalendarGroupSeeder::class,
            BelizePublicHolidays2026Seeder::class,
            PayPeriodGroupSeeder::class,
            EmploymentDetailSeeder::class,
            SupervisorDepartmentHeadSeeder::class,
            SchedulerSeeder::class,
            TimesheetSeeder::class,
            PayrollRunSeeder::class,
        ]);
    }
}
