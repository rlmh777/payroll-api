<?php

namespace Tests\Unit\Support;

use App\Models\Tenant;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReflectionProperty;
use Tests\TestCase;

class TenantPublicUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('tenancy.central_domains', ['localhost', '127.0.0.1', 'www']);
        Config::set('tenancy.central_domain_suffixes', [
            'azurecontainerapps.io',
            'web.core.windows.net',
            'localhost',
        ]);
        Config::set('tenancy.public_host_suffix', 'kamihrm.com');
        Config::set('app.frontend_url', 'http://localhost:9000');
        Config::set('app.url', 'http://localhost');
        Config::set('webauthn.rp_id', 'localhost');
        Config::set('webauthn.origins', ['http://localhost:9000']);
    }

    public function test_request_host_wins_for_tenant_custom_domain(): void
    {
        $manager = app(TenantManager::class);
        $this->setCurrent($manager, 'chaacreek');
        $request = Request::create('https://chaacreek.kamihrm.com/api/login', 'POST');

        $this->assertSame('https://chaacreek.kamihrm.com', $manager->publicBaseUrl($request));

        $manager->applyPublicUrls($request);

        $this->assertSame('https://chaacreek.kamihrm.com', config('app.url'));
        $this->assertSame('https://chaacreek.kamihrm.com', config('app.frontend_url'));
        $this->assertSame('chaacreek.kamihrm.com', config('webauthn.rp_id'));
        $this->assertContains('https://chaacreek.kamihrm.com', config('webauthn.origins'));
    }

    public function test_another_shared_tenant_uses_its_own_host(): void
    {
        $manager = app(TenantManager::class);
        $this->setCurrent($manager, 'acme');
        $request = Request::create('https://acme.kamihrm.com/api/login', 'POST');

        $this->assertSame('https://acme.kamihrm.com', $manager->publicBaseUrl($request));
    }

    public function test_azure_container_host_uses_suffix_not_request_host(): void
    {
        $manager = app(TenantManager::class);
        $this->setCurrent($manager, 'chaacreek');
        $request = Request::create(
            'https://ca-payroll-shrdprod-api.proudriver-fdf05cd7.centralus.azurecontainerapps.io/api/login',
            'POST'
        );

        $this->assertSame('https://chaacreek.kamihrm.com', $manager->publicBaseUrl($request));
    }

    public function test_queue_without_request_uses_tenant_suffix(): void
    {
        $manager = app(TenantManager::class);
        $this->setCurrent($manager, 'acme');

        $this->assertSame('https://acme.kamihrm.com', $manager->publicBaseUrl());
    }

    public function test_localhost_keeps_env_frontend_url_when_suffix_unset(): void
    {
        Config::set('tenancy.public_host_suffix', '');
        $manager = app(TenantManager::class);
        $this->setCurrent($manager, 'chaacreek');
        $request = Request::create('http://localhost:8000/api/login', 'POST');

        $this->assertSame('http://localhost:9000', $manager->publicBaseUrl($request));
    }

    private function setCurrent(TenantManager $manager, string $slug): void
    {
        $tenant = new Tenant([
            'id' => $slug,
            'slug' => $slug,
            'name' => $slug,
            'database' => 'payroll_'.$slug,
            'enabled' => true,
        ]);
        $property = new ReflectionProperty(TenantManager::class, 'current');
        $property->setValue($manager, $tenant);
    }
}
