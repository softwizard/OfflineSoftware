<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserUuidTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_id_is_a_uuid_v7(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertSame(7, (int) $user->id[14]);
    }

    public function test_users_table_primary_key_is_not_auto_incrementing(): void
    {
        $columns = Schema::getColumns('users');
        $idColumn = collect($columns)->firstWhere('name', 'id');

        $this->assertNotNull($idColumn);
        $this->assertFalse($idColumn['auto_increment']);
    }

    public function test_sessions_and_passkeys_foreign_keys_are_not_bigint(): void
    {
        $sessionsUserId = collect(Schema::getColumns('sessions'))->firstWhere('name', 'user_id');
        $passkeysUserId = collect(Schema::getColumns('passkeys'))->firstWhere('name', 'user_id');

        $this->assertStringNotContainsStringIgnoringCase('int', $sessionsUserId['type_name']);
        $this->assertStringNotContainsStringIgnoringCase('int', $passkeysUserId['type_name']);
    }
}
