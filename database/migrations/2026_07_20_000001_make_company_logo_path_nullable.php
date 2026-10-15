<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('logoPath', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('company')->whereNull('logoPath')->update(['logoPath' => '']);

        Schema::table('company', function (Blueprint $table) {
            $table->string('logoPath', 255)->nullable(false)->change();
        });
    }
};
