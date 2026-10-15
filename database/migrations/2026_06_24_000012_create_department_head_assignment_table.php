<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_head_assignment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('departmentId')->constrained('department')->cascadeOnDelete();
            $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
            $table->date('startDate');
            $table->date('endDate')->nullable();
            $table->boolean('isCurrent')->default(true);
            $table->foreignUuid('appointedById')->nullable()->constrained('employee')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['departmentId', 'startDate']);
            $table->index(['employeeId', 'startDate']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX department_head_one_current_idx ON department_head_assignment ("departmentId") WHERE "endDate" IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS department_head_one_current_idx');
        Schema::dropIfExists('department_head_assignment');
    }
};
