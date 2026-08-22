<?php

namespace Tests\Feature\Sync;

use App\Models\Device;
use Illuminate\Console\Command;

class RevokeDeviceCommandTest extends CloudTestCase
{
    public function test_revokes_a_device(): void
    {
        $device = Device::factory()->create();

        $this->artisan('sync:device:revoke', ['device_code' => $device->device_code])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_does_not_delete_the_devices_token(): void
    {
        // RevokeDevice's own docblock explains why: the device needs to
        // authenticate exactly one more request to learn it's revoked.
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $this->artisan('sync:device:revoke', ['device_code' => $device->device_code]);

        $response = $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/handshake');

        $response->assertStatus(401)->assertJson(['error' => 'device_revoked']);
    }

    public function test_is_idempotent_on_an_already_revoked_device(): void
    {
        $device = Device::factory()->revoked()->create();

        $this->artisan('sync:device:revoke', ['device_code' => $device->device_code])
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_fails_for_an_unknown_device_code(): void
    {
        $this->artisan('sync:device:revoke', ['device_code' => 'D99'])
            ->assertExitCode(Command::FAILURE);
    }

    public function test_refuses_to_run_in_local_mode(): void
    {
        config(['sync.mode' => 'local']);

        $this->artisan('sync:device:revoke', ['device_code' => 'D01'])
            ->assertExitCode(Command::FAILURE);
    }
}
