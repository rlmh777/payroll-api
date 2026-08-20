<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_runs')) {
            Schema::table('tax_calculator_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('tax_calculator_runs', 'import_purchase_ledger_sheet')) {
                    $table->json('import_purchase_ledger_sheet')->nullable()->after('import_gst_sheet');
                }
                if (! Schema::hasColumn('tax_calculator_runs', 'import_purchase_ledger_filename')) {
                    $table->string('import_purchase_ledger_filename', 255)->nullable()->after('import_purchase_ledger_sheet');
                }
                if (! Schema::hasColumn('tax_calculator_runs', 'import_purchase_ledger_at')) {
                    $table->timestamp('import_purchase_ledger_at')->nullable()->after('import_purchase_ledger_filename');
                }
            });
        }

        if (! Schema::hasTable('tax_calculator_purchase_ledger_excluded_names')) {
            Schema::create('tax_calculator_purchase_ledger_excluded_names', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 255)->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_calculator_purchase_ledger_excluded_names');

        if (Schema::hasTable('tax_calculator_runs')) {
            Schema::table('tax_calculator_runs', function (Blueprint $table) {
                foreach ([
                    'import_purchase_ledger_sheet',
                    'import_purchase_ledger_filename',
                    'import_purchase_ledger_at',
                ] as $column) {
                    if (Schema::hasColumn('tax_calculator_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
