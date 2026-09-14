<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;

class TenantManager
{
    private ?Tenant $current = null;

    public function enabled(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function resolveSlugFromRequest(Request $request): ?string
    {
        $header = (string) config('tenancy.header', 'X-Tenant');
        $fromHeader = strtolower(trim((string) $request->header($header, '')));
        if ($fromHeader !== '') {
            return $this->normalizeSlug($fromHeader);
        }

        $host = strtolower($request->getHost());
        $central = array_map('strtolower', config('tenancy.central_domains', []));
        $centralSuffixes = array_map('strtolower', config('tenancy.central_domain_suffixes', []));

        // Azure Container Apps / Storage default hosts are not tenant subdomains.
        if ($this->hostMatchesCentralSuffix($host, $centralSuffixes)) {
            return $this->defaultSlug();
        }

        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            if ($subdomain !== '' && ! in_array($subdomain, $central, true)) {
                return $this->normalizeSlug($subdomain);
            }
        }

        return $this->defaultSlug();
    }

    private function defaultSlug(): ?string
    {
        $default = strtolower(trim((string) config('tenancy.default', '')));

        return $default !== '' ? $this->normalizeSlug($default) : null;
    }

    /**
     * @param  list<string>  $suffixes
     */
    private function hostMatchesCentralSuffix(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            $suffix = ltrim(trim($suffix), '.');
            if ($suffix === '') {
                continue;
            }
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public function initializeBySlug(string $slug): Tenant
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            throw new InvalidArgumentException('Tenant slug is required.');
        }

        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();

        if (! $tenant) {
            throw new RuntimeException("Unknown or disabled tenant [{$slug}].");
        }

        $this->initialize($tenant);

        return $tenant;
    }

    public function initialize(Tenant $tenant): void
    {
        if (! $tenant->enabled) {
            throw new RuntimeException("Tenant [{$tenant->slug}] is disabled.");
        }

        Config::set('database.connections.tenant.database', $tenant->database);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');
        DB::setDefaultConnection('tenant');

        $this->current = $tenant;
    }

    public function forget(): void
    {
        $this->current = null;
        Config::set('database.default', env('DB_CONNECTION', 'pgsql'));
        DB::setDefaultConnection(config('database.default'));
    }

    public function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    public function databaseNameForSlug(string $slug): string
    {
        $slug = $this->normalizeSlug($slug);
        $prefix = (string) config('tenancy.database_prefix', 'payroll_');

        return $prefix.$slug;
    }
}
