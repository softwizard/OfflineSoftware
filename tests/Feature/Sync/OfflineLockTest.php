<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\OfflineLock;
use App\Sync\OfflineLockException;
use App\Sync\RemoteApplier;
use App\Sync\Sync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineLockTest extends TestCase
{
    use RefreshDatabase;

    private function setLastSuccessDaysAgo(?int $days): void
    {
        DB::table('sync_state')->where('id', 1)->update([
            'last_success_at' => $days === null ? null : now()->subDays($days),
        ]);
    }

    public function test_a_device_that_has_never_synced_is_not_locked(): void
    {
        $this->setLastSuccessDaysAgo(null);

        $status = app(OfflineLock::class)->status();

        $this->assertFalse($status['locked']);
        $this->assertFalse($status['warning']);
    }

    public function test_no_warning_before_day_10(): void
    {
        $this->setLastSuccessDaysAgo(9);

        $status = app(OfflineLock::class)->status();

        $this->assertFalse($status['warning']);
        $this->assertFalse($status['locked']);
    }

    public function test_warns_starting_day_10(): void
    {
        $this->setLastSuccessDaysAgo(10);

        $status = app(OfflineLock::class)->status();

        $this->assertTrue($status['warning']);
        $this->assertFalse($status['locked']);
        $this->assertSame(4, $status['days_remaining']);
    }

    public function test_locks_starting_day_14(): void
    {
        $this->setLastSuccessDaysAgo(14);

        $status = app(OfflineLock::class)->status();

        $this->assertTrue($status['locked']);
        $this->assertSame(0, $status['days_remaining']);
    }

    public function test_create_is_blocked_while_locked(): void
    {
        $this->setLastSuccessDaysAgo(14);

        $this->expectException(OfflineLockException::class);

        Customer::factory()->create();
    }

    public function test_update_is_blocked_while_locked(): void
    {
        $customer = Customer::factory()->create();
        $this->setLastSuccessDaysAgo(14);

        $this->expectException(OfflineLockException::class);

        $customer->update(['name' => 'New Name']);
    }

    public function test_delete_is_blocked_while_locked(): void
    {
        $customer = Customer::factory()->create();
        $this->setLastSuccessDaysAgo(14);

        $this->expectException(OfflineLockException::class);

        $customer->delete();
    }

    public function test_writes_succeed_normally_before_the_lock_kicks_in(): void
    {
        $this->setLastSuccessDaysAgo(13);

        $customer = Customer::factory()->create();

        $this->assertNotNull($customer->fresh());
    }

    public function test_a_remote_apply_is_never_blocked_even_while_locked(): void
    {
        $owner = User::factory()->create();
        $this->setLastSuccessDaysAgo(20); // deep past the lock threshold

        // This is exactly what recovery looks like: PullRunner applying
        // the backlog through RemoteApplier (always inside
        // Sync::withoutRecording) is what un-locks the device in the
        // first place — it must never be blocked by the very lock it's
        // about to lift.
        app(RemoteApplier::class)->applyChange('customer', (string) Str::uuid7(), 'create', [
            'name' => 'Recovered',
            'owner_user_id' => $owner->id,
        ], 1);

        $this->assertSame(1, Customer::count());
    }

    public function test_seeding_via_withoutrecording_is_never_blocked_even_while_locked(): void
    {
        $this->setLastSuccessDaysAgo(20);

        Sync::withoutRecording(function () {
            Customer::factory()->create();
        });

        $this->assertSame(1, Customer::count());
    }
}
