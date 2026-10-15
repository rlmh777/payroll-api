<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vacancy_applications')) {
            return;
        }

        Schema::table('vacancy_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('vacancy_applications', 'cover_letter_path')) {
                $table->string('cover_letter_path')->nullable()->after('cover_letter');
            }
            if (! Schema::hasColumn('vacancy_applications', 'cover_letter_name')) {
                $table->string('cover_letter_name')->nullable()->after('cover_letter_path');
            }
            if (! Schema::hasColumn('vacancy_applications', 'social_security_path')) {
                $table->string('social_security_path')->nullable()->after('resume_name');
            }
            if (! Schema::hasColumn('vacancy_applications', 'social_security_name')) {
                $table->string('social_security_name')->nullable()->after('social_security_path');
            }
            if (! Schema::hasColumn('vacancy_applications', 'passport_path')) {
                $table->string('passport_path')->nullable()->after('social_security_name');
            }
            if (! Schema::hasColumn('vacancy_applications', 'passport_name')) {
                $table->string('passport_name')->nullable()->after('passport_path');
            }
            if (! Schema::hasColumn('vacancy_applications', 'police_record_path')) {
                $table->string('police_record_path')->nullable()->after('passport_name');
            }
            if (! Schema::hasColumn('vacancy_applications', 'police_record_name')) {
                $table->string('police_record_name')->nullable()->after('police_record_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vacancy_applications')) {
            return;
        }

        Schema::table('vacancy_applications', function (Blueprint $table) {
            foreach ([
                'cover_letter_path',
                'cover_letter_name',
                'social_security_path',
                'social_security_name',
                'passport_path',
                'passport_name',
                'police_record_path',
                'police_record_name',
            ] as $column) {
                if (Schema::hasColumn('vacancy_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
