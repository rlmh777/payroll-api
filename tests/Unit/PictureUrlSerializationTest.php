<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\Core\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PictureUrlSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_to_array_includes_picture_url_without_throwing(): void
    {
        $person = new Person(['picturePath' => null]);

        $array = $person->toArray();

        $this->assertArrayHasKey('pictureUrl', $array);
        $this->assertNull($array['pictureUrl']);
    }

    public function test_user_to_array_includes_picture_url_without_throwing(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'username' => 'alovelace',
            'email' => 'ada@example.com',
            'password' => bcrypt('secret'),
        ]);

        $array = $user->toArray();

        $this->assertArrayHasKey('pictureUrl', $array);
        $this->assertNull($array['pictureUrl']);
    }
}
