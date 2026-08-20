<?php

namespace App\Modules\Payroll\Services;

use App\Models\TaxCalculatorAccount;

class TaxCalculatorAccountSyncService
{
    /**
     * Create missing calculator accounts from imported lines and assign parent hierarchy.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{created: int, updated: int}
     */
    public function syncFromImportLines(array $lines): array
    {
        $created = 0;
        $updated = 0;
        $accountsByIndex = [];

        foreach ($lines as $index => $line) {
            if ($this->shouldSkipLine($line)) {
                continue;
            }

            $account = $this->findExisting($line);
            $attributes = $this->defaultAttributes($line);

            if ($account === null) {
                $account = TaxCalculatorAccount::create($attributes);
                $created++;
            } else {
                $changed = $this->applyStructuralUpdates($account, $attributes);
                if ($changed) {
                    $updated++;
                }
            }

            $accountsByIndex[$index] = $account;
        }

        $this->assignParents($lines, $accountsByIndex, $updated);
        $repaired = $this->repairInvalidParentLinks();

        return ['created' => $created, 'updated' => $updated + array_sum($repaired)];
    }

    /**
     * @return array{section_roots: int, self_references: int}
     */
    public function repairInvalidParentLinks(): array
    {
        return [
            'section_roots' => $this->repairSectionRootParents(),
            'self_references' => $this->repairSelfReferencingParents(),
        ];
    }

    /**
     * Clear invalid parent links on section roots (e.g. 4500 · Tour Income).
     */
    public function repairSectionRootParents(): int
    {
        $repaired = 0;

        TaxCalculatorAccount::query()
            ->whereNotNull('parent_id')
            ->orderBy('id')
            ->each(function (TaxCalculatorAccount $account) use (&$repaired) {
                if (! $this->isSectionRootAccount($account)) {
                    return;
                }

                $account->parent_id = null;
                $account->saveQuietly();
                $repaired++;
            });

        return $repaired;
    }

