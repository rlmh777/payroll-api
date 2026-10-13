<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use App\Models\BiometricDeviceEvent;
use App\Models\ClockingLog;
use App\Models\Employee;
use App\Modules\Hr\Services\Attendance\TimesheetProcessingScheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

class BiometricPushService
{
    public function __construct(
        private readonly TimesheetProcessingScheduler $timesheetProcessingScheduler,
    ) {}

    /**
     * @return array{device: BiometricDevice, token: string}
     */
    public function authenticate(Request $request, ?string $deviceCode = null): array
    {
        $token = $this->extractToken($request);
        if ($token === '') {
            throw ValidationException::withMessages([
                'token' => 'Device token is required.',
            ])->status(401);
        }

        $code = $deviceCode
            ?? $this->string(
                $request->header('X-Device-Id')
                ?? $request->input('deviceId')
                ?? $request->input('device_id')
                ?? $request->input('deviceCode'),
            );
        $serial = $this->string($request->input('serialNumber') ?? $request->input('serial_number'));

        $device = null;
        if ($code !== '') {
            $device = BiometricDevice::query()->where('code', $code)->first();
        }
        if (! $device && $serial !== '') {
            $device = BiometricDevice::query()->where('serial_number', $serial)->first();
        }
        if (! $device) {
            $prefix = substr($token, 0, 8);
            $device = BiometricDevice::query()
                ->where('auth_token_prefix', $prefix)
                ->get()
                ->first(fn (BiometricDevice $candidate) => $candidate->tokenMatches($token));
        }

        if (! $device || ! $device->tokenMatches($token)) {
            throw ValidationException::withMessages([
                'token' => 'Device is not registered.',
            ])->status(401);
        }

        if (! $device->is_active) {
            $device->recordError('Push rejected: device is inactive.');
            throw ValidationException::withMessages([
                'deviceId' => 'Device is inactive.',
            ])->status(403);
        }

        return ['device' => $device, 'token' => $token];
    }

