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
        Schema::create('employee_default_deduction', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignUuid('paymentToId')->constrained('vendor')->onDelete('cascade');
            $table->decimal('amount', total: 12, places: 2);
            $table->text('note');
            $table->foreignId('frequencyId')->constrained('payrate_frequency')->onDelete('cascade');
            $table->foreignUuid('chartOfAccountId')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->foreignId('deductionTypeId')->constrained('deduction_type')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_default_deduction');
    }
};
