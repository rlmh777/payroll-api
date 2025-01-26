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
        Schema::create('employment_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('employeeId')->nullable()->constrained('employee')->onDelete('cascade');
            $table->string('employerName', 512);
            $table->string('positionHeld', 512);
            $table->date('from');
            $table->date('to');
            $table->text('note');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_history');
    }
};
