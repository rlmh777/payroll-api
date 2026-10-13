<?php

namespace App\Support;

use App\Models\BiometricDevice;

class BiometricDevicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(BiometricDevice $device, ?string $authToken = null): array
    {
        $payload = [
            'id' => $device->id,
            'code' => $device->code,
            'name' => $device->name,
            'model' => $device->model,
            'serialNumber' => $device->serial_number,
            'location' => $device->location,
            'timezone' => $device->timezone,
            'protocol' => $device->protocol,
            'heartbeatTimeoutSeconds' => (int) $device->heartbeat_timeout_seconds,
            'isActive' => (bool) $device->is_active,
            'status' => $device->liveStatus(),
            'registeredAt' => $device->registered_at?->toIso8601String(),
            'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
            'lastHeartbeatAt' => $device->last_heartbeat_at?->toIso8601String(),
            'lastEventAt' => $device->last_event_at?->toIso8601String(),
            'ipAddress' => $device->ip_address,
            'firmware' => $device->firmware,
            'lastError' => $device->last_error,
            'notes' => $device->notes,
            'authTokenSet' => filled($device->auth_token_hash),
            'pushUrl' => url('/api/integrations/biometric/push'),
            'heartbeatUrl' => url('/api/integrations/biometric/heartbeat'),
            'registerUrl' => url('/api/integrations/biometric/register'),
        ];

        if ($authToken !== null) {
            $payload['authToken'] = $authToken;
        }

        return $payload;
    }
}
