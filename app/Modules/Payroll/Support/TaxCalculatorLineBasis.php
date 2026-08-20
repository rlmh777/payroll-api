<?php

namespace App\Modules\Payroll\Support;

use App\Models\TaxCalculatorAccount;

class TaxCalculatorLineBasis
{
    /**
     * @param  array<string, mixed>  $line
     */
    public static function qualifies(array $line, string $basis): bool
    {
        if ($basis === TaxCalculatorAccount::TAX_BASIS_GROSS) {
            return self::isGrossLine($line);
        }

        if ($basis === TaxCalculatorAccount::TAX_BASIS_NET) {
            return self::isNetLine($line);
        }

        return false;
    }

    public static function isSpreadsheetTotalLine(array $line): bool
    {
        $name = strtolower(trim((string) ($line['account_name'] ?? '')));

        return ($line['row_type'] ?? '') === 'total'
            || (bool) ($line['is_rollup'] ?? false)
            || str_starts_with($name, 'total ')
            || str_contains($name, '(net)');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function isGrossLine(array $line): bool
    {
        $rowType = (string) ($line['row_type'] ?? 'account');
        $name = strtolower((string) ($line['account_name'] ?? ''));

        if ($rowType === 'account') {
            return true;
        }

        return str_contains($name, '(gross)');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function isNetLine(array $line): bool
    {
        $rowType = (string) ($line['row_type'] ?? 'account');
        $name = strtolower((string) ($line['account_name'] ?? ''));

        if ($rowType === 'total' || self::isSpreadsheetTotalLine($line)) {
            return true;
        }

        return str_contains($name, '(net)');
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array{code: string, tax_basis: string, category: string}>
     */
    public static function assignments(array $line): array
    {
        if (! empty($line['tax_rate_assignments']) && is_array($line['tax_rate_assignments'])) {
            return array_values($line['tax_rate_assignments']);
        }

        $basis = (string) ($line['tax_basis'] ?? TaxCalculatorAccount::TAX_BASIS_GROSS);
        $assignments = [];
        foreach ($line['business_tax_codes'] ?? [] as $code) {
            if ($code) {
                $assignments[] = ['code' => (string) $code, 'tax_basis' => $basis, 'category' => 'business_tax'];
            }
        }
        if (! empty($line['business_tax_code'])) {
            $assignments[] = ['code' => (string) $line['business_tax_code'], 'tax_basis' => $basis, 'category' => 'business_tax'];
        }
        foreach ($line['gst_codes'] ?? [] as $code) {
            if ($code) {
                $assignments[] = ['code' => (string) $code, 'tax_basis' => $basis, 'category' => 'gst'];
            }
        }
        if (! empty($line['gst_code'])) {
            $assignments[] = ['code' => (string) $line['gst_code'], 'tax_basis' => $basis, 'category' => 'gst'];
        }

        return $assignments;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function qualifiesForBtb(array $line, TaxCalculatorAccount $mapping): bool
    {
        if (! $mapping->include_btb) {
            return false;
        }

        $basis = $mapping->resolvesTaxBasis();
        if ($basis === TaxCalculatorAccount::TAX_BASIS_NONE) {
            $basis = $mapping->implicitTaxBasis();
        }

        if ($basis === TaxCalculatorAccount::TAX_BASIS_NONE) {
            return false;
        }

        return self::qualifies($line, $basis);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function hasTaxAssignments(array $line): bool
    {
        return self::assignments($line) !== [] || ! empty($line['include_btb']);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function hasNetBusinessAssignment(array $line): bool
    {
        foreach (self::assignments($line) as $assignment) {
            if (($assignment['category'] ?? '') === 'business_tax'
                && ($assignment['tax_basis'] ?? '') === TaxCalculatorAccount::TAX_BASIS_NET) {
                return true;
            }
        }

        return false;
    }
}
