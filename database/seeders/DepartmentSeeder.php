<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $operations = Department::firstOrCreate(['name' => 'Operations'], ['parentId' => null]);
        $finance = Department::firstOrCreate(['name' => 'Finance'], ['parentId' => null]);
        $hr = Department::firstOrCreate(['name' => 'Human Resources'], ['parentId' => null]);
        $it = Department::firstOrCreate(['name' => 'IT'], ['parentId' => null]);
        $sales = Department::firstOrCreate(['name' => 'Sales'], ['parentId' => null]);

        Department::firstOrCreate(['name' => 'Payroll'], ['parentId' => $finance->id]);
        Department::firstOrCreate(['name' => 'Accounting'], ['parentId' => $finance->id]);
        Department::firstOrCreate(['name' => 'Recruiting'], ['parentId' => $hr->id]);
        Department::firstOrCreate(['name' => 'Support'], ['parentId' => $operations->id]);
        Department::firstOrCreate(['name' => 'Infrastructure'], ['parentId' => $it->id]);
        Department::firstOrCreate(['name' => 'Field Sales'], ['parentId' => $sales->id]);
    }
}
