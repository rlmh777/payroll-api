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
        Schema::create('employee_hours_worked', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('employeeCode1',24);
            $table->string('employeeCode2',24);
            $table->date('from');
            $table->date('to');
            $table->decimal('regularWorkingHours', total: 6, places: 2);
            $table->decimal('overtimeHours', total: 5, places: 2);
            $table->decimal('doubletimeHours', total: 5, places: 2);
            $table->string('note',1024);
            $table->string('location',128);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_hours_worked');
    }
};
