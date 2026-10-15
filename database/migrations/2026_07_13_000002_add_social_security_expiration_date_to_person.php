<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('person')) {
            return;
        }

        Schema::table('person', function (Blueprint $table) {
            if (!Schema::hasColumn('person', 'socialSecurityExpirationDate')) {
                $table->date('socialSecurityExpirationDate')
                    ->nullable()
                    ->after('socialSecurityNumber');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('person')) {
            return;
        }

        Schema::table('person', function (Blueprint $table) {
            if (Schema::hasColumn('person', 'socialSecurityExpirationDate')) {
                $table->dropColumn('socialSecurityExpirationDate');
            }
        });
    }
};
