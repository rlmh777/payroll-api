<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class WebAuthnCredential extends Model
{
    use HasUuids;

    protected $table = 'webauthn_credentials';

    protected $fillable = [
        'user_id',
        'name',
        'credential_id',
        'credential_id_hash',
        'public_key',
        'aaguid',
        'counter',
        'transports',
        'attestation_type',
        'trust_path',
        'backup_eligible',
        'backup_status',
    ];

    protected $casts = [
        'counter' => 'integer',
        'transports' => 'array',
        'trust_path' => 'array',
        'backup_eligible' => 'boolean',
        'backup_status' => 'boolean',
    ];

    protected $hidden = [
        'public_key',
        'credential_id',
        'credential_id_hash',
        'trust_path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toCredentialRecord(): CredentialRecord
    {
        $aaguid = $this->aaguid
            ? Uuid::fromString((string) $this->aaguid)
            : Uuid::fromString('00000000-0000-0000-0000-000000000000');

        return CredentialRecord::create(
            base64_decode((string) $this->credential_id, true) ?: (string) $this->credential_id,
            'public-key',
            $this->transports ?? [],
            $this->attestation_type ?: 'none',
            EmptyTrustPath::create(),
            $aaguid,
            base64_decode((string) $this->public_key, true) ?: (string) $this->public_key,
            (string) $this->user_id,
            (int) $this->counter,
            null,
            $this->backup_eligible,
            $this->backup_status,
        );
    }

    public static function fromCredentialRecord(User $user, CredentialRecord $record, ?string $name = null): self
    {
        $credentialId = base64_encode($record->publicKeyCredentialId);

        return new self([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => $name ?: 'Passkey',
            'credential_id' => $credentialId,
            'credential_id_hash' => hash('sha256', $credentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            'aaguid' => (string) $record->aaguid,
            'counter' => $record->counter,
            'transports' => $record->transports,
            'attestation_type' => $record->attestationType,
            'trust_path' => [],
            'backup_eligible' => $record->backupEligible,
            'backup_status' => $record->backupStatus,
        ]);
    }
}
