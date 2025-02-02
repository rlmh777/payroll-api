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
        Schema::create('default_employee_allowance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignUuid('allowanceId')->constrained('allowance')->onDelete('cascade');
            $table->foreignId('frequencyId')->constrained('payrate_frequency')->onDelete('cascade');
            $table->foreignUuid('chartOfAccountId')->constrained('chart_of_account')->onDelete('cascade');
            $table->string('note',1024);
            $table->decimal('amount', total: 12, places: 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('default_employee_allowance');
    }
};
