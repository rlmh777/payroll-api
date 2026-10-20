<?php

namespace App\Services;

use App\Exceptions\SensitiveActionException;
use App\Models\User;
use App\Support\Access;
use App\Support\ConfiguredMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SensitiveActionChallengeService
{
    public const ACTION_DATABASE_RESTORE = 'database_restore';

    public const ACTION_LOG_CLEAR = 'application_log_clear';

    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    /**
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        self::ACTION_DATABASE_RESTORE => 'database-backup-crud',
        self::ACTION_LOG_CLEAR => 'application-logs-crud',
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::ACTION_DATABASE_RESTORE => 'restore the production database',
        self::ACTION_LOG_CLEAR => 'delete application logs',
    ];

    public function __construct(
        private readonly ConfiguredMail $mail,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{challenge_key: string, sent_to: string, expires_in: int, action: string}
     */
    public function issue(User $user, string $action, array $context = []): array
    {
        $this->assertCanIssue($user, $action);
        $context = $this->normalizeContext($action, $context);

        if (! filled($user->email)) {
            throw new SensitiveActionException('Your account has no email address to send a confirmation code to.', 422);
        }

        if (! $this->mail->isReady()) {
            throw new SensitiveActionException(
                'Outbound email is not configured. Set up Admin → Settings → Email before restoring the database or clearing logs.',
                503,
            );
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $challengeKey = 'sensitive:'.$action.':'.Str::uuid()->toString();

        Cache::put($challengeKey, [
            'user_id' => (string) $user->id,
            'action' => $action,
            'context' => $context,
            // Short-lived OTPs do not need bcrypt (expensive and a poor fit for 6-digit codes).
            'code_hash' => hash_hmac('sha256', $code, (string) config('app.key')),
            'attempts' => 0,
        ], now()->addSeconds(self::TTL_SECONDS));

        $label = self::LABELS[$action];
        $this->mail->sendHtml(
            (string) $user->email,
            'Confirmation code: '.$label,
            $this->emailBody($user, $label, $code),
        );

        Log::info('Sensitive action confirmation code sent.', [
            'action' => $action,
            'user_id' => $user->id,
            'context' => $context,
        ]);

        return [
            'challenge_key' => $challengeKey,
            'sent_to' => $this->maskEmail((string) $user->email),
            'expires_in' => self::TTL_SECONDS,
            'action' => $action,
        ];
    }

    /**
     * @param  array<string, mixed>  $expectedContext
     */
    public function consume(User $user, string $action, string $challengeKey, string $code, array $expectedContext = []): void
    {
        $this->assertCanIssue($user, $action);
        $expectedContext = $this->normalizeContext($action, $expectedContext);

        $cached = Cache::get($challengeKey);
        if (! is_array($cached)
            || ($cached['user_id'] ?? null) !== (string) $user->id
            || ($cached['action'] ?? null) !== $action
        ) {
            throw new SensitiveActionException('Confirmation code expired or invalid. Request a new code.');
        }

        if (($cached['context'] ?? []) !== $expectedContext) {
            throw new SensitiveActionException('Confirmation code does not match this action. Request a new code.');
        }

        $attempts = (int) ($cached['attempts'] ?? 0) + 1;
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        $hash = $cached['code_hash'] ?? null;
        $expected = hash_hmac('sha256', $normalized, (string) config('app.key'));
        $valid = is_string($hash) && $hash !== '' && hash_equals($hash, $expected);

        if (! $valid) {
            if ($attempts >= self::MAX_ATTEMPTS) {
                Cache::forget($challengeKey);
                throw new SensitiveActionException('Too many incorrect confirmation codes. Request a new code.', 423);
            }

            $cached['attempts'] = $attempts;
            Cache::put($challengeKey, $cached, now()->addSeconds(self::TTL_SECONDS));

            throw new SensitiveActionException('Invalid confirmation code.');
        }

        Cache::forget($challengeKey);

        Log::info('Sensitive action confirmation code accepted.', [
            'action' => $action,
            'user_id' => $user->id,
            'context' => $expectedContext,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function normalizeContext(string $action, array $context): array
    {
        return match ($action) {
            self::ACTION_DATABASE_RESTORE => [
                'source' => ($context['source'] ?? 'backup') === 'upload' ? 'upload' : 'backup',
                'backup_id' => ($context['source'] ?? 'backup') === 'upload'
                    ? null
                    : (isset($context['backup_id']) ? (string) $context['backup_id'] : null),
            ],
            self::ACTION_LOG_CLEAR => [
                'file' => filled($context['file'] ?? null) ? (string) $context['file'] : null,
            ],
            default => throw new SensitiveActionException('Unknown sensitive action.', 422),
        };
    }

    private function assertCanIssue(User $user, string $action): void
    {
        $permission = self::PERMISSIONS[$action] ?? null;
        if (! is_string($permission) || $permission === '') {
            throw new SensitiveActionException('Unknown sensitive action.', 422);
        }

        if (! Access::can($user, $permission)) {
            throw new SensitiveActionException('Forbidden.', 403);
        }
    }

    private function emailBody(User $user, string $label, string $code): string
    {
        $name = e($user->name !== '' ? $user->name : 'there');
        $safeLabel = e($label);
        $safeCode = e($code);

        return <<<HTML
<p>Hello {$name},</p>
<p>A confirmation code was requested to <strong>{$safeLabel}</strong> from the payroll admin screens.</p>
<p style="font-size:28px;letter-spacing:6px;font-weight:700">{$safeCode}</p>
<p>This code expires in 10 minutes and can be used once. If you did not request this, ignore the email and review recent admin activity.</p>
HTML;
    }

    public function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        if ($local === '' || $domain === '') {
            return '***';
        }

        $visible = mb_substr($local, 0, 1);
        $masked = $visible.str_repeat('*', max(1, mb_strlen($local) - 1));

        return $masked.'@'.$domain;
    }
}
