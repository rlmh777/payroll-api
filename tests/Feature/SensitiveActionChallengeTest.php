<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\DatabaseBackup;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Core\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SensitiveActionChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_requires_a_valid_email_code(): void
    {
        Mail::fake();
        $this->mock(DatabaseBackupService::class, function ($mock) {
            $mock->shouldReceive('restore')->once();
        });

        $admin = $this->admin(['database-backup-crud']);
        Sanctum::actingAs($admin);
        $backup = $this->backup();

        $this->postJson("/api/database-backups/{$backup->id}/restore", [
            'confirmation' => 'RESTORE',
        ])->assertStatus(422);

        $challenge = $this->postJson('/api/sensitive-actions/challenge', [
            'action' => 'database_restore',
            'context' => ['source' => 'backup', 'backup_id' => $backup->id],
        ])->assertOk();

        $this->postJson("/api/database-backups/{$backup->id}/restore", [
            'challenge_key' => $challenge->json('challenge_key'),
            'confirmation_code' => '000000',
        ])->assertStatus(400);

        $this->postJson("/api/database-backups/{$backup->id}/restore", [
            'challenge_key' => $challenge->json('challenge_key'),
            'confirmation_code' => $this->codeFromMail($admin->email),
        ])->assertOk();
    }

    public function test_restore_code_cannot_be_reused_on_another_backup(): void
    {
        Mail::fake();
        $admin = $this->admin(['database-backup-crud']);
        Sanctum::actingAs($admin);
        $backup = $this->backup();
        $other = $this->backup();

        $challenge = $this->postJson('/api/sensitive-actions/challenge', [
            'action' => 'database_restore',
            'context' => ['source' => 'backup', 'backup_id' => $backup->id],
        ])->assertOk();

        $this->postJson("/api/database-backups/{$other->id}/restore", [
            'challenge_key' => $challenge->json('challenge_key'),
            'confirmation_code' => $this->codeFromMail($admin->email),
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Confirmation code does not match this action. Request a new code.');
    }

    public function test_upload_restore_requires_an_email_code(): void
    {
        Mail::fake();
        $admin = $this->admin(['database-backup-crud']);
        Sanctum::actingAs($admin);

        $challenge = $this->postJson('/api/sensitive-actions/challenge', [
            'action' => 'database_restore',
            'context' => ['source' => 'upload'],
        ])->assertOk();

        $this->mock(DatabaseBackupService::class, function ($mock) {
            $mock->shouldReceive('restoreUpload')->once()->andReturn(new DatabaseBackup([
                'id' => '11111111-1111-1111-1111-111111111111',
                'filename' => 'upload.dump',
                'status' => 'completed',
            ]));
        });

        $this->post('/api/database-backups/restore-upload', [
            'challenge_key' => $challenge->json('challenge_key'),
            'confirmation_code' => $this->codeFromMail($admin->email),
            'file' => UploadedFile::fake()->create('backup.dump', 10),
        ])->assertOk();
    }

    public function test_user_without_permission_cannot_request_a_code(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin(['view-database-backup']));

        $this->postJson('/api/sensitive-actions/challenge', [
            'action' => 'database_restore',
            'context' => ['source' => 'upload'],
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $names
     */
    private function admin(array $names): User
    {
        $permissions = [];
        foreach ($names as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $user = User::query()->create([
            'name' => 'Ops Admin',
            'username' => 'ops.admin.'.uniqid(),
            'email' => uniqid('ops-').'@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function backup(): DatabaseBackup
    {
        return DatabaseBackup::query()->create([
            'filename' => 'backup-'.uniqid().'.dump',
            'disk' => 'local',
            'path' => 'database-backups/example.dump',
            'type' => 'manual',
            'status' => 'completed',
            'size_bytes' => 12,
            'completed_at' => now(),
        ]);
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
