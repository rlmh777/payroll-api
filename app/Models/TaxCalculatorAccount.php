<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxCalculatorAccount extends Model
{
    use HasUuids;

    protected $table = 'tax_calculator_accounts';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const TAX_BASIS_NONE = 'none';

    public const TAX_BASIS_GROSS = 'gross';

    public const TAX_BASIS_NET = 'net';

    protected $fillable = [
        'account_id',
        'parent_id',
        'qb_code',
        'qb_name',
        'is_rollup',
        'line_kind',
        'tax_basis',
        'business_tax_code',
        'gst_code',
        'include_btb',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_rollup' => 'boolean',
        'include_btb' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('qb_code');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxCalculatorAccountRate::class, 'tax_calculator_account_id');
    }

    /**
     * @return list<string>
     */
    public function businessTaxCodes(): array
    {
        $codes = $this->relationLoaded('rates')
            ? $this->rates->pluck('rate_code')->all()
            : $this->rates()->pluck('rate_code')->all();

        $businessCodes = TaxCalculatorRate::query()
            ->where('category', 'business_tax')
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();

        if ($businessCodes !== []) {
            return array_values($businessCodes);
        }

        return $this->business_tax_code ? [$this->business_tax_code] : [];
    }

    /**
     * @return list<string>
     */
    public function gstTaxCodes(): array
    {
        $codes = $this->relationLoaded('rates')
            ? $this->rates->pluck('rate_code')->all()
            : $this->rates()->pluck('rate_code')->all();

        $gstCodes = TaxCalculatorRate::query()
            ->where('category', 'gst')
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();

        if ($gstCodes !== []) {
            return array_values($gstCodes);
        }

        return $this->gst_code ? [$this->gst_code] : [];
    }

    /**
     * @return list<array{code: string, tax_basis: string, category: string}>
     */
    public function rateAssignments(): array
    {
        $rateRows = $this->relationLoaded('rates')
            ? $this->rates
            : $this->rates()->get();

        if ($rateRows->isEmpty()) {
            return $this->legacyRateAssignments();
        }

        $categories = TaxCalculatorRate::query()
            ->whereIn('code', $rateRows->pluck('rate_code')->all())
            ->pluck('category', 'code');

        $assignments = [];
        foreach ($rateRows as $rateRow) {
            $code = (string) $rateRow->rate_code;
            $assignments[] = [
                'code' => $code,
                'tax_basis' => $this->implicitTaxBasis(),
                'category' => (string) ($categories[$code] ?? ''),
            ];
        }

        return $assignments;
    }

    /**
     * @return list<array{code: string, tax_basis: string, category: string}>
     */
    private function legacyRateAssignments(): array
    {
        $basis = $this->implicitTaxBasis();
        if ($basis === self::TAX_BASIS_NONE && ! $this->hasTaxRates()) {
            return [];
        }
        if ($basis === self::TAX_BASIS_NONE) {
            $basis = self::TAX_BASIS_GROSS;
        }

        $assignments = [];
        foreach ($this->businessTaxCodes() as $code) {
            $assignments[] = ['code' => $code, 'tax_basis' => $basis, 'category' => 'business_tax'];
        }
        foreach ($this->gstTaxCodes() as $code) {
            $assignments[] = ['code' => $code, 'tax_basis' => $basis, 'category' => 'gst'];
        }

        return $assignments;
    }

    /**
     * @param  list<array{code: string, tax_basis?: string|null}>  $assignments
     */
    public function syncRateAssignments(array $assignments): void
    {
        $normalized = [];
        foreach ($assignments as $assignment) {
            $code = strtoupper(trim((string) ($assignment['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $basis = strtolower(trim((string) ($assignment['tax_basis'] ?? self::TAX_BASIS_GROSS)));
            if (! in_array($basis, [self::TAX_BASIS_GROSS, self::TAX_BASIS_NET], true)) {
                $basis = self::TAX_BASIS_GROSS;
            }
            $normalized[$code] = $basis;
        }

        $codeKeys = array_keys($normalized);
        if ($codeKeys === []) {
            $this->rates()->delete();
        } else {
            $this->rates()->whereNotIn('rate_code', $codeKeys)->delete();
        }

        foreach ($normalized as $code => $basis) {
            $this->rates()->updateOrCreate(
                ['rate_code' => $code],
                ['tax_basis' => $basis],
            );
        }

        $codes = array_keys($normalized);
        $businessCodes = TaxCalculatorRate::query()
            ->where('category', 'business_tax')
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();
        $gstCodes = TaxCalculatorRate::query()
            ->where('category', 'gst')
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();

        $this->business_tax_code = $businessCodes[0] ?? null;
        $this->gst_code = $gstCodes[0] ?? null;
        $this->unsetRelation('rates');
        $this->saveQuietly();
    }

    public function syncRateCodes(array $rateCodes, ?string $defaultBasis = null): void
    {
        $basis = $defaultBasis ?? $this->resolvesTaxBasis();
        if ($basis === self::TAX_BASIS_NONE) {
            $basis = self::TAX_BASIS_GROSS;
        }

        $assignments = array_map(
            fn (string $code) => ['code' => $code, 'tax_basis' => $basis],
            array_values(array_unique(array_filter(array_map(
                fn ($code) => strtoupper(trim((string) $code)),
                $rateCodes,
            )))),
        );

        $this->syncRateAssignments($assignments);
    }

    public function hasTaxRates(): bool
    {
        if ($this->include_btb) {
            return true;
        }

        if ($this->relationLoaded('rates')) {
            return $this->rates->isNotEmpty();
        }

        if ($this->rates()->exists()) {
            return true;
        }

        return $this->business_tax_code !== null || $this->gst_code !== null;
    }

    public function resolvesTaxBasis(): string
    {
        $basis = strtolower(trim((string) ($this->tax_basis ?? '')));
        if (in_array($basis, [self::TAX_BASIS_NONE, self::TAX_BASIS_GROSS, self::TAX_BASIS_NET], true)) {
            return $basis;
        }

        if (! $this->hasTaxRates()) {
            return self::TAX_BASIS_NONE;
        }

        return $this->implicitTaxBasis();
    }

    public function implicitTaxBasis(): string
    {
        return $this->is_rollup || self::lineKindFromAttributes($this->is_rollup, $this->qb_code, $this->qb_name) === 'net_total'
            ? self::TAX_BASIS_NET
            : self::TAX_BASIS_GROSS;
    }

    public function lineKind(): string
    {
        if ($this->line_kind && in_array($this->line_kind, ['qb_account', 'net_total', 'section'], true)) {
            return $this->line_kind;
        }

        return self::lineKindFromAttributes($this->is_rollup, $this->qb_code, $this->qb_name);
    }

    public static function lineKindFromAttributes(bool $isRollup, ?string $qbCode, ?string $qbName): string
    {
        $name = strtolower(trim((string) $qbName));
        $code = strtoupper(trim((string) $qbCode));

        if (strlen($code) === 4 && str_ends_with($code, '00') && self::isSectionName($code, $name)) {
            return 'section';
        }

        if ($isRollup || str_starts_with($name, 'total ') || str_contains($name, '(net)')) {
            return 'net_total';
        }

        return 'qb_account';
    }

    private static function isSectionName(string $code, string $name): bool
    {
        if ($name === '' || str_starts_with($name, 'total ')) {
            return false;
        }

        if (str_contains($name, ' - ') || str_contains($name, '- ') || str_contains($name, '(gross)') || str_contains($name, '(net)')) {
            return false;
        }

        $prefix = strtolower($code).' ';
        if (str_starts_with($name, $prefix.'·') || str_starts_with($name, $prefix.'.')) {
            return true;
        }

        return str_ends_with($name, ':');
    }

    public static function inferIsRollup(?string $qbName): bool
    {
        $name = strtolower(trim((string) $qbName));

        return str_starts_with($name, 'total ') || str_contains($name, '(net)');
    }

    public function mappingCode(): string
    {
        return strtoupper((string) ($this->qb_code ?: $this->account?->code1 ?: ''));
    }

    public function displayName(): string
    {
        if ($this->qb_code || $this->qb_name) {
            return trim(($this->qb_code ?? '').' · '.($this->qb_name ?? ''));
        }

        if ($this->account) {
            return trim(($this->account->code1 ?? '').' — '.$this->account->name);
        }

        return 'Account';
    }
}
