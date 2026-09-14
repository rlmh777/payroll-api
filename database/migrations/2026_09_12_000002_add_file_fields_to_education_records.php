<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addFileColumns('qualification', 'note');
        $this->addFileColumns('employee_certification', 'notes');
        $this->addFileColumns('employee_skill', 'notes');
    }

    public function down(): void
    {
        $this->dropFileColumns('qualification');
        $this->dropFileColumns('employee_certification');
        $this->dropFileColumns('employee_skill');
    }

    private function addFileColumns(string $table, string $afterColumn): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $afterColumn) {
            if (! Schema::hasColumn($table, 'filePath')) {
                $blueprint->string('filePath', 1024)->nullable()->after($afterColumn);
            }
            if (! Schema::hasColumn($table, 'fileName')) {
                $blueprint->string('fileName', 255)->nullable()->after('filePath');
            }
            if (! Schema::hasColumn($table, 'mimeType')) {
                $blueprint->string('mimeType', 128)->nullable()->after('fileName');
            }
            if (! Schema::hasColumn($table, 'fileSize')) {
                $blueprint->unsignedBigInteger('fileSize')->nullable()->after('mimeType');
            }
        });
    }

    private function dropFileColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            foreach (['filePath', 'fileName', 'mimeType', 'fileSize'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
