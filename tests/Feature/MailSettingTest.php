<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_and_test_log_mailer(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/mail-settings')
            ->assertOk()
            ->assertJsonPath('mailer', 'log');

        $this->putJson('/api/mail-settings', [
            'enabled' => true,
            'mailer' => 'log',
            'fromAddress' => 'hr@example.com',
            'fromName' => 'Payroll HR',
        ])
            ->assertOk()
            ->assertJsonPath('data.fromAddress', 'hr@example.com')
            ->assertJsonPath('data.mailer', 'log');

        $this->postJson('/api/mail-settings/test', [
            'to' => 'gm@example.com',
            'mailer' => 'log',
            'enabled' => true,
            'fromAddress' => 'hr@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            return $mail->hasTo('gm@example.com')
                && $mail->emailSubject === 'Test email from payroll';
        });
    }

    public function test_smtp_requires_host_and_from_address(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/mail-settings', [
            'enabled' => true,
            'mailer' => 'smtp',
            'host' => '',
            'fromAddress' => '',
        ])->assertStatus(422);
    }

    private function admin(): User
    {
        $view = Permission::query()->firstOrCreate([
            'name' => 'view-email-settings',
            'guard_name' => 'web',
        ]);
        $write = Permission::query()->firstOrCreate([
            'name' => 'email-settings-crud',
            'guard_name' => 'web',
        ]);

        $user = User::query()->create([
            'name' => 'Admin',
            'username' => 'mail.admin',
            'email' => 'mail-admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo([$view, $write]);

        return $user;
    }
}
