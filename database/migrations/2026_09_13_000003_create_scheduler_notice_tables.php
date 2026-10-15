<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduler_notice')) {
            Schema::create('scheduler_notice', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('title');
                $table->text('description')->nullable();
                $table->date('start_date');
                $table->date('end_date');
                $table->string('audience_type', 32); // company | departments | employees
                $table->string('color', 32)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['start_date', 'end_date']);
                $table->index('audience_type');
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('scheduler_notice_department')) {
            Schema::create('scheduler_notice_department', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('scheduler_notice_id')
                    ->constrained('scheduler_notice')
                    ->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('department')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['scheduler_notice_id', 'department_id'],
                    'scheduler_notice_department_uq'
                );
            });
        }

        if (! Schema::hasTable('scheduler_notice_employee')) {
            Schema::create('scheduler_notice_employee', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('scheduler_notice_id')
                    ->constrained('scheduler_notice')
                    ->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('employee')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['scheduler_notice_id', 'employee_id'],
                    'scheduler_notice_employee_uq'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_notice_employee');
        Schema::dropIfExists('scheduler_notice_department');
        Schema::dropIfExists('scheduler_notice');
    }
};
