<?php

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use App\Support\BiometricDevicePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BiometricDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = BiometricDevice::query()
            ->orderBy('name')
            ->get()
            ->map(fn (BiometricDevice $device) => BiometricDevicePresenter::present($device))
            ->values();

        return response()->json(['data' => $devices]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $token = BiometricDevice::issueToken();
        $device = new BiometricDevice($this->payload($validated, creating: true));
        $device->setAuthToken($token);
        $device->save();

        return response()->json([
            'message' => 'Biometric device created. Copy the device token now; it will not be shown again.',
            'data' => BiometricDevicePresenter::present($device, $token),
        ], 201);
    }

    public function show(BiometricDevice $biometricDevice): JsonResponse
    {
        return response()->json(['data' => BiometricDevicePresenter::present($biometricDevice)]);
    }

    public function update(Request $request, BiometricDevice $biometricDevice): JsonResponse
    {
        $validated = $this->validated($request, $biometricDevice);
        $biometricDevice->fill($this->payload($validated, creating: false));
        $biometricDevice->save();

        return response()->json([
            'message' => 'Biometric device updated.',
            'data' => BiometricDevicePresenter::present($biometricDevice),
        ]);
    }

    public function destroy(BiometricDevice $biometricDevice): JsonResponse
    {
        $biometricDevice->delete();

        return response()->json(['message' => 'Biometric device deleted.']);
    }

    public function rotateToken(BiometricDevice $biometricDevice): JsonResponse
    {
        $token = BiometricDevice::issueToken();
        $biometricDevice->setAuthToken($token);
        $biometricDevice->save();

        return response()->json([
            'message' => 'Device token rotated. Copy it now; it will not be shown again.',
            'data' => BiometricDevicePresenter::present($biometricDevice, $token),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?BiometricDevice $device = null): array
    {
        return $request->validate([
            'code' => [
                $device ? 'sometimes' : 'required',
                'string',
                'max:64',
                Rule::unique('biometric_devices', 'code')->ignore($device?->id),
            ],
            'name' => [$device ? 'sometimes' : 'required', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:64'],
            'serialNumber' => [
                'sometimes',
                'nullable',
                'string',
                'max:128',
                Rule::unique('biometric_devices', 'serial_number')->ignore($device?->id),
            ],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone:all'],
            'protocol' => ['sometimes', 'string', Rule::in(BiometricDevice::PROTOCOLS)],
            'heartbeatTimeoutSeconds' => ['sometimes', 'integer', 'min:15', 'max:3600'],
            'isActive' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated, bool $creating): array
    {
        $map = [
            'code' => 'code',
            'name' => 'name',
            'model' => 'model',
            'serialNumber' => 'serial_number',
            'location' => 'location',
            'timezone' => 'timezone',
            'protocol' => 'protocol',
            'heartbeatTimeoutSeconds' => 'heartbeat_timeout_seconds',
            'isActive' => 'is_active',
            'notes' => 'notes',
        ];

        $payload = [];
        foreach ($map as $from => $to) {
            if (! array_key_exists($from, $validated)) {
                continue;
            }
            $value = $validated[$from];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }
            $payload[$to] = $value;
        }

        if ($creating) {
            $payload['model'] = $payload['model'] ?? config('biometric.default_model', 'DS-K1T342');
            $payload['timezone'] = $payload['timezone'] ?? config('biometric.default_timezone', 'America/Belize');
            $payload['protocol'] = $payload['protocol'] ?? 'http_push';
            $payload['heartbeat_timeout_seconds'] = $payload['heartbeat_timeout_seconds']
                ?? (int) config('biometric.heartbeat_timeout_seconds', 90);
            $payload['is_active'] = $payload['is_active'] ?? true;
        }

        return $payload;
    }
}
