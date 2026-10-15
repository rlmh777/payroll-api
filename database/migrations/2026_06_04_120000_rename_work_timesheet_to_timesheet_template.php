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
        if (!Schema::hasTable('work_timesheet')) {
            return;
        }

        Schema::rename('work_timesheet', 'timesheet_template');

        Schema::table('work_timesheet_department', function (Blueprint $table) {
            $table->dropForeign(['work_timesheet_id']);
        });

        Schema::rename('work_timesheet_department', 'timesheet_template_department');

        Schema::table('timesheet_template_department', function (Blueprint $table) {
            $table->renameColumn('work_timesheet_id', 'timesheet_template_id');
        });

        Schema::table('timesheet_template_department', function (Blueprint $table) {
            $table->foreign('timesheet_template_id')
                ->references('id')
                ->on('timesheet_template')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('timesheet_template')) {
            return;
        }

        Schema::table('timesheet_template_department', function (Blueprint $table) {
            $table->dropForeign(['timesheet_template_id']);
        });

        Schema::table('timesheet_template_department', function (Blueprint $table) {
            $table->renameColumn('timesheet_template_id', 'work_timesheet_id');
        });

        Schema::rename('timesheet_template_department', 'work_timesheet_department');
        Schema::rename('timesheet_template', 'work_timesheet');

        Schema::table('work_timesheet_department', function (Blueprint $table) {
            $table->foreign('work_timesheet_id')
                ->references('id')
                ->on('work_timesheet')
                ->cascadeOnDelete();
        });
    }
};
