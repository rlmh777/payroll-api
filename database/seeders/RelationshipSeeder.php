<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Relationship;

class RelationshipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $relationships = [
            // Family Relationships
            ['name' => 'Spouse'],
            ['name' => 'Parent'],
            ['name' => 'Child'],
            ['name' => 'Sibling'],
            ['name' => 'Grandparent'],
            ['name' => 'Grandchild'],
            ['name' => 'Aunt'],
            ['name' => 'Uncle'],
            ['name' => 'Niece'],
            ['name' => 'Nephew'],
            ['name' => 'Cousin'],
            ['name' => 'In-Law'],

            // Emergency Contacts
            ['name' => 'Friend'],
            ['name' => 'Neighbor'],
            ['name' => 'Colleague'],
            ['name' => 'Guardian'],
            ['name' => 'Other']
        ];

        foreach ($relationships as $relationship) {
            Relationship::create($relationship);
        }
    }
} 