    /**
     * @return array<string, mixed>
     */
    public function register(Request $request): array
    {
        ['device' => $device] = $this->authenticate($request);
        $serial = $this->string($request->input('serialNumber') ?? $request->input('serial_number'));
        $firmware = $this->string($request->input('firmware'));
        $ip = $this->clientIp($request);

        if ($serial !== '') {
            $taken = BiometricDevice::query()
                ->where('serial_number', $serial)
                ->where('id', '!=', $device->id)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'serialNumber' => 'Serial number is already assigned to another device.',
                ]);
            }
            $device->serial_number = $serial;
        }
        if ($firmware !== '') {
            $device->firmware = $firmware;
        }

        $device->markSeen($ip, heartbeat: true, registered: true);

        return [
            'message' => 'Device registered.',
            'status' => $device->liveStatus(),
            'deviceId' => $device->code,
            'registeredAt' => $device->registered_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(Request $request): array
    {
        ['device' => $device] = $this->authenticate($request);
        $device->markSeen($this->clientIp($request), heartbeat: true, registered: true);

        return [
            'message' => 'Heartbeat accepted.',
            'status' => $device->liveStatus(),
            'deviceId' => $device->code,
            'serverTime' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ingest(Request $request): array
    {
        $payload = $this->payload($request);
        ['device' => $device] = $this->authenticate($request, $this->payloadDeviceCode($payload));

        if ($this->isHeartbeatPayload($payload)) {
            $device->markSeen($this->clientIp($request), heartbeat: true, registered: true);

            return [
                'message' => 'Heartbeat accepted.',
                'status' => $device->liveStatus(),
                'accepted' => 0,
                'duplicates' => 0,
                'results' => [],
            ];
        }

        $events = $this->extractEvents($payload);
        if ($events === []) {
            $device->markSeen($this->clientIp($request), heartbeat: true, registered: true);

            return [
                'message' => 'No attendance events in payload.',
                'status' => $device->liveStatus(),
                'accepted' => 0,
                'duplicates' => 0,
                'results' => [],
            ];
        }

        $results = [];
        $clockingRows = [];

        DB::transaction(function () use ($device, $events, &$results, &$clockingRows) {
            foreach ($events as $event) {
                $result = $this->acceptEvent($device, $event);
                $results[] = $result;
                if (! empty($result['clockingLog']) && empty($result['duplicate'])) {
                    $clockingRows[] = [
                        'punchDateTime' => $result['eventTime'],
                    ];
                }
            }
        });

        $accepted = count(array_filter($results, fn (array $row) => empty($row['duplicate'])));
        $duplicates = count($results) - $accepted;

        $device->markSeen($this->clientIp($request), registered: true);
        if ($accepted > 0) {
            $device->markEvent();
            $this->timesheetProcessingScheduler->queueAfterIngest($accepted, $clockingRows);
        }

        return [
            'message' => 'Events accepted.',
            'status' => $device->fresh()?->liveStatus() ?? $device->liveStatus(),
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function acceptEvent(BiometricDevice $device, array $event): array
    {
        $clean = $this->stripBiometrics($event);
        $access = $this->accessControllerEvent($clean);
        $employeeNo = $this->employeeNo($clean, $access);
        if ($employeeNo === '') {
            throw ValidationException::withMessages([
                'employeeNo' => 'Employee identification is required.',
            ]);
        }

        $eventTime = $this->eventTime($clean, $access, $device->timezone);
        $eventId = $this->eventId($device->code, $clean, $access, $employeeNo, $eventTime);
        $existing = BiometricDeviceEvent::query()->where('event_id', $eventId)->first();
        if ($existing) {
            return $this->presentStored($existing, duplicate: true);
        }

        $employee = $this->identifyEmployee($employeeNo);
        $punchType = $this->punchType($clean, $access);

        $clocking = ClockingLog::query()->firstOrCreate(
            [
                'biometricUserId' => $employeeNo,
                'deviceId' => $device->code,
                'punchDateTime' => $eventTime->format('Y-m-d H:i:s'),
            ],
            [
                'punchType' => $punchType,
            ],
        );

        $stored = BiometricDeviceEvent::query()->create([
            'event_id' => $eventId,
            'biometric_device_id' => $device->id,
            'employee_no' => $employeeNo,
            'employee_id' => $employee?->id,
            'identified' => $employee !== null,
            'event_time' => $eventTime,
            'punch_type' => $punchType,
            'clocking_log_id' => $clocking->id,
            'duplicate' => false,
            'raw_payload' => $clean,
            'received_at' => Carbon::now(),
        ]);

        return $this->presentStored($stored, duplicate: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStored(BiometricDeviceEvent $stored, bool $duplicate): array
    {
        return [
            'eventId' => $stored->event_id,
            'employeeNo' => $stored->employee_no,
            'employeeId' => $stored->employee_id,
            'identified' => (bool) $stored->identified,
            'eventTime' => $stored->event_time?->toIso8601String(),
            'punchType' => $stored->punch_type,
            'clockingLog' => $stored->clocking_log_id,
            'duplicate' => $duplicate,
        ];
    }

    private function identifyEmployee(string $employeeNo): ?Employee
    {
        return Employee::query()
            ->where('code', $employeeNo)
            ->orWhere('internalId1', $employeeNo)
            ->orWhere('internalId2', $employeeNo)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $access
     */
    private function eventTime(array $event, array $access, string $timezone): Carbon
    {
        $raw = $this->string(
            $event['event_time']
            ?? $event['eventTime']
            ?? $event['dateTime']
            ?? $event['time']
            ?? $access['time']
            ?? $access['dateTime']
            ?? null,
        );
        if ($raw === '') {
            throw ValidationException::withMessages([
                'eventTime' => 'Event timestamp is required.',
            ]);
        }

        try {
            $parsed = Carbon::parse(str_replace('Z', '+00:00', $raw), $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'eventTime' => 'Event timestamp is invalid.',
            ]);
        }

        if ($parsed->tzName === 'UTC' && ! preg_match('/Z|[+-]\d{2}:?\d{2}$/', $raw)) {
            $parsed = Carbon::parse($raw, $timezone);
        }

        $utc = $parsed->clone()->utc();
        $now = Carbon::now('UTC');
        $futureSkew = max(0, (int) config('biometric.max_future_skew_seconds', 900));
        if ($utc->gt($now->copy()->addSeconds($futureSkew))) {
            throw ValidationException::withMessages([
                'eventTime' => 'Event timestamp is in the future.',
            ]);
        }

        $maxAgeDays = max(1, (int) config('biometric.max_event_age_days', 30));
        if ($utc->lt($now->copy()->subDays($maxAgeDays))) {
            throw ValidationException::withMessages([
                'eventTime' => 'Event timestamp is older than the recovery window.',
            ]);
        }

        return $utc;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $access
     */
    private function eventId(string $deviceCode, array $event, array $access, string $employeeNo, Carbon $eventTime): string
    {
        $provided = $this->string($event['event_id'] ?? $event['eventId'] ?? null);
        if ($provided !== '') {
            return $provided;
        }

        $serial = $event['serialNo'] ?? $event['eventSerialNo'] ?? $access['serialNo'] ?? $access['eventSerialNo'] ?? null;
        if ($serial !== null && $serial !== '') {
            return hash('sha256', $deviceCode.'|serial|'.$serial);
        }

        $canonical = json_encode([
            'device' => $deviceCode,
            'employee' => $employeeNo,
            'time' => $eventTime->toIso8601String(),
            'major' => $access['majorEventType'] ?? $access['major'] ?? $event['major'] ?? null,
            'minor' => $access['subEventType'] ?? $access['minor'] ?? $event['minor'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $canonical);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $access
     */
    private function employeeNo(array $event, array $access): string
    {
        return $this->string(
            $event['employee_no']
            ?? $event['employeeNo']
            ?? $event['employeeNoString']
            ?? $access['employeeNoString']
            ?? $access['employeeNo']
            ?? $access['employeeNoString']
            ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $access
     */
    private function punchType(array $event, array $access): ?string
    {
        $raw = strtolower($this->string(
            $event['punchType']
            ?? $event['punch_type']
            ?? $event['attendanceStatus']
            ?? $access['attendanceStatus']
            ?? null,
        ));
        if (in_array($raw, ['in', 'checkin', 'check-in', 'breakin', 'break_in', 'overtimein', 'overtime_in'], true)) {
            return 'IN';
        }
        if (in_array($raw, ['out', 'checkout', 'check-out', 'breakout', 'break_out', 'overtimeout', 'overtime_out'], true)) {
            return 'OUT';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractEvents(array $payload): array
    {
        if (isset($payload['events']) && is_array($payload['events'])) {
            return array_values(array_filter($payload['events'], 'is_array'));
        }

        $alert = $payload['EventNotificationAlert'] ?? null;
        if (is_array($alert)) {
            $payload = $alert;
        }

        $acs = $payload['AcsEvent'] ?? null;
        if (is_array($acs)) {
            $list = $acs['InfoList'] ?? [];
            if (isset($list['time']) || isset($list['employeeNoString'])) {
                $list = [$list];
            }
            if (is_array($list)) {
                return array_values(array_filter($list, 'is_array'));
            }
        }

        if (isset($payload['AccessControllerEvent']) || ($payload['eventType'] ?? '') === 'AccessControllerEvent') {
            return [$payload];
        }

        if (isset($payload['employee_no']) || isset($payload['employeeNo']) || isset($payload['employeeNoString'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isHeartbeatPayload(array $payload): bool
    {
        $type = strtolower($this->string($payload['eventType'] ?? $payload['EventNotificationAlert']['eventType'] ?? null));

        return in_array($type, ['heartbeat', 'keepalive', 'keep-alive'], true)
            || isset($payload['heartbeat'])
            || ($payload['eventDescription'] ?? '') === 'heartbeat';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));
        $raw = (string) $request->getContent();
        if ($raw !== '' && (str_contains($contentType, 'xml') || str_starts_with(ltrim($raw), '<'))) {
            $parsed = $this->xmlToArray($raw);
            if ($parsed !== []) {
                return $parsed;
            }
        }

        $json = $request->json()->all();
        if ($json !== []) {
            return $json;
        }

        return $request->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadDeviceCode(array $payload): ?string
    {
        $code = $this->string(
            $payload['deviceId']
            ?? $payload['device_id']
            ?? $payload['deviceCode']
            ?? $payload['AccessControllerEvent']['deviceName']
            ?? null,
        );

        return $code !== '' ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function accessControllerEvent(array $event): array
    {
        $access = $event['AccessControllerEvent'] ?? [];

        return is_array($access) ? $access : [];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function stripBiometrics(array $event): array
    {
        $blocked = ['pictures', 'picture', 'facesnapshot', 'faceimage', 'picdata', 'picurl', 'FaceRect'];

        return array_filter(
            $event,
            fn ($key) => ! in_array(strtolower((string) $key), $blocked, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function xmlToArray(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($element === false) {
                return [];
            }

            $decoded = json_decode(json_encode($element), true);

            return is_array($decoded) ? $decoded : [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function extractToken(Request $request): string
    {
        $bearer = $this->string($request->bearerToken());
        if ($bearer !== '') {
            return $bearer;
        }

        return $this->string(
            $request->header('X-Device-Token')
            ?? $request->query('token')
            ?? $request->input('token')
            ?? $request->input('authToken')
            ?? $request->input('password'),
        );
    }

    private function clientIp(Request $request): ?string
    {
        $ip = $request->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private function string(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
