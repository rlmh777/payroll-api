<?php

namespace App\Http\Controllers;

use App\Enums\StorageDriver;
use App\Models\StorageSetting;
use App\Support\ConfiguredStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorageSettingController extends Controller
{
    public function __construct(
        private readonly ConfiguredStorage $storage,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->format(StorageSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => ['required', Rule::in(StorageDriver::values())],
            'container' => ['nullable', 'string', 'max:255'],
            'accountName' => ['nullable', 'string', 'max:255'],
            'accountKey' => ['nullable', 'string', 'max:2000'],
            'endpoint' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:64'],
            'usePathStyleEndpoint' => ['sometimes', 'boolean'],
            'prefix' => ['nullable', 'string', 'max:255'],
        ]);

        $driver = StorageDriver::from($validated['driver']);
        $settings = StorageSetting::current();
        $payload = [
            'driver' => $driver->value,
            'container' => $validated['container'] ?? $settings->container ?? 'uploads',
            'account_name' => $validated['accountName'] ?? $settings->account_name,
            'endpoint' => $validated['endpoint'] ?? null,
            'region' => $validated['region'] ?? null,
            'use_path_style_endpoint' => (bool) ($validated['usePathStyleEndpoint'] ?? false),
            'prefix' => $validated['prefix'] ?? null,
        ];

        if (filled($validated['accountKey'] ?? null)) {
            $payload['account_key'] = $validated['accountKey'];
        }

        if ($driver !== StorageDriver::Local) {
            $request->validate([
                'container' => ['required', 'string', 'max:255'],
                'accountName' => ['required', 'string', 'max:255'],
            ]);
            if (! filled($payload['account_key'] ?? $settings->account_key)) {
                return response()->json([
                    'message' => 'Account key is required the first time you connect this storage.',
                    'errors' => ['accountKey' => ['Account key is required.']],
                ], 422);
            }
        }

        $settings->fill($payload)->save();
        $this->storage->forgetCachedDisk();

        return response()->json([
            'message' => 'File storage settings saved.',
            'data' => $this->format($settings->fresh() ?? $settings),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $settings = StorageSetting::current();
        if ($request->filled('driver')) {
            $validated = $request->validate([
                'driver' => ['required', Rule::in(StorageDriver::values())],
                'container' => ['nullable', 'string', 'max:255'],
                'accountName' => ['nullable', 'string', 'max:255'],
                'accountKey' => ['nullable', 'string', 'max:2000'],
                'endpoint' => ['nullable', 'string', 'max:500'],
                'region' => ['nullable', 'string', 'max:64'],
                'usePathStyleEndpoint' => ['sometimes', 'boolean'],
                'prefix' => ['nullable', 'string', 'max:255'],
            ]);
            $settings = $settings->replicate();
            $settings->driver = $validated['driver'];
            $settings->container = $validated['container'] ?? $settings->container;
            $settings->account_name = $validated['accountName'] ?? $settings->account_name;
            if (filled($validated['accountKey'] ?? null)) {
                $settings->account_key = $validated['accountKey'];
            }
            $settings->endpoint = $validated['endpoint'] ?? $settings->endpoint;
            $settings->region = $validated['region'] ?? $settings->region;
            $settings->use_path_style_endpoint = (bool) ($validated['usePathStyleEndpoint'] ?? $settings->use_path_style_endpoint);
            $settings->prefix = $validated['prefix'] ?? $settings->prefix;
        }

        $result = $this->storage->testConnection($settings);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(StorageSetting $settings): array
    {
        return [
            'driver' => $settings->driverEnum()->value,
            'container' => $settings->container ?: 'uploads',
            'accountName' => $settings->account_name,
            'accountKeySet' => $settings->hasSecret(),
            'endpoint' => $settings->endpoint,
            'region' => $settings->region,
            'usePathStyleEndpoint' => (bool) $settings->use_path_style_endpoint,
            'prefix' => $settings->prefix,
        ];
    }
}
