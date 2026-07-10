<?php

namespace Database\Seeders;

use App\Models\SsBenefitType;
use Illuminate\Database\Seeder;

class SsBenefitTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Pension', 'Disability', 'Survivor', 'Other'] as $name) {
            SsBenefitType::updateOrCreate(['name' => $name]);
        }
    }
}
