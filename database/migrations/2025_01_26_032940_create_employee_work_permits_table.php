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
        Schema::create('employee_work_permit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('employeeId')->nullable()->constrained('employee')->onDelete('cascade');
            $table->string('workPermitNumber', 64);
            $table->date('issued');
            $table->date('expires');
            $table->string('socialSecurityNumber', 12);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_work_permit');
    }
};
