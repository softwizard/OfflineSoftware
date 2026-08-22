<?php

namespace Tests\Feature\Sync;

use App\Models\Device;
use App\Models\User;

class ProvisionDeviceCommandTest extends CloudTestCase
{
    public function test_provisions_a_device_with_a_hashed_one_time_code(): void
    {
        $this->artisan('sync:device:provision', ['name' => 'Field Laptop', 'device_code' => 'd07'])
            ->assertExitCode(0);

        $device = Device::where('device_code', 'D07')->first();

        $this->assertNotNull($device);
        $this->assertSame('Field Laptop', $device->name);
        $this->assertNotNull($device->provisioning_code_hash);
        $this->assertTrue($device->provisioning_code_expires_at->isFuture());
    }

    public function test_rejects_a_duplicate_device_code(): void
    {
        Device::factory()->create(['device_code' => 'D07']);

        $this->artisan('sync:device:provision', ['name' => 'Another', 'device_code' => 'd07'])
            ->assertExitCode(1);
    }

    public function test_accepts_an_owner_option(): void
    {
        $owner = User::factory()->create();

        $this->artisan('sync:device:provision', [
            'name' => 'Field Laptop',
            'device_code' => 'D07',
            '--owner' => $owner->id,
        ])->assertExitCode(0);

        $this->assertSame($owner->id, Device::where('device_code', 'D07')->first()->owner_user_id);
    }
}
