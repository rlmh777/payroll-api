<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company')) {
            return;
        }

        Schema::table('company', function (Blueprint $table) {
            if (! Schema::hasColumn('company', 'bankBranchNumber')) {
                $table->string('bankBranchNumber', 32)
                    ->nullable()
                    ->after('socialSecurityElectronicEmployerNumber');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company')) {
            return;
        }

        Schema::table('company', function (Blueprint $table) {
            if (Schema::hasColumn('company', 'bankBranchNumber')) {
                $table->dropColumn('bankBranchNumber');
            }
        });
    }
};
