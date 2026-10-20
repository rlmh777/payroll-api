<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method', function (Blueprint $table) {
            $table->boolean('requiresBankAccount')
                ->default(true)
                ->after('name');
        });

        // Existing bank-transfer style methods keep requiring an account.
        DB::table('payment_method')
            ->whereRaw("LOWER(name) LIKE '%bank%' OR LOWER(name) LIKE '%transfer%' OR LOWER(name) LIKE '%ach%' OR LOWER(name) LIKE '%wire%'")
            ->update(['requiresBankAccount' => true]);

        foreach ([
            ['name' => 'Cash', 'requiresBankAccount' => false],
            ['name' => 'Cheque', 'requiresBankAccount' => false],
            ['name' => 'Bank Transfer', 'requiresBankAccount' => true],
        ] as $method) {
            $existing = DB::table('payment_method')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($method['name'])])
                ->first();

            if ($existing) {
                DB::table('payment_method')
                    ->where('id', $existing->id)
                    ->update(['requiresBankAccount' => $method['requiresBankAccount']]);
                continue;
            }

            DB::table('payment_method')->insert([
                'name' => $method['name'],
                'requiresBankAccount' => $method['requiresBankAccount'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Cash / cheque style names should never require a bank account.
        DB::table('payment_method')
            ->whereRaw("LOWER(name) LIKE '%cash%' OR LOWER(name) LIKE '%cheque%' OR LOWER(name) LIKE '%check%'")
            ->update(['requiresBankAccount' => false]);
    }

    public function down(): void
    {
        Schema::table('payment_method', function (Blueprint $table) {
            $table->dropColumn('requiresBankAccount');
        });
    }
};
