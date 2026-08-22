<?php

namespace Tests\Feature\Sync;

use App\Models\Device;

class EnforceSchemaVersionTest extends CloudTestCase
{
    public function test_v1_client_against_server_requiring_v5_gets_409_schema_outdated(): void
    {
        config(['sync.min_supported_schema_version' => 5]);

        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', '1')
            ->postJson('/api/sync/handshake');

        $response->assertStatus(409);
        $response->assertJson(['error' => 'schema_outdated']);
    }

    public function test_missing_header_is_treated_as_outdated(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/sync/handshake')
            ->assertStatus(409);
    }

    public function test_matching_version_passes(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/handshake')
            ->assertOk();
    }
}
