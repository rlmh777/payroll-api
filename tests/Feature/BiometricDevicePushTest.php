<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricDeviceEvent;
use App\Models\ClockingLog;
use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiometricDevicePushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Carbon::setTestNow('2026-10-11 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_register_a_device_and_token_is_shown_once(): void
    {
        Sanctum::actingAs($this->devicesAdmin());

        $created = $this->postJson('/api/biometric-devices', [
            'code' => 'front-desk',
            'name' => 'Front desk terminal',
            'model' => 'DS-K1T342',
            'timezone' => 'America/Belize',
            'protocol' => 'http_push',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'front-desk')
            ->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.model', 'DS-K1T342');

        $token = $created->json('data.authToken');
        $this->assertNotEmpty($token);

        $this->getJson('/api/biometric-devices')
            ->assertOk()
            ->assertJsonMissingPath('data.0.authToken')
            ->assertJsonPath('data.0.status', 'unknown');
    }

    public function test_unregistered_device_cannot_push_events(): void
    {
        $this->postJson('/api/integrations/biometric/push', [
            'deviceId' => 'unknown',
            'employeeNo' => '100001',
            'eventTime' => '2026-10-11T11:55:00-06:00',
        ], [
            'Authorization' => 'Bearer not-a-real-token',
        ])->assertUnauthorized();
    }

    public function test_inactive_device_is_rejected(): void
    {
        [$device, $token] = $this->provisionDevice(['is_active' => false]);

        $this->postJson('/api/integrations/biometric/register', [
            'deviceId' => $device->code,
            'serialNumber' => 'DSK1T342TEST1',
        ], $this->deviceHeaders($token))
            ->assertForbidden();
    }

    public function test_device_registration_and_heartbeat_show_live_status(): void
    {
        [$device, $token] = $this->provisionDevice();
        Sanctum::actingAs($this->devicesAdmin());

        $this->postJson('/api/integrations/biometric/register', [
            'deviceId' => $device->code,
            'serialNumber' => 'DSK1T342TEST1',
            'firmware' => 'V4.39.180',
        ], $this->deviceHeaders($token))
            ->assertOk()
            ->assertJsonPath('status', 'online');

        $this->postJson('/api/integrations/biometric/heartbeat', [
            'deviceId' => $device->code,
        ], $this->deviceHeaders($token))
            ->assertOk()
            ->assertJsonPath('status', 'online');

        $this->getJson('/api/biometric-devices')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'online')
            ->assertJsonPath('data.0.serialNumber', 'DSK1T342TEST1');

        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        $this->getJson('/api/biometric-devices')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'offline');
    }

    public function test_push_identifies_employee_and_stores_clocking_log(): void
    {
        [$device, $token] = $this->provisionDevice();
        $employee = $this->createEmployee('100011');

        $this->postJson('/api/integrations/biometric/push', [
            'ipAddress' => '10.0.0.64',
            'dateTime' => '2026-10-11T05:54:00-06:00',
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'employeeNoString' => '100011',
                'serialNo' => 4411,
                'attendanceStatus' => 'checkIn',
                'majorEventType' => 5,
                'subEventType' => 75,
            ],
        ], $this->deviceHeaders($token, $device->code))
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('results.0.identified', true)
            ->assertJsonPath('results.0.employeeNo', '100011')
            ->assertJsonPath('results.0.employeeId', $employee->id)
            ->assertJsonPath('results.0.punchType', 'IN');

        $this->assertDatabaseHas('clocking_log', [
            'biometricUserId' => '100011',
            'deviceId' => $device->code,
            'punchType' => 'IN',
        ]);
    }

    public function test_missing_employee_identification_is_rejected(): void
    {
        [$device, $token] = $this->provisionDevice();

        $this->postJson('/api/integrations/biometric/push', [
            'dateTime' => '2026-10-11T05:54:00-06:00',
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'serialNo' => 12,
            ],
        ], $this->deviceHeaders($token, $device->code))
            ->assertUnprocessable()
            ->assertJsonPath('errors.employeeNo.0', 'Employee identification is required.');

        $this->assertSame(0, ClockingLog::query()->count());
    }

    public function test_unknown_employee_is_delivered_but_not_identified(): void
    {
        [$device, $token] = $this->provisionDevice();

        $this->postJson('/api/integrations/attendance/events', [
            'events' => [[
                'event_id' => 'evt-unknown-1',
                'device_id' => $device->code,
                'employee_no' => '999999',
                'event_time' => '2026-10-11T11:50:00+00:00',
            ]],
        ], $this->deviceHeaders($token, $device->code))
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('results.0.identified', false);

        $this->assertDatabaseHas('clocking_log', [
            'biometricUserId' => '999999',
            'deviceId' => $device->code,
        ]);
    }

    public function test_future_timestamps_are_rejected_and_replayed_events_are_accepted(): void
    {
        [$device, $token] = $this->provisionDevice();

        $this->postJson('/api/integrations/biometric/push', [
            'events' => [[
                'event_id' => 'evt-future',
                'employee_no' => '100011',
                'event_time' => Carbon::now()->addHours(2)->toIso8601String(),
            ]],
        ], $this->deviceHeaders($token, $device->code))
            ->assertUnprocessable();

        $this->postJson('/api/integrations/biometric/push', [
            'events' => [[
                'event_id' => 'evt-replay',
                'employee_no' => '100011',
                'event_time' => Carbon::now()->subHours(6)->toIso8601String(),
            ]],
        ], $this->deviceHeaders($token, $device->code))
            ->assertOk()
            ->assertJsonPath('accepted', 1);
    }

    public function test_duplicate_event_delivery_is_idempotent(): void
    {
        [$device, $token] = $this->provisionDevice();
        $payload = [
            'events' => [[
                'event_id' => 'evt-stable-1',
                'employee_no' => '100011',
                'event_time' => '2026-10-11T11:40:00+00:00',
            ]],
        ];

        $this->postJson('/api/integrations/biometric/push', $payload, $this->deviceHeaders($token, $device->code))
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('duplicates', 0);

        $this->postJson('/api/integrations/biometric/push', $payload, $this->deviceHeaders($token, $device->code))
            ->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('duplicates', 1)
            ->assertJsonPath('results.0.duplicate', true);

        $this->assertSame(1, ClockingLog::query()->count());
        $this->assertSame(1, BiometricDeviceEvent::query()->count());
    }

    public function test_network_outage_recovery_accepts_buffered_events_after_reconnect(): void
    {
        [$device, $token] = $this->provisionDevice();
        $device->update([
            'last_seen_at' => Carbon::now()->subMinutes(20),
            'last_heartbeat_at' => Carbon::now()->subMinutes(20),
        ]);

        Sanctum::actingAs($this->devicesAdmin());
        $this->getJson('/api/biometric-devices')->assertJsonPath('data.0.status', 'offline');

        $this->postJson('/api/integrations/biometric/register', [
            'deviceId' => $device->code,
            'serialNumber' => 'DSK1T342TEST1',
        ], $this->deviceHeaders($token))->assertOk()->assertJsonPath('status', 'online');

        $this->postJson('/api/integrations/biometric/push', [
            'events' => [
                [
                    'event_id' => 'buffered-1',
                    'employee_no' => '100011',
                    'event_time' => Carbon::now()->subMinutes(15)->toIso8601String(),
                ],
                [
                    'event_id' => 'buffered-2',
                    'employee_no' => '100011',
                    'event_time' => Carbon::now()->subMinutes(8)->toIso8601String(),
                ],
            ],
        ], $this->deviceHeaders($token, $device->code))
            ->assertOk()
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('status', 'online');

        $this->assertSame(2, ClockingLog::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: BiometricDevice, 1: string}
     */
    private function provisionDevice(array $overrides = []): array
    {
        $token = BiometricDevice::issueToken();
        $device = new BiometricDevice(array_merge([
            'code' => 'front-desk',
            'name' => 'Front desk',
            'model' => 'DS-K1T342',
            'timezone' => 'America/Belize',
            'protocol' => 'http_push',
            'heartbeat_timeout_seconds' => 90,
            'is_active' => true,
        ], $overrides));
        $device->setAuthToken($token);
        $device->save();

        return [$device, $token];
    }

    /**
     * @return array<string, string>
     */
    private function deviceHeaders(string $token, ?string $deviceCode = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
        if ($deviceCode) {
            $headers['X-Device-Id'] = $deviceCode;
        }

        return $headers;
    }

    private function createEmployee(string $code): \App\Modules\Hr\Models\Employee
    {
        $country = Country::query()->create([
            'name' => 'Belize',
            'code1' => 'BZ',
            'code2' => 'BLZ',
            'nationalityName' => 'Belizean',
        ]);
        $district = District::query()->create([
            'name' => 'Cayo',
            'countryId' => $country->id,
        ]);
        $locality = Locality::query()->create([
            'name' => 'San Ignacio',
            'districtId' => $district->id,
        ]);
        $gender = Gender::query()->create(['name' => 'Female']);
        $payment = PaymentMethod::query()->create(['name' => 'Bank transfer']);

        return EmployeePersonSync::create([
            'firstName' => 'Freddy',
            'lastName' => 'Balona',
            'email' => 'fbalona@example.com',
            'phone' => '615-0011',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $locality->id,
            'genderId' => $gender->id,
            'socialSecurityNumber' => '10001111',
            'paymentMethodId' => $payment->id,
            'code' => $code,
        ]);
    }

    private function devicesAdmin(): User
    {
        $view = Permission::query()->firstOrCreate([
            'name' => 'view-biometric-devices',
            'guard_name' => 'web',
        ]);
        $crud = Permission::query()->firstOrCreate([
            'name' => 'biometric-devices-crud',
            'guard_name' => 'web',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo([$view, $crud]);

        return $admin;
    }
}
