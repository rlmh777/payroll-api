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
        Schema::create('timesheet_template_department', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('timesheet_template_id')->constrained('timesheet_template')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('department')->cascadeOnDelete();
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timesheet_template_department');
    }
};
