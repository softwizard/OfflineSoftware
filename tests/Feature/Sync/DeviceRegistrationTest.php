<?php

namespace Tests\Feature\Sync;

use App\Models\Device;
use Illuminate\Support\Facades\Hash;

class DeviceRegistrationTest extends CloudTestCase
{
    public function test_valid_provisioning_code_exchanges_for_a_device_token(): void
    {
        $device = Device::factory()->withProvisioningCode('SECRET123')->create();

        $response = $this->postJson('/api/sync/register', [
            'device_code' => $device->device_code,
            'provisioning_code' => 'SECRET123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['device_token', 'device_code', 'schema_version']);
        $this->assertSame($device->device_code, $response->json('device_code'));
    }

    public function test_provisioning_code_is_consumed_after_use(): void
    {
        $device = Device::factory()->withProvisioningCode('SECRET123')->create();

        $this->postJson('/api/sync/register', [
            'device_code' => $device->device_code,
            'provisioning_code' => 'SECRET123',
        ])->assertCreated();

        $this->postJson('/api/sync/register', [
            'device_code' => $device->device_code,
            'provisioning_code' => 'SECRET123',
        ])->assertStatus(422);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $device = Device::factory()->withProvisioningCode('SECRET123')->create();

        $this->postJson('/api/sync/register', [
            'device_code' => $device->device_code,
            'provisioning_code' => 'WRONG',
        ])->assertStatus(422);
    }

    public function test_unknown_device_code_is_rejected(): void
    {
        $this->postJson('/api/sync/register', [
            'device_code' => 'D99',
            'provisioning_code' => 'anything',
        ])->assertStatus(422);
    }

    public function test_expired_code_is_rejected(): void
    {
        $device = Device::factory()->create([
            'provisioning_code_hash' => Hash::make('SECRET123'),
            'provisioning_code_expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/sync/register', [
            'device_code' => $device->device_code,
            'provisioning_code' => 'SECRET123',
        ])->assertStatus(422);
    }

    public function test_issued_token_authenticates_subsequent_sync_requests(): void
    {
        $device = Device::factory()->withProvisioningCode('SECRET123')->create();

        $token = $this->postJson('/api/sync/register', [
            'device_code' => $device->device_code,
            'provisioning_code' => 'SECRET123',
        ])->json('device_token');

        $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/handshake')
            ->assertOk();
    }
}
