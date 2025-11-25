<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\LeaveType;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $leaveTypes = [
            'Annual Leave',
            'Sick Leave',
            'Personal Leave',
            'Maternity Leave',
            'Paternity Leave',
            'Bereavement Leave',
            'Unpaid Leave',
            'Study Leave',
            'Compensatory Leave',
            'Public Holiday',
        ];

        foreach ($leaveTypes as $leaveType) {
            LeaveType::create([
                'name' => $leaveType
            ]);
        }
    }
}

