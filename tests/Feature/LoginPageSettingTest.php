<?php

namespace Tests\Feature;

use App\Models\LoginPageSetting;
use App\Models\Permission;
use App\Models\User;
use App\Support\ConfiguredStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginPageSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        app(ConfiguredStorage::class)->forgetCachedDisk();
    }

    public function test_login_page_is_public(): void
    {
        $this->getJson('/api/login-page')
            ->assertOk()
            ->assertJsonPath('backgroundMode', 'color')
            ->assertJsonPath('blocks.2.type', 'form');
    }

    public function test_admin_can_update_layout_and_upload_images(): void
    {
        $admin = $this->loginPageAdmin();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->image('bg.jpg', 80, 60);
        $upload = $this->post('/api/login-page-settings/images', [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $imageId = $upload->json('image.id');
        $this->assertNotEmpty($imageId);

        $this->putJson('/api/login-page-settings', [
            'backgroundMode' => 'carousel',
            'backgroundColor' => '#111827',
            'carouselIntervalMs' => 5000,
            'images' => [
                [
                    'id' => $imageId,
                    'path' => $upload->json('image.path'),
                ],
            ],
            'backgroundImageIds' => [$imageId],
            'blocks' => [
                [
                    'id' => 'form-default',
                    'type' => 'form',
                    'x' => 10,
                    'y' => 20,
                    'width' => 40,
                    'height' => 50,
                    'maxWidth' => 360,
                    'text' => '',
                    'align' => 'center',
                ],
                [
                    'id' => 'heading-1',
                    'type' => 'heading',
                    'x' => 12,
                    'y' => 8,
                    'width' => 36,
                    'height' => 10,
                    'text' => 'Welcome back',
                    'fontSize' => 28,
                    'color' => '#ffffff',
                    'align' => 'left',
                    'maxWidth' => 0,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('backgroundMode', 'carousel')
            ->assertJsonPath('blocks.0.maxWidth', 360)
            ->assertJsonPath('blocks.1.maxWidth', 0)
            ->assertJsonPath('blocks.1.text', 'Welcome back');

        $this->assertSame('carousel', LoginPageSetting::current()->layout['backgroundMode']);
    }

    public function test_user_without_permission_cannot_update_login_page(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/login-page-settings')->assertForbidden();
        $this->putJson('/api/login-page-settings', [
            'backgroundMode' => 'color',
            'backgroundColor' => '#000000',
            'blocks' => [
                [
                    'id' => 'form-default',
                    'type' => 'form',
                    'x' => 32,
                    'y' => 28,
                    'width' => 36,
                    'height' => 60,
                ],
            ],
        ])->assertForbidden();
    }

    private function loginPageAdmin(): User
    {
        $view = Permission::query()->firstOrCreate([
            'name' => 'view-login-page',
            'guard_name' => 'web',
        ]);
        $crud = Permission::query()->firstOrCreate([
            'name' => 'login-page-crud',
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
