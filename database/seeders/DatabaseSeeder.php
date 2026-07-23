<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            GenderSeeder::class,
            HonorificSeeder::class,
            CitizenshipStatusSeeder::class,
            PaymentMethodSeeder::class,
            PayrateFrequencySeeder::class,
            CountrySeeder::class,
            DistrictSeeder::class,
            localitiesSeeder::class,
            RoleAndPermissionSeeder::class,
            // Menus (and their permissions) must exist before role permission sync.
            MenuSeeder::class,
            UserRoleSeeder::class,
            DegreeSeeder::class,
            EmployeeStatusSeeder::class,
            ContractTypeSeeder::class,
            EmploymentStatusSeeder::class,
            RelationshipSeeder::class,
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
            TimesheetTemplateSeeder::class,
            CalendarGroupSeeder::class,
            BelizePublicHolidays2026Seeder::class,
            PayPeriodGroupSeeder::class,
            EmployeeImportTemplateSeeder::class,
            SupervisorUserSeeder::class,
            SupervisorDepartmentHeadSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
