<?php

namespace App\Modules\Payroll\Services;

use App\Models\PayrollAccountMapping;
use Illuminate\Support\Collection;

class PayrollAccountMappingService
{
    /** @var Collection<string, PayrollAccountMapping>|null */
    private ?Collection $cache = null;

    /**
     * @return Collection<int, PayrollAccountMapping>
     */
    public function all(bool $activeOnly = false): Collection
    {
        $query = PayrollAccountMapping::query()
            ->with('account')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function accountIdFor(string $code): ?string
    {
        $mapping = $this->find($code);
        if (! $mapping || ! $mapping->is_active) {
            return null;
        }

        return filled($mapping->account_id) ? (string) $mapping->account_id : null;
    }

    /**
     * Resolve a journal account key: real account UUID, or mapping placeholder.
     */
    public function journalAccountKey(string $code): string
    {
        $accountId = $this->accountIdFor($code);
        if ($accountId) {
            return $accountId;
        }

        return 'mapping:'.strtoupper(trim($code));
    }

    public function find(string $code): ?PayrollAccountMapping
    {
        $normalized = strtoupper(trim($code));

        return $this->cached()->get($normalized);
    }

    public function forgetCache(): void
    {
        $this->cache = null;
    }

    /**
     * @return Collection<string, PayrollAccountMapping>
     */
    private function cached(): Collection
    {
        if ($this->cache === null) {
            $this->cache = PayrollAccountMapping::query()
                ->get()
                ->keyBy(fn (PayrollAccountMapping $mapping) => strtoupper((string) $mapping->code));
        }

        return $this->cache;
    }
}
