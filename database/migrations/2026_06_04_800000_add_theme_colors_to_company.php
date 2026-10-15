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
        if (!Schema::hasTable('company')) {
            return;
        }

        Schema::table('company', function (Blueprint $table) {
            if (!Schema::hasColumn('company', 'primaryColor')) {
                $table->string('primaryColor', 7)->default('#1976D2')->after('logo');
            }

            if (!Schema::hasColumn('company', 'secondaryColor')) {
                $table->string('secondaryColor', 7)->default('#26A69A')->after('primaryColor');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('company')) {
            return;
        }

        Schema::table('company', function (Blueprint $table) {
            if (Schema::hasColumn('company', 'secondaryColor')) {
                $table->dropColumn('secondaryColor');
            }

            if (Schema::hasColumn('company', 'primaryColor')) {
                $table->dropColumn('primaryColor');
            }
        });
    }
};
