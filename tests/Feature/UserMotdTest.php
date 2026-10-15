<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserMotdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_login_claim_returns_a_message_and_daily_claim_is_skipped_the_same_day(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/user/motd', [
            'reason' => 'login',
            'localDate' => '2026-10-12',
        ])
            ->assertOk()
            ->json('message');

        $this->assertIsArray($first);
        $this->assertNotEmpty($first['id']);
        $this->assertNotEmpty($first['text']);

        $this->postJson('/api/user/motd', [
            'reason' => 'daily',
            'localDate' => '2026-10-12',
        ])
            ->assertOk()
            ->assertJsonPath('message', null);
    }

    public function test_login_on_the_same_day_still_returns_a_new_unseen_message(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/user/motd', [
            'reason' => 'login',
            'localDate' => '2026-10-12',
        ])->assertOk()->json('message.id');

        $second = $this->postJson('/api/user/motd', [
            'reason' => 'login',
            'localDate' => '2026-10-12',
        ])->assertOk()->json('message.id');

        $this->assertNotSame($first, $second);
    }

    public function test_daily_claim_returns_a_new_message_on_a_new_day(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/user/motd', [
            'reason' => 'daily',
            'localDate' => '2026-10-12',
        ])->assertOk()->json('message.id');

        $this->postJson('/api/user/motd', [
            'reason' => 'daily',
            'localDate' => '2026-10-12',
        ])->assertOk()->assertJsonPath('message', null);

        $second = $this->postJson('/api/user/motd', [
            'reason' => 'daily',
            'localDate' => '2026-10-13',
        ])->assertOk()->json('message.id');

        $this->assertNotSame($first, $second);
    }

    public function test_recently_shown_messages_are_not_repeated(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->postJson('/api/user/motd', [
                'reason' => 'login',
                'localDate' => '2026-10-12',
            ])->assertOk()->json('message.id');
        }

        $this->assertCount(12, array_unique($ids));
    }

    public function test_guest_cannot_claim_motd(): void
    {
        $this->postJson('/api/user/motd', ['reason' => 'login'])->assertUnauthorized();
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Brent Lewis',
            'username' => 'blewis',
            'email' => 'blewis@example.com',
            'password' => bcrypt('secret'),
        ]);
    }
}
