<?php

namespace Database\Seeders;

use App\Models\Locality;
use App\Models\Worksite;
use Illuminate\Database\Seeder;

class WorksiteSeeder extends Seeder
{
    public function run(): void
    {
        $locality = Locality::query()->whereRaw('LOWER(name) = ?', ['san ignacio'])->first()
            ?? Locality::query()->first();

        if (!$locality) {
            $this->command?->warn('WorksiteSeeder skipped: no localities found.');
            return;
        }

        foreach ([
            ['name' => 'Head Office', 'address1' => '1 Administration Drive'],
            ['name' => 'Branch Office', 'address1' => '45 Commerce Street'],
            ['name' => 'Business Office', 'address1' => 'Business Office'],
            ['name' => 'Guava Limb Café', 'address1' => 'Guava Limb Café'],
            ['name' => 'Resort', 'address1' => 'Resort'],
        ] as $site) {
            Worksite::updateOrCreate(
                ['name' => $site['name']],
                [
                    'address1' => $site['address1'],
                    'address2' => null,
                    'localityId' => $locality->id,
                ],
            );
        }
    }
}
