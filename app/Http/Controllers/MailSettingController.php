<?php

namespace App\Http\Controllers;

use App\Models\MailSetting;
use App\Support\ConfiguredMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailSettingController extends Controller
{
    public function __construct(
        private readonly ConfiguredMail $mail,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->format(MailSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $settings = MailSetting::current();
        $payload = $this->payloadFromValidated($validated, $settings);

        if (($payload['mailer'] ?? $settings->mailer) === 'smtp') {
            $request->validate([
                'host' => ['required', 'string', 'max:255'],
                'fromAddress' => ['required', 'email', 'max:255'],
            ]);
        }

        $settings->fill($payload)->save();
        $this->mail->apply($settings);

        return response()->json([
            'message' => 'Email settings saved.',
            'data' => $this->format($settings->fresh() ?? $settings),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email', 'max:255'],
            'mailer' => ['sometimes', 'string', Rule::in(MailSetting::MAILERS)],
            'enabled' => ['sometimes', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'encryption' => ['nullable', 'string', Rule::in(MailSetting::ENCRYPTIONS)],
            'fromAddress' => ['nullable', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = MailSetting::current();
        if ($request->filled('mailer')) {
            $settings = $settings->exists ? $settings->replicate() : new MailSetting(MailSetting::defaultsFromEnv());
            $settings->fill($this->payloadFromValidated($validated, MailSetting::current()));
        }

        if (! $settings->enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Enable outbound email before sending a test.',
            ], 422);
        }

        $result = $this->mail->test($settings, $validated['to']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'mailer' => ['required', 'string', Rule::in(MailSetting::MAILERS)],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'encryption' => ['nullable', 'string', Rule::in(MailSetting::ENCRYPTIONS)],
            'fromAddress' => ['nullable', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payloadFromValidated(array $validated, MailSetting $existing): array
    {
        $payload = [
            'enabled' => (bool) ($validated['enabled'] ?? $existing->enabled ?? true),
            'mailer' => $validated['mailer'] ?? $existing->mailer ?? 'smtp',
            'host' => $validated['host'] ?? $existing->host,
            'port' => $validated['port'] ?? $existing->port ?? 587,
            'username' => $validated['username'] ?? $existing->username,
            'encryption' => $validated['encryption'] ?? $existing->encryption ?? 'none',
            'from_address' => $validated['fromAddress'] ?? $existing->from_address,
            'from_name' => $validated['fromName'] ?? $existing->from_name,
        ];

        if (filled($validated['password'] ?? null)) {
            $payload['password'] = $validated['password'];
        } elseif ($existing->exists) {
            $payload['password'] = $existing->password;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MailSetting $settings): array
    {
        return [
            'enabled' => (bool) ($settings->enabled ?? true),
            'mailer' => $settings->mailer ?: 'smtp',
            'host' => $settings->host,
            'port' => $settings->port ?: 587,
            'username' => $settings->username,
            'passwordSet' => $settings->hasSecret(),
            'encryption' => $settings->encryption ?: 'none',
            'fromAddress' => $settings->from_address,
            'fromName' => $settings->from_name,
        ];
    }
}
