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
        Schema::create('employment_detail', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->date('startDate');
            $table->date('endDate');
            $table->boolean('isActive');
            $table->string('payscalePoint', 8);
            $table->decimal('hourlyRate', total: 12, places: 2);
            $table->decimal('totalRate', total: 12, places: 2);
            $table->foreignId('payrateFrequencyId')->constrained('payrate_frequency')->onDelete('cascade');
            $table->text('benefits');
            $table->foreignUuid('accountId')->constrained('accounts')->onDelete('cascade');
            $table->foreignId('contractTypeId')->constrained('contract_type')->onDelete('cascade');
            $table->text('employmentPolicies');
            $table->string('contractAgreementPath', 1024);
            $table->foreignId('employmentStatusId')->constrained('employment_status')->onDelete('cascade');
            $table->foreignId('employeeStatusId')->constrained('employee_status')->onDelete('cascade');
            $table->foreignId('departmentId')->constrained('department')->onDelete('cascade');
            $table->foreignId('worksiteId')->constrained('worksite')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_detail');
    }
};
