<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CloudBootSmokeTest extends CloudTestCase
{
    public function test_a_customer_can_be_created_directly_in_cloud_mode(): void
    {
        // Regression: Customer::booted() used to always call CodeGenerator,
        // which needs DeviceIdentity::id() — that throws in cloud mode,
        // since there's no device server-side. Any direct create (not
        // through push) would have crashed.
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertNotNull($customer->code);
    }

    public function test_cloud_migrations_are_loaded(): void
    {
        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('sync_sequence'));
        $this->assertTrue(Schema::hasTable('sync_log'));
        $this->assertTrue(Schema::hasTable('sync_receipts'));
        $this->assertTrue(Schema::hasTable('sync_conflicts'));
        $this->assertFalse(Schema::hasTable('sync_changes'), 'sync_changes is local-only');
        $this->assertFalse(Schema::hasTable('code_sequences'), 'code_sequences is local-only');
    }

    public function test_sync_sequence_seeded_with_exactly_one_row(): void
    {
        $rows = DB::table('sync_sequence')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows->first()->id);
        $this->assertSame(0, (int) $rows->first()->value);
    }

    public function test_app_is_actually_in_cloud_mode(): void
    {
        $this->assertTrue(sync_is_cloud());
        $this->assertFalse(sync_is_local());
    }

    public function test_personal_access_tokens_tokenable_id_matches_uuid_keys(): void
    {
        // Regression: Sanctum's published migration defaults to morphs()
        // (an unsignedBigInteger tokenable_id, assuming auto-increment PKs).
        // Device uses a UUID PK — SQLite's loose column typing let a UUID
        // string through silently either way, so this only actually failed
        // against real MySQL ("Data truncated for column 'tokenable_id'").
        // Asserting the column type directly here means this can't regress
        // unnoticed even when the whole suite runs on SQLite.
        $column = collect(Schema::getColumns('personal_access_tokens'))
            ->firstWhere('name', 'tokenable_id');

        $this->assertStringNotContainsStringIgnoringCase('int', $column['type_name']);
    }
}
