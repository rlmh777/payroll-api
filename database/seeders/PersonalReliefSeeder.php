<?php

namespace Database\Seeders;

use App\Models\PersonalRelief;
use Illuminate\Database\Seeder;

class PersonalReliefSeeder extends Seeder
{
    /**
     * Belize personal relief brackets by annual earnings.
     */
    public function run(): void
    {
        $brackets = [
            [
                'startRange' => 0,
                'endRange' => 26000.00,
                'personalRelief' => 25600.00,
            ],
            [
                'startRange' => 26000.01,
                'endRange' => 27000.00,
                'personalRelief' => 24600.00,
            ],
            [
                'startRange' => 27000.01,
                'endRange' => 29000.00,
                'personalRelief' => 22600.00,
            ],
            [
                'startRange' => 29000.01,
                'endRange' => 999999999.99,
                'personalRelief' => 19600.00,
            ],
        ];

        foreach ($brackets as $bracket) {
            PersonalRelief::updateOrCreate(
                ['startRange' => $bracket['startRange']],
                $bracket,
            );
        }
    }
}
