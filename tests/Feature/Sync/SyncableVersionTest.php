<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;

/**
 * Cloud-side version bumping (App\Sync\Syncable::bootSyncable) — what
 * base_version comparisons check against, so a gap here is a silent
 * conflict-detection gap. Found via S8-03's chaos scenario S05, not by
 * inspection: SoftDeletes::runSoftDelete() persists deleted_at through a
 * direct query builder update(), never through save(), so it fires
 * deleting/deleted but never updating/updated — the version bump lived
 * exclusively in the updating hook, so a delete silently left version
 * completely unchanged.
 */
class SyncableVersionTest extends CloudTestCase
{
    public function test_an_update_bumps_the_version(): void
    {
        $customer = Customer::factory()->create(['owner_user_id' => User::factory()->create()->id]);
        $this->assertSame(1, $customer->version);

        $customer->update(['name' => 'New Name']);

        $this->assertSame(2, $customer->version);
        $this->assertSame(2, $customer->fresh()->version);
    }

    public function test_a_delete_also_bumps_the_version(): void
    {
        $customer = Customer::factory()->create(['owner_user_id' => User::factory()->create()->id]);
        $this->assertSame(1, $customer->version);

        $customer->delete();

        $this->assertSame(2, $customer->version, 'in-memory version must reflect the bump immediately — PushController reads getVersion() right after delete()');
        $this->assertSame(2, Customer::withTrashed()->find($customer->id)->version, 'and it must actually be persisted, not just held in memory');
    }

    public function test_a_delete_still_persists_deleted_at_correctly(): void
    {
        // Regression guard for the fix itself: saveQuietly()-ing the
        // version bump inside the deleting hook must not interfere with
        // SoftDeletes' own subsequent update() call.
        $customer = Customer::factory()->create(['owner_user_id' => User::factory()->create()->id]);

        $customer->delete();

        $this->assertSoftDeleted($customer);
        $this->assertNotNull($customer->fresh()?->deleted_at ?? Customer::withTrashed()->find($customer->id)->deleted_at);
    }

    public function test_a_concurrent_update_after_a_delete_is_detected_as_a_conflict(): void
    {
        // The exact regression: base_version comparisons must be able to
        // tell a delete happened at all.
        $customer = Customer::factory()->create(['owner_user_id' => User::factory()->create()->id]);
        $baseVersion = $customer->version;

        $customer->delete();

        $this->assertNotSame($baseVersion, Customer::withTrashed()->find($customer->id)->version);
    }
}
