<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pay_period_schedule', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date');
            $table->foreignId('payrate_frequency_id')
                ->nullable()
                ->constrained('payrate_frequency')
                ->onDelete('set null');
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pay_period_schedule');
    }
};

