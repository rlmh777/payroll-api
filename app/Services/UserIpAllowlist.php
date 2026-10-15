<?php

namespace App\Services;

use App\Exceptions\LoginAccessDeniedException;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class UserIpAllowlist
{
    public const MAX_ENTRIES = 50;

    /**
     * @return list<string>
     */
    public function entries(User $user): array
    {
        return self::normalize($user->allowed_ips);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $entries = [];
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $entry = strtolower(trim((string) $value));
            if ($entry === '' || ! self::isValidEntry($entry) || in_array($entry, $entries, true)) {
                continue;
            }
            $entries[] = $entry;
            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $entries;
    }

    public static function isValidEntry(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$ip, $mask] = explode('/', $value, 2);
        if ($ip === '' || ! ctype_digit($mask)) {
            return false;
        }

        $prefix = (int) $mask;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix >= 0 && $prefix <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefix >= 0 && $prefix <= 128;
        }

        return false;
    }

    public function allows(User $user, string $ip): bool
    {
        $allowed = $this->entries($user);
        if ($allowed === []) {
            return true;
        }

        return $this->matches($ip, $allowed);
    }

    /**
     * @param  list<string>  $allowed
     */
    public function matches(string $ip, array $allowed): bool
    {
        $ip = strtolower(trim($ip));
        if ($ip === '' || $allowed === []) {
            return false;
        }

        if ($this->isLoopback($ip) && $this->allowsLoopback($allowed)) {
            return true;
        }

        return IpUtils::checkIp($ip, $allowed);
    }

    public function assertAllowed(User $user, Request $request): void
    {
        $ip = (string) $request->ip();
        if ($this->allows($user, $ip)) {
            return;
        }

        throw new LoginAccessDeniedException(
            'Access from this IP address is not allowed for this account.',
            403,
            ['client_ip' => $ip],
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowsLoopback(array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if ($this->isLoopback($entry) || $entry === '127.0.0.0/8' || $entry === '::1/128') {
                return true;
            }
        }

        return false;
    }

    private function isLoopback(string $value): bool
    {
        return in_array($value, ['127.0.0.1', '::1'], true);
    }
}
