<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Honorific;

class HonorificSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $honorifics = [
            // Common titles
            'Mr',
            'Mrs',
            'Miss',
            'Ms',
            
            // Professional titles
            'Dr',
            'Prof',
            'Professor',
            'Rev',
            'Reverend',
            'Hon',
            'Honorable',
            
            // Academic titles
            'PhD',
            'MD',
            'DDS',
            'DVM',
            'Esq',
            'Esquire',
            
            // Military titles
            'Capt',
            'Captain',
            'Col',
            'Colonel',
            'Gen',
            'General',
            'Lt',
            'Lieutenant',
            'Maj',
            'Major',
            'Sgt',
            'Sergeant',
            'Cpl',
            'Corporal',
            'Pvt',
            'Private',
            'Cmdr',
            'Commander',
            'Adm',
            'Admiral',
            
            // Royal and noble titles
            'Sir',
            'Dame',
            'Lord',
            'Lady',
            'Baron',
            'Baroness',
            'Earl',
            'Countess',
            'Duke',
            'Duchess',
            'Prince',
            'Princess',
            'King',
            'Queen',
            
            // Religious titles
            'Fr',
            'Father',
            'Sr',
            'Sister',
            'Br',
            'Brother',
            'Pastor',
            'Bishop',
            'Archbishop',
            'Cardinal',
            'Imam',
            'Rabbi',
            
            // Other titles
            'Ambassador',
            'Senator',
            'Governor',
            'Mayor',
            'Judge',
            'Chief',
        ];

        foreach ($honorifics as $honorific) {
            Honorific::firstOrCreate([
                'name' => $honorific
            ]);
        }
    }
}
