<?php

namespace Tests\Feature\Sync;

use App\Models\Device;

class AuthenticateDeviceTest extends CloudTestCase
{
    public function test_revoked_device_gets_401_device_revoked(): void
    {
        $device = Device::factory()->revoked()->create();
        $token = $device->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/handshake');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'device_revoked']);
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->postJson('/api/sync/handshake')->assertStatus(401);
    }

    public function test_bogus_token_gets_401(): void
    {
        $this->withToken('not-a-real-token')
            ->postJson('/api/sync/handshake')
            ->assertStatus(401);
    }

    public function test_valid_device_updates_last_seen_at(): void
    {
        $device = Device::factory()->create(['last_seen_at' => null]);
        $token = $device->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/handshake')
            ->assertOk();

        $this->assertNotNull($device->fresh()->last_seen_at);
    }
}
