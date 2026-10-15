<?php

namespace Database\Seeders;

use App\Models\SocialSecurity;
use Illuminate\Database\Seeder;

class SocialSecuritySeeder extends Seeder
{
    /**
     * Belize Social Security Board weekly contribution schedule.
     */
    public function run(): void
    {
        $tiers = [
            ['weeklyEarningsStartRange' => 0, 'weeklyEarningsEndRange' => 69.99, 'weeklyInsurableEarnings' => 55, 'weeklyEmployeeContributions' => 1.03, 'weeklyEmployerContributions' => 4.47, 'weekyEmployeeContributionsRate' => 1.88, 'weeklyEmployerContributionsRate' => 8.13, 'maxWeeklyShortTermBenefit' => 44, 'maxWeeklyPensions' => 47, 'maxYearlyPension' => 2444],
            ['weeklyEarningsStartRange' => 70, 'weeklyEarningsEndRange' => 109.99, 'weeklyInsurableEarnings' => 90, 'weeklyEmployeeContributions' => 1.69, 'weeklyEmployerContributions' => 7.31, 'weekyEmployeeContributionsRate' => 1.88, 'weeklyEmployerContributionsRate' => 8.13, 'maxWeeklyShortTermBenefit' => 72, 'maxWeeklyPensions' => 54, 'maxYearlyPension' => 2808],
            ['weeklyEarningsStartRange' => 110, 'weeklyEarningsEndRange' => 139.99, 'weeklyInsurableEarnings' => 130, 'weeklyEmployeeContributions' => 2.44, 'weeklyEmployerContributions' => 10.56, 'weekyEmployeeContributionsRate' => 1.88, 'weeklyEmployerContributionsRate' => 8.13, 'maxWeeklyShortTermBenefit' => 104, 'maxWeeklyPensions' => 78, 'maxYearlyPension' => 4056],
            ['weeklyEarningsStartRange' => 140, 'weeklyEarningsEndRange' => 179.99, 'weeklyInsurableEarnings' => 160, 'weeklyEmployeeContributions' => 3.94, 'weeklyEmployerContributions' => 12.06, 'weekyEmployeeContributionsRate' => 2.46, 'weeklyEmployerContributionsRate' => 7.54, 'maxWeeklyShortTermBenefit' => 128, 'maxWeeklyPensions' => 96, 'maxYearlyPension' => 4992],
            ['weeklyEarningsStartRange' => 180, 'weeklyEarningsEndRange' => 219.99, 'weeklyInsurableEarnings' => 200, 'weeklyEmployeeContributions' => 5.94, 'weeklyEmployerContributions' => 14.06, 'weekyEmployeeContributionsRate' => 2.97, 'weeklyEmployerContributionsRate' => 7.03, 'maxWeeklyShortTermBenefit' => 160, 'maxWeeklyPensions' => 120, 'maxYearlyPension' => 6240],
            ['weeklyEarningsStartRange' => 220, 'weeklyEarningsEndRange' => 259.99, 'weeklyInsurableEarnings' => 240, 'weeklyEmployeeContributions' => 7.94, 'weeklyEmployerContributions' => 16.06, 'weekyEmployeeContributionsRate' => 3.31, 'weeklyEmployerContributionsRate' => 6.69, 'maxWeeklyShortTermBenefit' => 192, 'maxWeeklyPensions' => 144, 'maxYearlyPension' => 7488],
            ['weeklyEarningsStartRange' => 260, 'weeklyEarningsEndRange' => 299.99, 'weeklyInsurableEarnings' => 280, 'weeklyEmployeeContributions' => 9.94, 'weeklyEmployerContributions' => 18.06, 'weekyEmployeeContributionsRate' => 3.55, 'weeklyEmployerContributionsRate' => 6.45, 'maxWeeklyShortTermBenefit' => 224, 'maxWeeklyPensions' => 168, 'maxYearlyPension' => 8736],
            ['weeklyEarningsStartRange' => 300, 'weeklyEarningsEndRange' => 339.99, 'weeklyInsurableEarnings' => 320, 'weeklyEmployeeContributions' => 11.94, 'weeklyEmployerContributions' => 20.06, 'weekyEmployeeContributionsRate' => 3.73, 'weeklyEmployerContributionsRate' => 6.27, 'maxWeeklyShortTermBenefit' => 256, 'maxWeeklyPensions' => 192, 'maxYearlyPension' => 9984],
            ['weeklyEarningsStartRange' => 340, 'weeklyEarningsEndRange' => 379.99, 'weeklyInsurableEarnings' => 360, 'weeklyEmployeeContributions' => 13.98, 'weeklyEmployerContributions' => 22.02, 'weekyEmployeeContributionsRate' => 3.88, 'weeklyEmployerContributionsRate' => 6.12, 'maxWeeklyShortTermBenefit' => 288, 'maxWeeklyPensions' => 216, 'maxYearlyPension' => 11232],
            ['weeklyEarningsStartRange' => 380, 'weeklyEarningsEndRange' => 419.99, 'weeklyInsurableEarnings' => 400, 'weeklyEmployeeContributions' => 16.15, 'weeklyEmployerContributions' => 23.85, 'weekyEmployeeContributionsRate' => 4.04, 'weeklyEmployerContributionsRate' => 5.96, 'maxWeeklyShortTermBenefit' => 320, 'maxWeeklyPensions' => 240, 'maxYearlyPension' => 12480],
            ['weeklyEarningsStartRange' => 420, 'weeklyEarningsEndRange' => 459.99, 'weeklyInsurableEarnings' => 440, 'weeklyEmployeeContributions' => 18.45, 'weeklyEmployerContributions' => 25.55, 'weekyEmployeeContributionsRate' => 4.19, 'weeklyEmployerContributionsRate' => 5.81, 'maxWeeklyShortTermBenefit' => 352, 'maxWeeklyPensions' => 264, 'maxYearlyPension' => 13728],
            ['weeklyEarningsStartRange' => 460, 'weeklyEarningsEndRange' => 499.99, 'weeklyInsurableEarnings' => 480, 'weeklyEmployeeContributions' => 20.86, 'weeklyEmployerContributions' => 27.14, 'weekyEmployeeContributionsRate' => 4.35, 'weeklyEmployerContributionsRate' => 5.65, 'maxWeeklyShortTermBenefit' => 384, 'maxWeeklyPensions' => 288, 'maxYearlyPension' => 14976],
            ['weeklyEarningsStartRange' => 500, 'weeklyEarningsEndRange' => null, 'weeklyInsurableEarnings' => 520, 'weeklyEmployeeContributions' => 23.40, 'weeklyEmployerContributions' => 28.60, 'weekyEmployeeContributionsRate' => 4.50, 'weeklyEmployerContributionsRate' => 5.50, 'maxWeeklyShortTermBenefit' => 416, 'maxWeeklyPensions' => 312, 'maxYearlyPension' => 16224],
        ];

        foreach ($tiers as $tier) {
            SocialSecurity::updateOrCreate(
                ['weeklyEarningsStartRange' => $tier['weeklyEarningsStartRange']],
                array_merge($tier, ['state' => 'active']),
            );
        }
    }
}
