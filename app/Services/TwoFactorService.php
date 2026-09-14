<?php

namespace App\Services;

use App\Models\AuthSetting;
use App\Models\User;
use App\Support\AuthUserPresenter;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private readonly Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function settings(): AuthSetting
    {
        return AuthSetting::current();
    }

    /**
     * After password validation: full session, 2FA challenge, or forced setup.
     *
     * @return array<string, mixed>
     */
    public function completePasswordLogin(User $user): array
    {
        $settings = $this->settings();

        if (! $user->requiresTwoFactor($settings)) {
            return $this->sessionPayload($user);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->challengePayload($user, 'verify');
        }

        return $this->challengePayload($user, 'setup');
    }

    /**
     * Passkeys already provide phishing-resistant MFA — issue a full session.
     *
     * @return array<string, mixed>
     */
    public function completeStrongLogin(User $user): array
    {
        return $this->sessionPayload($user);
    }

    /**
     * @return array{challenge_key: string, secret: string, otpauth_url: string, qr_svg: string}
     */
    public function beginSetup(User $user, ?string $challengeKey = null): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw new \RuntimeException('Two-factor authentication is already enabled.');
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $company = (string) config('app.name', 'Payroll');
        $otpauthUrl = $this->google2fa->getQRCodeUrl($company, $user->email, $secret);

        if ($challengeKey) {
            $cached = Cache::get($challengeKey);
            if (! is_array($cached) || ($cached['user_id'] ?? null) !== (string) $user->id) {
                throw new \RuntimeException('Two-factor challenge expired or invalid.');
            }
            $cached['pending_secret'] = $secret;
            $cached['purpose'] = 'setup';
            Cache::put($challengeKey, $cached, now()->addMinutes(10));
        } else {
            // Authenticated setup: stash pending secret on the user row until confirmed.
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        return [
            'challenge_key' => $challengeKey,
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'qr_svg' => $this->qrSvgDataUri($otpauthUrl),
        ];
    }

    /**
     * @return array{recovery_codes: list<string>}|array<string, mixed>
     */
    public function confirmSetup(User $user, string $code, ?string $challengeKey = null): array
    {
        $secret = null;

        if ($challengeKey) {
            $cached = Cache::get($challengeKey);
            if (! is_array($cached) || ($cached['user_id'] ?? null) !== (string) $user->id) {
                throw new \RuntimeException('Two-factor challenge expired or invalid.');
            }
            $secret = $cached['pending_secret'] ?? null;
        } else {
            $secret = $user->two_factor_secret;
        }

        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('No pending authenticator setup. Start setup again.');
        }

        if (! $this->verifyCode($secret, $code)) {
            throw new \RuntimeException('Invalid authentication code.');
        }

        $plainRecoveryCodes = $this->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(
                static fn (string $code) => Hash::make($code),
                $plainRecoveryCodes
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();

        if ($challengeKey) {
            Cache::forget($challengeKey);

            return array_merge(
                $this->sessionPayload($user->fresh() ?? $user),
                ['recovery_codes' => $plainRecoveryCodes]
            );
        }

        return ['recovery_codes' => $plainRecoveryCodes];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyChallenge(string $challengeKey, string $code): array
    {
        $cached = Cache::get($challengeKey);
        if (! is_array($cached) || ($cached['purpose'] ?? null) !== 'verify') {
            throw new \RuntimeException('Two-factor challenge expired or invalid.');
        }

        $user = User::query()->find($cached['user_id'] ?? null);
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            throw new \RuntimeException('Two-factor is not enabled for this account.');
        }

        $secret = (string) $user->two_factor_secret;
        $valid = $this->verifyCode($secret, $code) || $this->consumeRecoveryCode($user, $code);
        if (! $valid) {
            throw new \RuntimeException('Invalid authentication code.');
        }

        Cache::forget($challengeKey);

        return $this->sessionPayload($user);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', trim($code)) ?? '';
        if ($code === '' || $secret === '') {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, 1);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
        $hashes = $user->two_factor_recovery_codes;
        if (! is_array($hashes) || $hashes === [] || $normalized === '') {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (! is_string($hash)) {
                continue;
            }

            // Codes are stored as XXXX-XXXX; accept with or without separators.
            $candidates = [
                $normalized,
                substr($normalized, 0, 4).'-'.substr($normalized, 4),
            ];
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && Hash::check($candidate, $hash)) {
                    unset($hashes[$index]);
                    $user->forceFill([
                        'two_factor_recovery_codes' => array_values($hashes),
                    ])->save();

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function challengePayload(User $user, string $purpose): array
    {
        $challengeKey = '2fa:'.$purpose.':'.Str::uuid()->toString();
        Cache::put($challengeKey, [
            'user_id' => (string) $user->id,
            'purpose' => $purpose,
        ], now()->addMinutes(10));

        return [
            'message' => $purpose === 'setup'
                ? 'Two-factor authentication setup required'
                : 'Two-factor authentication required',
            'requires_two_factor' => $purpose === 'verify',
            'requires_two_factor_setup' => $purpose === 'setup',
            'challenge_key' => $challengeKey,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(User $user): array
    {
        $token = $user->createToken($user->email)->plainTextToken;

        return [
            'message' => 'Login successful',
            'user' => AuthUserPresenter::present($user),
            'token' => $token,
        ];
    }

    private function qrSvgDataUri(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
