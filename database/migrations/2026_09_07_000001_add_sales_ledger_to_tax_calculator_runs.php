<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_calculator_runs')) {
            return;
        }

        Schema::table('tax_calculator_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_calculator_runs', 'import_sales_ledger_sheet')) {
                $table->json('import_sales_ledger_sheet')->nullable()->after('import_purchase_ledger_at');
            }
            if (! Schema::hasColumn('tax_calculator_runs', 'import_sales_ledger_filename')) {
                $table->string('import_sales_ledger_filename', 255)->nullable()->after('import_sales_ledger_sheet');
            }
            if (! Schema::hasColumn('tax_calculator_runs', 'import_sales_ledger_at')) {
                $table->timestamp('import_sales_ledger_at')->nullable()->after('import_sales_ledger_filename');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_calculator_runs')) {
            return;
        }

        Schema::table('tax_calculator_runs', function (Blueprint $table) {
            foreach ([
                'import_sales_ledger_sheet',
                'import_sales_ledger_filename',
                'import_sales_ledger_at',
            ] as $column) {
                if (Schema::hasColumn('tax_calculator_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
