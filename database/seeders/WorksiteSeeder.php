<?php

namespace Database\Seeders;

use App\Models\Locality;
use App\Models\Worksite;
use Illuminate\Database\Seeder;

class WorksiteSeeder extends Seeder
{
    public function run(): void
    {
        $locality = Locality::query()->first();

        if (!$locality) {
            $this->command?->warn('WorksiteSeeder skipped: no localities found.');
            return;
        }

        Worksite::updateOrCreate(
            ['name' => 'Head Office'],
            [
                'address1' => '1 Administration Drive',
                'address2' => null,
                'localityId' => $locality->id,
            ],
        );

        Worksite::updateOrCreate(
            ['name' => 'Branch Office'],
            [
                'address1' => '45 Commerce Street',
                'address2' => null,
                'localityId' => $locality->id,
            ],
        );
    }
}
