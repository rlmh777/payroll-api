<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('public_holiday')) {
            return;
        }

        DB::table('public_holiday')
            ->where('payMultiplier', 1)
            ->update(['payMultiplier' => 1.5]);

        DB::table('public_holiday')
            ->where(function ($query) {
                $query->where('name', 'ilike', '%new year%')
                    ->orWhere('name', 'ilike', '%good friday%')
                    ->orWhere('name', 'ilike', '%independence%');
            })
            ->update(['payMultiplier' => 2.0]);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_holiday ALTER COLUMN "payMultiplier" SET DEFAULT 1.5');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('public_holiday')) {
            return;
        }

        DB::table('public_holiday')
            ->where('payMultiplier', 1.5)
            ->update(['payMultiplier' => 1]);

        DB::table('public_holiday')
            ->where(function ($query) {
                $query->where('name', 'ilike', '%new year%')
                    ->orWhere('name', 'ilike', '%good friday%')
                    ->orWhere('name', 'ilike', '%independence%');
            })
            ->update(['payMultiplier' => 1]);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_holiday ALTER COLUMN "payMultiplier" SET DEFAULT 1');
        }
    }
};
