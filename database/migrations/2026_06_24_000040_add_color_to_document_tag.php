<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('document_tag')) {
            return;
        }

        if (!Schema::hasColumn('document_tag', 'color')) {
            Schema::table('document_tag', function (Blueprint $table) {
                $table->string('color', 7)->default('#1976D2')->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_tag') && Schema::hasColumn('document_tag', 'color')) {
            Schema::table('document_tag', function (Blueprint $table) {
                $table->dropColumn('color');
            });
        }
    }
};
