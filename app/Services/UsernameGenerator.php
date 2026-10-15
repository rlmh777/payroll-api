<?php

namespace App\Services;

use App\Models\AuthSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class UsernameGenerator
{
    public const PATTERN_FIRST_LAST = 'first_last';

    public const PATTERN_LAST_FIRST = 'last_first';

    public const PATTERN_FIRST_INITIAL_LAST = 'first_initial_last';

    public const PATTERN_FIRSTLAST = 'firstlast';

    public const PATTERNS = [
        self::PATTERN_FIRST_LAST,
        self::PATTERN_LAST_FIRST,
        self::PATTERN_FIRST_INITIAL_LAST,
        self::PATTERN_FIRSTLAST,
    ];

    public const SEPARATORS = ['.', '_', '-', ''];

    public function __construct(
        private ?AuthSetting $settings = null,
    ) {
    }

    public function settings(): AuthSetting
    {
        return $this->settings ?? AuthSetting::current();
    }

    public static function sanitize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9._-]/', '', $normalized) ?? '';

        return substr($normalized, 0, 64);
    }

    public static function normalizePatterns(mixed $patterns): array
    {
        $values = is_array($patterns) ? $patterns : [$patterns];
        $normalized = [];

        foreach ($values as $pattern) {
            if (! is_string($pattern) || ! in_array($pattern, self::PATTERNS, true)) {
                continue;
            }
            if (! in_array($pattern, $normalized, true)) {
                $normalized[] = $pattern;
            }
        }

        return $normalized !== [] ? $normalized : [self::PATTERN_FIRST_LAST];
    }

    /**
     * Enabled patterns in priority order.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        $settings = $this->settings();

        if (is_array($settings->username_patterns) && $settings->username_patterns !== []) {
            return self::normalizePatterns($settings->username_patterns);
        }

        return self::normalizePatterns($settings->username_pattern);
    }

    public function buildUsername(string $firstName, string $lastName, ?string $middleName = null): string
    {
        $includeMiddle = $this->settings()->username_include_middle_initial !== false;

        return $this->compose(
            $firstName,
            $lastName,
            $includeMiddle ? $middleName : null,
            $this->patterns()[0],
        );
    }

    /**
     * @return array{username: string, email: string}
     */
    public function generateUniqueLoginCredentials(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        $domain = $this->resolveEmailDomain();

        foreach ($this->usernameCandidates($firstName, $lastName, $middleName) as $username) {
            $email = "{$username}@{$domain}";
            if (! $this->isTaken($username, $email)) {
                return compact('username', 'email');
            }
        }

        throw new RuntimeException('Unable to generate a unique employee login username.');
    }

    /**
     * @return list<string>
     */
    public function usernameCandidates(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        $candidates = $this->previewCandidates($firstName, $lastName, $middleName);
        $firstBase = $candidates[0];

        for ($suffix = 2; $suffix <= 100; $suffix++) {
            $candidates[] = substr($firstBase, 0, 60).$suffix;
        }

        return $candidates;
    }

    /**
     * Selected-pattern candidates in priority order, without numeric suffixes.
     *
     * @return list<string>
     */
    public function previewCandidates(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        $patterns = $this->patterns();
        $candidates = [];

        foreach ($patterns as $pattern) {
            $candidates[] = $this->compose($firstName, $lastName, null, $pattern);
        }

        if ($this->settings()->username_include_middle_initial !== false) {
            foreach ($patterns as $pattern) {
                $withMiddle = $this->compose($firstName, $lastName, $middleName, $pattern);
                if (! in_array($withMiddle, $candidates, true)) {
                    $candidates[] = $withMiddle;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    public function uniqueForNameOrEmail(string $name, string $email, ?string $ignoreUserId = null): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? (string) $parts[array_key_last($parts)] : '';
        $middle = count($parts) > 2 ? (string) $parts[1] : null;

        if ($first !== '' && $last !== '') {
            try {
                foreach ($this->usernameCandidates($first, $last, $middle) as $candidate) {
                    if (! $this->isTaken($candidate, null, $ignoreUserId)) {
                        return $candidate;
                    }
                }
            } catch (RuntimeException) {
                // Fall through to the email local-part.
            }
        }

        return $this->uniqueFromEmailLocalPart($email, $ignoreUserId);
    }

    public function uniqueFromEmailLocalPart(string $email, ?string $ignoreUserId = null): string
    {
        $base = self::sanitize((string) Str::before($email, '@'));
        if ($base === '') {
            $base = 'user';
        }

        $username = $base;
        $suffix = 2;
        while ($this->isTaken($username, null, $ignoreUserId)) {
            $username = substr($base, 0, 60).$suffix;
            $suffix++;
            if ($suffix > 1000) {
                throw new RuntimeException('Unable to generate a unique username.');
            }
        }

        return $username;
    }

    public function resolveEmailDomain(): string
    {
        $fromSettings = trim((string) ($this->settings()->employee_login_domain ?? ''));
        if ($fromSettings !== '') {
            return ltrim($fromSettings, '@');
        }

        $configuredDomain = trim((string) config('payroll.employee_login_domain'));
        if ($configuredDomain !== '') {
            return ltrim($configuredDomain, '@');
        }

        $companyEmail = Company::query()->value('email');
        if (is_string($companyEmail) && str_contains($companyEmail, '@')) {
            return Str::after($companyEmail, '@');
        }

        return 'payroll.local';
    }

    public function isTaken(string $username, ?string $email = null, ?string $ignoreUserId = null): bool
    {
        $username = strtolower($username);

        return User::query()
            ->when($ignoreUserId, fn ($query) => $query->where('id', '!=', $ignoreUserId))
            ->where(function ($query) use ($username, $email) {
                $query->whereRaw('LOWER(username) = ?', [$username]);
                if (is_string($email) && $email !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
            })
            ->exists();
    }

    private function compose(string $firstName, string $lastName, ?string $middleName, string $pattern): string
    {
        $first = $this->slugNamePart($firstName);
        $last = $this->slugNamePart($lastName);

        if ($first === '' || $last === '') {
            throw new RuntimeException('First and last name are required to generate a username.');
        }

        $middleInitial = $this->middleInitial($middleName);
        $separator = $this->separator();

        $username = match ($pattern) {
            self::PATTERN_LAST_FIRST => $this->join([$last, $middleInitial, $first], $separator),
            self::PATTERN_FIRST_INITIAL_LAST => $this->join(
                [substr($first, 0, 1), $middleInitial, $last],
                $separator,
            ),
            self::PATTERN_FIRSTLAST => $first.($middleInitial ?? '').$last,
            default => $this->join([$first, $middleInitial, $last], $separator),
        };

        $sanitized = self::sanitize($username);
        if ($sanitized === '') {
            throw new RuntimeException('Unable to generate a username from the given name.');
        }

        return $sanitized;
    }

    private function separator(): string
    {
        $separator = (string) ($this->settings()->username_separator ?? '.');

        return in_array($separator, self::SEPARATORS, true) ? $separator : '.';
    }

    private function join(array $parts, string $separator): string
    {
        $filtered = [];
        foreach ($parts as $part) {
            if ($part === null || $part === '') {
                continue;
            }
            $filtered[] = $part;
        }

        return implode($separator, $filtered);
    }

    private function middleInitial(?string $middleName): ?string
    {
        if ($middleName === null) {
            return null;
        }

        $slug = $this->slugNamePart($middleName);
        if ($slug === '') {
            return null;
        }

        return $slug[0];
    }

    private function slugNamePart(string $value): string
    {
        $normalized = Str::slug(strtolower(trim($value)), '');

        return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
    }
}
