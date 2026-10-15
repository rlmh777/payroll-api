<?php

namespace Tests\Unit\Services;

use App\Models\AuthSetting;
use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payroll.employee_login_domain' => 'example.com']);
    }

    public function test_default_pattern_is_first_dot_last(): void
    {
        $generator = app(UsernameGenerator::class);

        $this->assertSame('john.doe', $generator->buildUsername('John', 'Doe'));
        $this->assertSame('john.m.doe', $generator->buildUsername('John', 'Doe', 'Michael'));
    }

    public function test_last_first_pattern_and_underscore_separator(): void
    {
        $this->saveRules([
            'username_patterns' => [UsernameGenerator::PATTERN_LAST_FIRST],
            'username_pattern' => UsernameGenerator::PATTERN_LAST_FIRST,
            'username_separator' => '_',
            'username_include_middle_initial' => true,
        ]);

        $generator = new UsernameGenerator(AuthSetting::current());

        $this->assertSame('doe_john', $generator->buildUsername('John', 'Doe'));
        $this->assertSame('doe_m_john', $generator->buildUsername('John', 'Doe', 'Michael'));
    }

    public function test_first_initial_last_without_separator(): void
    {
        $this->saveRules([
            'username_patterns' => [UsernameGenerator::PATTERN_FIRST_INITIAL_LAST],
            'username_pattern' => UsernameGenerator::PATTERN_FIRST_INITIAL_LAST,
            'username_separator' => '',
            'username_include_middle_initial' => false,
        ]);

        $generator = new UsernameGenerator(AuthSetting::current());

        $this->assertSame('jdoe', $generator->buildUsername('John', 'Doe', 'Michael'));
    }

    public function test_firstlast_pattern_includes_middle_initial_when_enabled(): void
    {
        $this->saveRules([
            'username_patterns' => [UsernameGenerator::PATTERN_FIRSTLAST],
            'username_pattern' => UsernameGenerator::PATTERN_FIRSTLAST,
            'username_separator' => '.',
            'username_include_middle_initial' => true,
        ]);

        $generator = new UsernameGenerator(AuthSetting::current());

        $this->assertSame('johndoe', $generator->buildUsername('John', 'Doe'));
        $this->assertSame('johnmdoe', $generator->buildUsername('John', 'Doe', 'Michael'));
    }

    public function test_it_uses_configured_login_domain(): void
    {
        $this->saveRules([
            'employee_login_domain' => 'chaacreek.com',
        ]);

        $generator = new UsernameGenerator(AuthSetting::current());
        $credentials = $generator->generateUniqueLoginCredentials('Jane', 'Smith');

        $this->assertSame('jane.smith', $credentials['username']);
        $this->assertSame('jane.smith@chaacreek.com', $credentials['email']);
    }

    public function test_unique_credentials_skip_taken_usernames(): void
    {
        User::query()->create([
            'name' => 'Existing',
            'username' => 'john.doe',
            'email' => 'other@example.com',
            'password' => bcrypt('secret'),
        ]);

        $generator = app(UsernameGenerator::class);
        $credentials = $generator->generateUniqueLoginCredentials('John', 'Doe', 'Michael');

        $this->assertSame('john.m.doe', $credentials['username']);
        $this->assertSame('john.m.doe@example.com', $credentials['email']);
    }

    public function test_it_tries_selected_patterns_in_priority_order(): void
    {
        $this->saveRules([
            'username_patterns' => [
                UsernameGenerator::PATTERN_FIRST_LAST,
                UsernameGenerator::PATTERN_LAST_FIRST,
                UsernameGenerator::PATTERN_FIRST_INITIAL_LAST,
            ],
            'username_include_middle_initial' => false,
        ]);

        User::query()->create([
            'name' => 'Existing',
            'username' => 'john.doe',
            'email' => 'existing@example.com',
            'password' => bcrypt('secret'),
        ]);

        $generator = new UsernameGenerator(AuthSetting::current());
        $credentials = $generator->generateUniqueLoginCredentials('John', 'Doe');

        $this->assertSame('doe.john', $credentials['username']);
        $this->assertSame(
            ['john.doe', 'doe.john', 'j.doe'],
            $generator->previewCandidates('John', 'Doe'),
        );
    }

    private function saveRules(array $overrides): void
    {
        $settings = AuthSetting::current();
        $settings->fill($overrides);
        $settings->save();
    }
}
