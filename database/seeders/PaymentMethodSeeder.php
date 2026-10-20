<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = [
            ['name' => 'Bank Transfer', 'requiresBankAccount' => true],
            ['name' => 'Cash', 'requiresBankAccount' => false],
            ['name' => 'Cheque', 'requiresBankAccount' => false],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['name' => $method['name']],
                ['requiresBankAccount' => $method['requiresBankAccount']],
            );
        }
    }
}
