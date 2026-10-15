<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\CitizenshipStatus;


class CitizenshipStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            'Belizean Citizen',
            'Permanent Resident',
            'Work Permit Holder',
            'Temporary Resident',
            'Visitor',
            'Non-Resident',
            'Refugee',
            'Stateless',
        ];

        foreach ($statuses as $status) {
            CitizenshipStatus::create([
                'name' => $status
            ]);
        }
    }
}
