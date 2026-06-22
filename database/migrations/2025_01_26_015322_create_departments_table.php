<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('department', function (Blueprint $table) {
            $table->id('id');
            $table->string('name', 256);
            $table->foreignId('parentId')->nullable()->constrained('department')->onDelete('cascade');
            $table->decimal('totalDailyHoursBeforeOvertime', 5, 2)->default(9);
            $table->decimal('totalWeeklyHoursBeforeOvertime', 6, 2)->default(45);
            $table->boolean('includeLunchHour')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department');
    }
};
