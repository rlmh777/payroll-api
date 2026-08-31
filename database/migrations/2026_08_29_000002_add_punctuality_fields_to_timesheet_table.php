<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $table->string('clockInPunctuality', 16)->nullable()->after('clockOutDeviceId');
            $table->string('clockOutPunctuality', 16)->nullable()->after('clockInPunctuality');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $table->dropColumn([
                'clockInPunctuality',
                'clockOutPunctuality',
            ]);
        });
    }
};
