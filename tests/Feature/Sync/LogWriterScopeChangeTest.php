<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LogWriterScopeChangeTest extends CloudTestCase
{
    public function test_reassigning_owner_emits_one_revoke_and_one_full_row_with_fresh_seqs(): void
    {
        $oldOwner = User::factory()->create();
        $newOwner = User::factory()->create();

        $customer = Customer::create([
            'code' => 'CUST-TEST-000001',
            'name' => 'Acme',
            'owner_user_id' => $oldOwner->id,
        ]);

        $createSeq = DB::table('sync_log')
            ->where('entity_id', $customer->id)->where('op', 'create')->value('seq');

        $customer->update(['owner_user_id' => $newOwner->id]);

        $rows = DB::table('sync_log')
            ->where('entity_id', $customer->id)
            ->where('seq', '>', $createSeq)
            ->orderBy('seq')
            ->get();

        $this->assertCount(2, $rows, 'exactly one revoke row and one re-emit row');

        [$revoke, $reemit] = $rows->all();

        $this->assertSame('scope_revoke', $revoke->event);
        $this->assertSame($oldOwner->id, $revoke->target_owner_user_id);
        $this->assertNull($revoke->payload);

        $this->assertSame('change', $reemit->event);
        $this->assertSame('create', $reemit->op);
        $this->assertSame($newOwner->id, $reemit->target_owner_user_id);
        $this->assertSame('Acme', json_decode($reemit->payload, true)['name']);

        // Both fresh — neither reuses the create's seq nor each other's.
        $this->assertGreaterThan($createSeq, $revoke->seq);
        $this->assertGreaterThan($revoke->seq, $reemit->seq);
    }

    public function test_a_non_scope_field_update_does_not_trigger_revoke_or_reemit(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::create([
            'code' => 'CUST-TEST-000002',
            'name' => 'Acme',
            'owner_user_id' => $owner->id,
        ]);

        $countBefore = DB::table('sync_log')->count();

        $customer->update(['city' => 'Springfield']);

        $rows = DB::table('sync_log')->get();
        $this->assertCount($countBefore + 1, $rows);
        $this->assertSame('change', $rows->last()->event);
        $this->assertSame($owner->id, $rows->last()->target_owner_user_id);
    }
}
