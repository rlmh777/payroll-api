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
        Schema::create('employee_reporting', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supervisor_id')->constrained('employee')->cascadeOnDelete();
            $table->foreignUuid('subordinate_id')->constrained('employee')->cascadeOnDelete();
            $table->string('reporting_method', 32);
            $table->date('effective_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['supervisor_id', 'subordinate_id', 'reporting_method'], 'employee_reporting_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_reporting');
    }
};
