<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_setting', function (Blueprint $table) {
            $table->id();
            $table->decimal('incomeTaxRate', 5, 4)->default(0.25);
            $table->timestamps();
        });

        DB::table('payroll_setting')->insert([
            'incomeTaxRate' => 0.25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_setting');
    }
};
