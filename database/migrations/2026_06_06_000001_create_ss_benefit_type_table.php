<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ss_benefit_type', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->timestamps();
        });

        Schema::table('employee_ss_benefit_status', function (Blueprint $table) {
            $table->foreignId('ss_benefit_type_id')
                ->nullable()
                ->after('is_receiving_benefit')
                ->constrained('ss_benefit_type')
                ->nullOnDelete();
        });

        if (Schema::hasColumn('employee_ss_benefit_status', 'benefit_type')) {
            $types = DB::table('employee_ss_benefit_status')
                ->whereNotNull('benefit_type')
                ->distinct()
                ->pluck('benefit_type');

            foreach ($types as $typeName) {
                $normalized = trim((string) $typeName);
                if ($normalized === '') {
                    continue;
                }

                $typeId = DB::table('ss_benefit_type')->where('name', $normalized)->value('id');
                if (!$typeId) {
                    $typeId = DB::table('ss_benefit_type')->insertGetId([
                        'name' => $normalized,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('employee_ss_benefit_status')
                    ->where('benefit_type', $typeName)
                    ->update(['ss_benefit_type_id' => $typeId]);
            }

            Schema::table('employee_ss_benefit_status', function (Blueprint $table) {
                $table->dropColumn('benefit_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employee_ss_benefit_status', function (Blueprint $table) {
            $table->string('benefit_type', 64)->nullable()->after('is_receiving_benefit');
        });

        DB::table('employee_ss_benefit_status')
            ->join('ss_benefit_type', 'ss_benefit_type.id', '=', 'employee_ss_benefit_status.ss_benefit_type_id')
            ->update(['employee_ss_benefit_status.benefit_type' => DB::raw('ss_benefit_type.name')]);

        Schema::table('employee_ss_benefit_status', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ss_benefit_type_id');
        });

        Schema::dropIfExists('ss_benefit_type');
    }
};
