<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_compensation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
            $table->date('effectiveDate');
            $table->date('endDate')->nullable();
            $table->boolean('isActive')->default(true);
            $table->string('compensationMethod', 32);
            $table->decimal('hourlyRate', 12, 2)->default(0);
            $table->decimal('yearlyRate', 12, 2)->default(0);
            $table->string('payscalePoint', 8)->nullable();
            $table->string('reasonType', 32)->default('INITIAL');
            $table->text('reasonNote')->nullable();
            $table->foreignUuid('approvedById')->nullable()->constrained('employee')->nullOnDelete();
            $table->timestamps();
        });

        if (!Schema::hasTable('employment_detail')) {
            return;
        }

        $rows = DB::table('employment_detail')->orderBy('startDate')->get();

        foreach ($rows as $row) {
            $method = strtoupper((string) ($row->compensationMethod ?? 'HOURLY'));
            if (!in_array($method, ['HOURLY', 'BASE_SALARY'], true)) {
                $method = ((float) ($row->yearlyRate ?? 0)) > 0 ? 'BASE_SALARY' : 'HOURLY';
            }

            DB::table('employee_compensation')->insert([
                'id' => (string) Str::uuid(),
                'employeeId' => $row->employeeId,
                'effectiveDate' => $row->startDate,
                'endDate' => $row->endDate,
                'isActive' => (bool) $row->isActive,
                'compensationMethod' => $method,
                'hourlyRate' => (float) ($row->hourlyRate ?? 0),
                'yearlyRate' => (float) ($row->yearlyRate ?? 0),
                'payscalePoint' => $row->payscalePoint,
                'reasonType' => 'INITIAL',
                'reasonNote' => 'Migrated from employment contract.',
                'approvedById' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensation');
    }
};
