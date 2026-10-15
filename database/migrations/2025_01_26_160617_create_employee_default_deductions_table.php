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
            $table->foreignUuid('bankId')->constrained('bank')->onDelete('cascade');
            $table->string('accountNumber');
            $table->decimal('amount', total: 12, places: 2);
            $table->text('note')->nullable();
            $table->foreignId('frequencyId')->constrained('payrate_frequency')->onDelete('cascade');
            $table->foreignUuid('accountId')->constrained('accounts')->onDelete('cascade');
            $table->foreignId('deductionTypeId')->constrained('deduction_type')->onDelete('cascade');
            $table->boolean('allowPartialDeduction')->default(false);
            $table->string('applicationRule', 255)->nullable();
            $table->integer('priority')->default(0);
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
