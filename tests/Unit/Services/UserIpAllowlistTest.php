<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserIpAllowlist;
use Tests\TestCase;

class UserIpAllowlistTest extends TestCase
{
    public function test_empty_allowlist_permits_any_ip(): void
    {
        $user = new User(['allowed_ips' => []]);
        $service = new UserIpAllowlist();

        $this->assertTrue($service->allows($user, '203.0.113.10'));
        $this->assertTrue($service->allows($user, '127.0.0.1'));
    }

    public function test_exact_and_cidr_matches(): void
    {
        $user = new User(['allowed_ips' => ['203.0.113.10', '10.0.0.0/24']]);
        $service = new UserIpAllowlist();

        $this->assertTrue($service->allows($user, '203.0.113.10'));
        $this->assertTrue($service->allows($user, '10.0.0.42'));
        $this->assertFalse($service->allows($user, '203.0.113.11'));
        $this->assertFalse($service->allows($user, '10.0.1.1'));
    }

    public function test_loopback_addresses_are_equivalent(): void
    {
        $user = new User(['allowed_ips' => ['127.0.0.1']]);
        $service = new UserIpAllowlist();

        $this->assertTrue($service->allows($user, '::1'));
        $this->assertTrue($service->allows($user, '127.0.0.1'));
    }

    public function test_normalize_rejects_invalid_entries(): void
    {
        $this->assertSame(
            ['203.0.113.10', '10.0.0.0/24'],
            UserIpAllowlist::normalize([' 203.0.113.10 ', 'not-an-ip', '10.0.0.0/24', '10.0.0.0/99', '203.0.113.10']),
        );
        $this->assertFalse(UserIpAllowlist::isValidEntry('999.1.1.1/24'));
        $this->assertTrue(UserIpAllowlist::isValidEntry('2001:db8::1'));
        $this->assertTrue(UserIpAllowlist::isValidEntry('2001:db8::/32'));
    }
}
