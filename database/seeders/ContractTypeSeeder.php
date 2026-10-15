<?php

namespace Database\Seeders;

use App\Models\ContractType;
use Illuminate\Database\Seeder;

class ContractTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Legal / formal shape of the agreement (not hours or HR lifecycle).
        foreach ([
            'Permanent',
            'Fixed-Term Contract',
            'Temporary',
            'Casual',
            'Consultant',
            'Intern',
        ] as $name) {
            ContractType::updateOrCreate(['name' => $name]);
        }
    }
}
