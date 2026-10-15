<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ConfiguredStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfilePictureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        app(ConfiguredStorage::class)->forgetCachedDisk();
    }

    public function test_authenticated_user_can_upload_and_remove_profile_picture(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('avatar.jpg', 80, 80);

        $response = $this->post('/api/user/picture', [
            'picture' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('id'));
        $url = $response->json('pictureUrl');
        $this->assertIsString($url);
        $this->assertStringContainsString('/storage/users/pictures/', $url);

        $user->refresh();
        $this->assertNotNull($user->picture_path);
        $this->assertTrue(app(ConfiguredStorage::class)->exists($user->picture_path));

        $this->deleteJson('/api/user/picture')
            ->assertOk()
            ->assertJsonPath('pictureUrl', null);

        $this->assertNull($user->fresh()->picture_path);
    }

    public function test_picture_upload_requires_an_image(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/picture', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['picture']);
    }

    public function test_unauthenticated_users_cannot_upload_picture(): void
    {
        $this->postJson('/api/user/picture')
            ->assertUnauthorized();
    }
}
