<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationLogTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(storage_path('logs'), 0755, true);
        $this->logPath = storage_path('logs/laravel-2099-01-01.log');
        file_put_contents(
            $this->logPath,
            "#12 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): leftover\n".
            "[2026-10-13 12:00:00] testing.INFO: ready\n".
            "[2026-10-13 12:01:00] testing.ERROR: boom\nStack trace:\n#0 app.php\n".
            "[2026-10-13 12:02:00] testing.WARNING: slow query\n",
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function test_admin_can_read_laravel_log_entries(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/application-logs?file=laravel-2099-01-01.log&lines=200')
            ->assertOk()
            ->assertJsonPath('file', 'laravel-2099-01-01.log')
            ->assertJsonPath('entries.0.level', 'info')
            ->assertJsonPath('entries.1.level', 'error')
            ->assertJsonPath('entries.1.message', "boom\nStack trace:\n#0 app.php")
            ->assertJsonFragment(['name' => 'laravel-2099-01-01.log']);
    }

    public function test_admin_can_filter_log_entries_by_level_and_search(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/application-logs?file=laravel-2099-01-01.log&level=error&search=boom')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.level', 'error');
    }

    public function test_admin_can_clear_a_log_file(): void
    {
        Mail::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/application-logs?file=laravel-2099-01-01.log')
            ->assertStatus(422);

        $challenge = $this->postJson('/api/sensitive-actions/challenge', [
            'action' => 'application_log_clear',
            'context' => ['file' => 'laravel-2099-01-01.log'],
        ])->assertOk();

        $code = $this->codeFromMail($admin->email);

        $this->deleteJson('/api/application-logs?file=laravel-2099-01-01.log', [
            'file' => 'laravel-2099-01-01.log',
            'challenge_key' => $challenge->json('challenge_key'),
            'confirmation_code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('cleared.0', 'laravel-2099-01-01.log');

        $this->assertSame('', file_get_contents($this->logPath));
    }

    public function test_view_permission_cannot_clear_logs(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/application-logs?file=laravel-2099-01-01.log')->assertOk();
        $this->deleteJson('/api/application-logs?file=laravel-2099-01-01.log')->assertForbidden();
        $this->assertNotSame('', file_get_contents($this->logPath));
    }

    public function test_path_traversal_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/application-logs?file=../.env')->assertNotFound();
        $this->deleteJson('/api/application-logs?file=../../.env')->assertStatus(422);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/application-logs')->assertUnauthorized();
        $this->deleteJson('/api/application-logs')->assertUnauthorized();
    }

    private function admin(): User
    {
        return $this->userWithPermissions([
            'view-application-logs',
            'application-logs-crud',
        ]);
    }

    private function viewer(): User
    {
        return $this->userWithPermissions(['view-application-logs']);
    }

    /**
     * @param  list<string>  $names
     */
    private function userWithPermissions(array $names): User
    {
        $permissions = [];
        foreach ($names as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $user = User::query()->create([
            'name' => 'Log Admin',
            'username' => 'log.admin.'.uniqid(),
            'email' => uniqid('logs-').'@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function codeFromMail(string $to): string
    {
        $code = null;
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($to, &$code) {
            if (! $mail->hasTo($to)) {
                return false;
            }
            if (preg_match('/>(\d{6})</', $mail->htmlBody, $matches) === 1) {
                $code = $matches[1];
            }

            return true;
        });

        $this->assertNotNull($code);

        return (string) $code;
    }
}
