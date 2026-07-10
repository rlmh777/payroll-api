<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_document')) {
            return;
        }

        Schema::table('employee_document', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_document', 'filePath')) {
                $table->string('filePath', 1024)->nullable()->after('description');
            }
            if (!Schema::hasColumn('employee_document', 'fileName')) {
                $table->string('fileName', 255)->nullable()->after('filePath');
            }
            if (!Schema::hasColumn('employee_document', 'mimeType')) {
                $table->string('mimeType', 128)->nullable()->after('fileName');
            }
            if (!Schema::hasColumn('employee_document', 'fileSize')) {
                $table->unsignedBigInteger('fileSize')->nullable()->after('mimeType');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('employee_document')) {
            return;
        }

        Schema::table('employee_document', function (Blueprint $table) {
            foreach (['filePath', 'fileName', 'mimeType', 'fileSize'] as $column) {
                if (Schema::hasColumn('employee_document', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