    public function repairSelfReferencingParents(): int
    {
        return TaxCalculatorAccount::query()
            ->whereColumn('parent_id', 'id')
            ->update(['parent_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function shouldSkipLine(array $line): bool
    {
        if (($line['row_type'] ?? '') === 'grand_total') {
            return true;
        }

        $code = trim((string) ($line['account_code'] ?? ''));

        return $code === '';
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function defaultAttributes(array $line): array
    {
        $rowType = (string) ($line['row_type'] ?? 'account');
        $includeInTax = (bool) ($line['include_in_tax'] ?? true);
        $lineKind = $this->lineKindFromLine($line);
        $isRollup = $lineKind === 'net_total' || in_array($rowType, ['total', 'grand_total'], true) || (bool) ($line['is_rollup'] ?? false);

        $taxBasis = TaxCalculatorAccount::TAX_BASIS_NONE;
        if ($rowType === 'account' && $includeInTax) {
            $taxBasis = TaxCalculatorAccount::TAX_BASIS_GROSS;
        } elseif (($rowType === 'total' || $lineKind === 'net_total') && $includeInTax) {
            $taxBasis = TaxCalculatorAccount::TAX_BASIS_NET;
        }

        return [
            'qb_code' => strtoupper(preg_replace('/\s+/', '', (string) $line['account_code']) ?? ''),
            'qb_name' => trim((string) ($line['account_name'] ?? '')),
            'is_rollup' => $isRollup,
            'line_kind' => $lineKind,
            'tax_basis' => $taxBasis,
            'include_btb' => false,
            'sort_order' => (int) ($line['sort_order'] ?? 0),
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineKindFromLine(array $line): string
    {
        $rowType = (string) ($line['row_type'] ?? 'account');
        $isRollup = in_array($rowType, ['total', 'grand_total'], true) || (bool) ($line['is_rollup'] ?? false);
        $kind = TaxCalculatorAccount::lineKindFromAttributes(
            $isRollup,
            strtoupper(preg_replace('/\s+/', '', (string) ($line['account_code'] ?? '')) ?? ''),
            trim((string) ($line['account_name'] ?? '')),
        );

        if ($kind === 'qb_account' && $rowType === 'heading') {
            return 'section';
        }

        return $kind;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function findExisting(array $line): ?TaxCalculatorAccount
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) ($line['account_code'] ?? '')) ?? '');
        $name = trim((string) ($line['account_name'] ?? ''));
        $lineKind = $this->lineKindFromLine($line);

        return TaxCalculatorAccount::query()
            ->where('qb_code', $code)
            ->get()
            ->first(fn (TaxCalculatorAccount $account) => $this->namesMatch((string) $account->qb_name, $name, $account->line_kind, $lineKind));
    }

    private function namesMatch(string $left, string $right, ?string $leftKind = null, ?string $rightKind = null): bool
    {
        $normalize = static fn (string $value) => strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));

        $leftNorm = $normalize($left);
        $rightNorm = $normalize($right);

        $leftIsTotal = str_starts_with($leftNorm, 'total ');
        $rightIsTotal = str_starts_with($rightNorm, 'total ');
        if ($leftIsTotal !== $rightIsTotal) {
            return false;
        }

        if ($leftIsTotal && $rightIsTotal) {
            return $leftNorm === $rightNorm
                || str_contains($leftNorm, $rightNorm)
                || str_contains($rightNorm, $leftNorm);
        }

        if ($leftKind !== null && $rightKind !== null && $leftKind !== $rightKind) {
            return false;
        }

        return $leftNorm === $rightNorm
            || str_contains($leftNorm, $rightNorm)
            || str_contains($rightNorm, $leftNorm);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function applyStructuralUpdates(TaxCalculatorAccount $account, array $defaults): bool
    {
        $changed = false;

        foreach (['qb_name', 'sort_order'] as $field) {
            if ($account->{$field} != $defaults[$field]) {
                $account->{$field} = $defaults[$field];
                $changed = true;
            }
        }

        if (! $account->hasTaxRates() && ($account->tax_basis ?? null) !== $defaults['tax_basis']) {
            $account->tax_basis = $defaults['tax_basis'];
            $changed = true;
        }

        if ($changed) {
            $account->saveQuietly();
        }

        return $changed;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, TaxCalculatorAccount>  $accountsByIndex
     */
    private function assignParents(array $lines, array $accountsByIndex, int &$updated): void
    {
        $sectionParentId = null;
        $subSectionParentId = null;
        /** @var list<int> $pendingDetailIndexes */
        $pendingDetailIndexes = [];

        foreach ($lines as $index => $line) {
            if (! isset($accountsByIndex[$index])) {
                continue;
            }

            $account = $accountsByIndex[$index];
            $rowType = (string) ($line['row_type'] ?? 'account');

            if ($this->isMajorSectionHeading($line)) {
                $this->setParentId($account, null, $updated);
                $sectionParentId = $account->id;
                $subSectionParentId = null;
                $pendingDetailIndexes = [];
                continue;
            }

            if ($rowType === 'heading') {
                $this->setParentId($account, $sectionParentId, $updated);
                $subSectionParentId = $account->id;
                $pendingDetailIndexes = [];
                continue;
            }

            if ($rowType === 'total') {
                $this->setParentId($account, $subSectionParentId ?? $sectionParentId, $updated);
                foreach ($pendingDetailIndexes as $detailIndex) {
                    if (! isset($accountsByIndex[$detailIndex])) {
                        continue;
                    }

                    $detailLine = $lines[$detailIndex];
                    $detailAccount = $accountsByIndex[$detailIndex];
                    if ($this->isMajorSectionHeading($detailLine) || $this->isSectionRootAccount($detailAccount)) {
                        continue;
                    }

                    $this->setParentId($detailAccount, $account->id, $updated);
                }
                $pendingDetailIndexes = [];
                continue;
            }

            if ($rowType === 'account') {
                if (! $this->isMajorSectionHeading($line)) {
                    $pendingDetailIndexes[] = $index;
                }
                $this->setParentId($account, $subSectionParentId ?? $sectionParentId, $updated);
            }
        }
    }

    private function isSectionRootAccount(TaxCalculatorAccount $account): bool
    {
        $code = strtoupper(trim($account->qb_code));
        $name = strtolower(trim($account->qb_name));

        if (strlen($code) !== 4 || ! str_ends_with($code, '00')) {
            return false;
        }

        if ($name === '' || str_starts_with($name, 'total ')) {
            return false;
        }

        if (str_contains($name, '(gross)') || str_contains($name, '(net)')) {
            return false;
        }

        if (str_contains($name, ' - ')) {
            return false;
        }

        return str_starts_with($name, strtolower($code).' ·');
    }

    private function setParentId(TaxCalculatorAccount $account, ?string $parentId, int &$updated): void
    {
        if ($parentId === $account->id) {
            $parentId = null;
        }

        if ($account->parent_id === $parentId) {
            return;
        }

        $account->parent_id = $parentId;
        $account->saveQuietly();
        $updated++;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isMajorSectionHeading(array $line): bool
    {
        if (($line['row_type'] ?? '') !== 'heading') {
            return false;
        }

        if (($line['label_column'] ?? 'B') !== 'A') {
            return false;
        }

        $label = trim((string) ($line['account_name'] ?? ''));
        if ($label === '' || str_starts_with(strtolower($label), 'total ')) {
            return false;
        }

        if (str_ends_with($label, ':')) {
            return true;
        }

        if (str_contains(strtolower($label), 'guava limb')) {
            return true;
        }

        $code = strtoupper(preg_replace('/\s+/', '', (string) ($line['account_code'] ?? '')) ?? '');

        return strlen($code) === 4 && str_ends_with($code, '00');
    }
}
