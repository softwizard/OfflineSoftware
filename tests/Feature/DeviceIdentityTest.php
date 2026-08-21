<?php

namespace Tests\Feature;

use App\Providers\SyncServiceProvider;
use App\Sync\DeviceIdentity;
use RuntimeException;
use Tests\TestCase;

class DeviceIdentityTest extends TestCase
{
    public function test_sync_mode_helpers_reflect_config(): void
    {
        config(['sync.mode' => 'local']);
        $this->assertTrue(sync_is_local());
        $this->assertFalse(sync_is_cloud());

        config(['sync.mode' => 'cloud']);
        $this->assertFalse(sync_is_local());
        $this->assertTrue(sync_is_cloud());
    }

    public function test_device_identity_returns_the_configured_id(): void
    {
        config(['sync.device_id' => 'D07']);

        $this->assertSame('D07', DeviceIdentity::id());
    }

    public function test_device_identity_throws_when_unset(): void
    {
        config(['sync.device_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SYNC_DEVICE_ID is not set');

        DeviceIdentity::id();
    }

    public function test_local_boot_fails_loudly_without_a_device_id(): void
    {
        config(['sync.mode' => 'local', 'sync.device_id' => null]);

        $this->expectException(RuntimeException::class);

        (new SyncServiceProvider($this->app))->boot();
    }

    public function test_cloud_boot_does_not_require_a_device_id(): void
    {
        config(['sync.mode' => 'cloud', 'sync.device_id' => null]);

        (new SyncServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }
}
