<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_group', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 32)->nullable();
            $table->boolean('isActive')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_group_member', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employeeGroupId');
            $table->uuid('employeeId');
            $table->date('startDate')->nullable();
            $table->date('endDate')->nullable();
            $table->timestamps();

            $table->foreign('employeeGroupId')
                ->references('id')
                ->on('employee_group')
                ->cascadeOnDelete();
            $table->foreign('employeeId')
                ->references('id')
                ->on('employee')
                ->cascadeOnDelete();

            $table->unique(['employeeGroupId', 'employeeId'], 'employee_group_member_unique');
            $table->index(['employeeId', 'employeeGroupId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_group_member');
        Schema::dropIfExists('employee_group');
    }
};
