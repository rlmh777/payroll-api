<?php

namespace App\Services;

use App\Models\AuthSetting;
use App\Models\User;
use App\Models\WebAuthnCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\AuthenticatorSelectionCriteria;

class PasskeyService
{
    public function enabled(): bool
    {
        return (bool) AuthSetting::current()->passkeys_enabled;
    }

    /**
     * @return array{options: array<string, mixed>, challenge_key: string}
     */
    public function registrationOptions(User $user): array
    {
        $challenge = random_bytes(32);
        $challengeKey = 'webauthn:register:'.$user->id.':'.Str::uuid()->toString();
        $rpId = (string) config('webauthn.rp_id');

        $excludeCredentials = $user->webAuthnCredentials->map(fn (WebAuthnCredential $credential) => [
            'type' => 'public-key',
            'id' => $this->toBase64Url($this->decodeBinary($credential->credential_id)),
            'transports' => $credential->transports ?? [],
        ])->values()->all();

        $options = [
            'rp' => [
                'name' => (string) config('webauthn.rp_name'),
                'id' => $rpId,
            ],
            'user' => [
                'id' => $this->toBase64Url((string) $user->id),
                'name' => $user->email,
                'displayName' => $user->name ?: $user->email,
            ],
            'challenge' => $this->toBase64Url($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60_000,
            'attestation' => 'none',
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'required',
            ],
        ];

        Cache::put($challengeKey, [
            'challenge' => base64_encode($challenge),
            'user_id' => (string) $user->id,
        ], now()->addSeconds((int) config('webauthn.challenge_ttl', 300)));

        return [
            'options' => $options,
            'challenge_key' => $challengeKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentialPayload
     */
    public function register(User $user, string $challengeKey, array $credentialPayload, ?string $name = null): WebAuthnCredential
    {
        $cached = Cache::pull($challengeKey);
        if (! is_array($cached) || ($cached['user_id'] ?? null) !== (string) $user->id) {
            throw new \RuntimeException('Passkey registration challenge expired or invalid.');
        }

        $publicKeyCredential = $this->denormalizeCredential($credentialPayload);
        $response = $publicKeyCredential->response;
        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Invalid passkey registration response.');
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(
                (string) config('webauthn.rp_name'),
                (string) config('webauthn.rp_id'),
            ),
            PublicKeyCredentialUserEntity::create(
                $user->email,
                (string) $user->id,
                $user->name ?: $user->email,
            ),
            base64_decode((string) $cached['challenge'], true) ?: '',
            [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
            AuthenticatorSelectionCriteria::create(
                AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        );

        $validator = AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory()->creationCeremony());
        $record = $validator->check($response, $options, (string) config('webauthn.rp_id'));

        $model = WebAuthnCredential::fromCredentialRecord($user, $record, $name);
        $model->save();

        return $model;
    }

    /**
     * @return array{options: array<string, mixed>, challenge_key: string, has_passkeys: bool}
     */
    public function authenticationOptions(?string $email = null): array
    {
        $challenge = random_bytes(32);
        $challengeKey = 'webauthn:login:'.Str::uuid()->toString();
        $allowCredentials = [];
        $userId = null;
        $hasPasskeys = false;

        if ($email) {
            $user = $this->findUserByLoginIdentifier($email);
            if ($user) {
                $userId = (string) $user->id;
                $allowCredentials = $user->webAuthnCredentials->map(fn (WebAuthnCredential $credential) => [
                    'type' => 'public-key',
                    'id' => $this->toBase64Url($this->decodeBinary($credential->credential_id)),
                    'transports' => $credential->transports ?? [],
                ])->values()->all();
                $hasPasskeys = $allowCredentials !== [];
            }
        }

        $options = [
            'challenge' => $this->toBase64Url($challenge),
            'timeout' => 60_000,
            'rpId' => (string) config('webauthn.rp_id'),
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'preferred',
        ];

        Cache::put($challengeKey, [
            'challenge' => base64_encode($challenge),
            'user_id' => $userId,
        ], now()->addSeconds((int) config('webauthn.challenge_ttl', 300)));

        return [
            'options' => $options,
            'challenge_key' => $challengeKey,
            'has_passkeys' => $hasPasskeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentialPayload
     */
    public function authenticate(string $challengeKey, array $credentialPayload): User
    {
        $cached = Cache::pull($challengeKey);
        if (! is_array($cached)) {
            throw new \RuntimeException('Passkey login challenge expired or invalid.');
        }

        $publicKeyCredential = $this->denormalizeCredential($credentialPayload);
        $response = $publicKeyCredential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Invalid passkey login response.');
        }

        $credentialIdB64 = base64_encode($publicKeyCredential->rawId);
        $credential = WebAuthnCredential::query()
            ->where('credential_id_hash', hash('sha256', $credentialIdB64))
            ->first();

        if (! $credential) {
            throw new \RuntimeException('Unknown passkey.');
        }

        $user = $credential->user;
        if (! $user) {
            throw new \RuntimeException('Passkey user not found.');
        }

        if (($cached['user_id'] ?? null) && $cached['user_id'] !== (string) $user->id) {
            throw new \RuntimeException('Passkey does not match the requested account.');
        }

        $options = PublicKeyCredentialRequestOptions::create(
            base64_decode((string) $cached['challenge'], true) ?: '',
            (string) config('webauthn.rp_id'),
        );

        $validator = AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony());
        $updated = $validator->check(
            $credential->toCredentialRecord(),
            $response,
            $options,
            (string) config('webauthn.rp_id'),
            (string) $user->id,
        );

        $credential->counter = $updated->counter;
        $credential->backup_eligible = $updated->backupEligible;
        $credential->backup_status = $updated->backupStatus;
        $credential->save();

        return $user;
    }

    public function findUserByLoginIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $user = User::query()->where('email', $identifier)->first();
        if ($user) {
            return $user;
        }

        if (! str_contains($identifier, '@')) {
            return User::query()
                ->where('email', 'like', $identifier.'@%')
                ->orderBy('email')
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentialPayload
     */
    private function denormalizeCredential(array $credentialPayload): PublicKeyCredential
    {
        /** @var PublicKeyCredential $credential */
        $credential = $this->serializer()->denormalize($credentialPayload, PublicKeyCredential::class);

        return $credential;
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $origins = config('webauthn.origins', []);
        if (is_array($origins) && $origins !== []) {
            $factory->setAllowedOrigins($origins, true);
        }
        $factory->setSecuredRelyingPartyId([(string) config('webauthn.rp_id')]);

        return $factory;
    }

    private function serializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());

        return (new WebauthnSerializerFactory($attestationManager))->create();
    }

    private function decodeBinary(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded !== false) {
            return $decoded;
        }

        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded !== false ? $decoded : $value;
    }

    private function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
