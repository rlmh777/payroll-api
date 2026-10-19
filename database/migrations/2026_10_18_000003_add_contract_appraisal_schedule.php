<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employment_detail')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                if (! Schema::hasColumn('employment_detail', 'appraisalDate')) {
                    $table->date('appraisalDate')->nullable();
                }
                if (! Schema::hasColumn('employment_detail', 'appraisalFormTemplateId')) {
                    $table->uuid('appraisalFormTemplateId')->nullable();
                    $table->foreign('appraisalFormTemplateId')
                        ->references('id')
                        ->on('form_templates')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('employee_appraisals')) {
            Schema::table('employee_appraisals', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_appraisals', 'employment_detail_id')) {
                    $table->uuid('employment_detail_id')->nullable();
                    $table->foreign('employment_detail_id')
                        ->references('id')
                        ->on('employment_detail')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('employee_appraisals', 'scheduled_for')) {
                    $table->date('scheduled_for')->nullable();
                }
                if (! Schema::hasColumn('employee_appraisals', 'next_appraisal_date')) {
                    $table->date('next_appraisal_date')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_appraisals')) {
            Schema::table('employee_appraisals', function (Blueprint $table) {
                if (Schema::hasColumn('employee_appraisals', 'employment_detail_id')) {
                    $table->dropConstrainedForeignId('employment_detail_id');
                }
                if (Schema::hasColumn('employee_appraisals', 'scheduled_for')) {
                    $table->dropColumn('scheduled_for');
                }
                if (Schema::hasColumn('employee_appraisals', 'next_appraisal_date')) {
                    $table->dropColumn('next_appraisal_date');
                }
            });
        }

        if (Schema::hasTable('employment_detail')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                if (Schema::hasColumn('employment_detail', 'appraisalFormTemplateId')) {
                    $table->dropConstrainedForeignId('appraisalFormTemplateId');
                }
                if (Schema::hasColumn('employment_detail', 'appraisalDate')) {
                    $table->dropColumn('appraisalDate');
                }
            });
        }
    }
};
