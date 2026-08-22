<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Sync\Api\DeviceRevokedException;
use App\Sync\Api\SyncApi;
use App\Sync\OfflineLock;
use App\Sync\OfflineLockException;
use App\Sync\PushRunner;
use App\Sync\SyncContext;
use App\Sync\SyncRunner;
use App\Sync\SyncTime;
use App\Sync\SyncWipe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * S8-03 — build-plan_1.md §5, all 20 scenarios, run end to end against the
 * real PushRunner/PullRunner/PushController/PullController/
 * ConflictResolver/RemoteApplier/ChangeCoalescer/DuplicateDetector/
 * OfflineLock/SyncWipe stack via Tests\Feature\Sync\MultiDeviceTestCase
 * (S8-01) and InProcessSyncApi (S8-02) — no HTTP, no ports, deterministic.
 *
 * Many of these mechanisms already have dedicated unit-level coverage
 * elsewhere (SequenceAllocatorTest, ConflictResolverTest, OfflineLockTest,
 * ...); what this file adds is the *integration* — real multiple devices,
 * a real server, genuinely separate databases, exercised the way the
 * actual protocol would exercise them.
 */
class ChaosScenariosTest extends MultiDeviceTestCase
{
    public function test_s01_offline_creates_sync_cleanly(): void
    {
        $this->onDeviceA(function () {
            Customer::factory(3)->create(['owner_user_id' => $this->ownerId]);
        });

        $this->syncDevice('device_a');

        $this->onServer(fn () => $this->assertSame(3, Customer::count()));
        $this->onDeviceA(fn () => $this->assertSame(0, DB::table('sync_changes')->count()));
    }

    public function test_s02_b_pulls_what_a_pushed_at_the_identical_version(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId])->id);

        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        $serverVersion = $this->onServer(fn () => Customer::find($id)->version);
        $this->onDeviceB(function () use ($id, $serverVersion) {
            $this->assertNotNull(Customer::find($id));
            $this->assertSame($serverVersion, Customer::find($id)->version);
        });
    }

    public function test_s03_a_sequential_edit_after_a_pull_never_conflicts(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId])->id);
        $this->syncDevice('device_a');
        $this->syncDevice('device_b'); // B now holds the row

        $this->onDeviceA(fn () => Customer::find($id)->update(['name' => 'Edited By A']));
        $this->syncDevice('device_a');

        $this->syncDevice('device_b'); // B pulls A's edit before making its own

        $this->onDeviceB(fn () => Customer::find($id)->update(['name' => 'Edited By B']));
        $this->syncDevice('device_b');

        $this->onServer(function () use ($id) {
            $this->assertSame('Edited By B', Customer::find($id)->name);
            $this->assertSame(0, DB::table('sync_conflicts')->count());
        });
    }

    public function test_s04_concurrent_offline_edits_converge_with_one_conflict_row(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'name' => 'Original'])->id);
        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        $this->onDeviceA(fn () => Customer::find($id)->update(['name' => 'From A']));
        $this->onDeviceB(fn () => Customer::find($id)->update(['name' => 'From B']));

        $this->syncDevice('device_a'); // applies cleanly — server was still at A's base_version
        $this->syncDevice('device_b'); // conflicts — resolver picks a winner; B's own push response already carries it

        $this->onServer(fn () => $this->assertSame(1, DB::table('sync_conflicts')->count()));

        $winner = $this->onServer(fn () => Customer::find($id)->name);
        $this->assertContains($winner, ['From A', 'From B']);
        $this->onDeviceB(fn () => $this->assertSame($winner, Customer::find($id)->name));

        $this->syncDevice('device_a'); // A only converges once it pulls again
        $this->onDeviceA(fn () => $this->assertSame($winner, Customer::find($id)->name));
    }

    public function test_s05_delete_beats_a_concurrent_update(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId])->id);
        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        $this->onDeviceA(fn () => Customer::find($id)->delete());
        $this->onDeviceB(fn () => Customer::find($id)->update(['name' => 'B Edit']));

        $this->syncDevice('device_a'); // the delete applies first
        $this->syncDevice('device_b'); // conflicts — delete wins regardless of timestamps

        $this->onServer(function () use ($id) {
            $this->assertNotNull(Customer::withTrashed()->find($id)->deleted_at);
            $conflict = DB::table('sync_conflicts')->latest('created_at')->first();
            $this->assertSame('current', $conflict->winner);
        });
    }

    public function test_s06_a_transport_failure_leaves_everything_pending_and_the_retry_is_safe(): void
    {
        $this->onDeviceA(function () {
            Customer::factory(2)->create(['owner_user_id' => $this->ownerId]);
        });

        $api = $this->apiFor('device_a')->failNextPush(1);

        try {
            $this->pushFrom('device_a', $api);
            $this->fail('Expected the simulated transport failure to propagate.');
        } catch (Throwable) {
            // expected
        }

        $this->onDeviceA(fn () => $this->assertSame(2, DB::table('sync_changes')->where('status', 'pending')->count()));

        $this->pushFrom('device_a', $api); // retry — the fault only fires once

        $this->onServer(fn () => $this->assertSame(2, Customer::count()));
        $this->onDeviceA(fn () => $this->assertSame(0, DB::table('sync_changes')->count()));
    }

    public function test_s07_a_lost_response_replays_the_receipt_with_no_phantom_conflict(): void
    {
        $this->onDeviceA(function () {
            Customer::factory()->create(['owner_user_id' => $this->ownerId]);
        });

        $api = $this->apiFor('device_a');

        // First attempt — genuinely reaches and is applied by the server;
        // the *client* just never gets to process the response (as if the
        // reply itself, not the request, was lost in transit) — so the
        // local outbox row is deliberately left untouched here, unlike
        // what pushFrom()/PushRunner would normally do next.
        $this->onDeviceA(function () use ($api) {
            $wire = DB::table('sync_changes')->where('status', 'pending')->get()->map(fn ($row) => [
                'id' => $row->id, 'entity' => $row->entity, 'entity_id' => $row->entity_id,
                'op' => $row->op, 'base_version' => $row->base_version,
                'client_updated_at' => $row->client_updated_at, 'origin_device' => $row->origin_device,
                'payload' => json_decode($row->payload, true),
            ])->all();

            $api->push($wire);
        });

        // Retry — the same still-"pending" row, now through the real
        // PushRunner, exactly like the next scheduled sync:run would do,
        // unaware anything already happened.
        $this->pushFrom('device_a', $api);

        $this->onServer(function () {
            $this->assertSame(1, DB::table('sync_log')->count());
            $this->assertSame(0, DB::table('sync_conflicts')->count());
            $this->assertSame(1, DB::table('sync_receipts')->count());
        });
        $this->onDeviceA(fn () => $this->assertSame(0, DB::table('sync_changes')->count()));
    }

    public function test_s08_a_skewed_clock_is_flagged_but_the_existing_value_wins(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'name' => 'Original'])->id);
        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        // A's correctly-timed edit lands on the server first — this is
        // "the existing value" B's skewed edit will collide with.
        $this->onDeviceA(fn () => Customer::find($id)->update(['name' => 'Correct Time Edit']));
        $this->syncDevice('device_a');

        $this->onDeviceB(fn () => Customer::find($id)->update(['name' => 'Skewed Clock Edit']));
        $this->onDeviceB(function () use ($id) {
            DB::table('sync_changes')->where('entity_id', $id)->update([
                'client_updated_at' => SyncTime::wire(now()->subDays(2)),
            ]);
        });

        $this->syncDevice('device_b');

        $this->onServer(function () use ($id) {
            $this->assertSame('Correct Time Edit', Customer::find($id)->name);
            $conflict = DB::table('sync_conflicts')->latest('created_at')->first();
            $this->assertTrue((bool) $conflict->clock_suspect);
            $this->assertSame('current', $conflict->winner);
        });
    }

    public function test_s09_a_large_backlog_syncs_completely_across_several_batches(): void
    {
        $this->onDeviceA(function () {
            Customer::factory(700)->create(['owner_user_id' => $this->ownerId]);
        });

        $totalPushed = $this->onDeviceA(function () {
            $api = $this->apiFor('device_a');
            $total = 0;

            while (DB::table('sync_changes')->where('status', 'pending')->exists()) {
                $total += app(PushRunner::class, ['api' => $api])->run();
            }

            return $total;
        });

        $this->assertSame(700, $totalPushed);
        $this->onServer(fn () => $this->assertSame(700, Customer::count()));
        $this->onDeviceA(fn () => $this->assertSame(0, DB::table('sync_changes')->count()));

        $this->pullTo('device_b', limit: 200); // pages through, several times, to drain 700 rows
        $this->onDeviceB(function () {
            $this->assertSame(700, Customer::count());
            $this->assertSame(700, (int) DB::table('sync_state')->where('id', 1)->value('cursor'));
        });
    }

    public function test_s10_40_offline_edits_coalesce_to_one_change(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'name' => 'v0'])->id);
        $this->syncDevice('device_a');

        $this->onDeviceA(function () use ($id) {
            for ($i = 1; $i <= 40; $i++) {
                Customer::find($id)->update(['name' => "v{$i}"]);
            }
        });

        $this->onDeviceA(fn () => $this->assertSame(1, DB::table('sync_changes')->count()));

        $this->syncDevice('device_a');

        $this->onServer(fn () => $this->assertSame('v40', Customer::find($id)->name));
    }

    public function test_s11_reassigning_scope_revokes_from_the_old_owner_and_delivers_to_the_new(): void
    {
        $newOwnerId = (string) Str::uuid7();
        $newOwnerRow = fn () => [
            'id' => $newOwnerId, 'name' => 'New Owner', 'email' => $newOwnerId.'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ];
        $this->onServer(function () use ($newOwnerRow) {
            DB::table('users')->insert($newOwnerRow());
            DB::table('devices')->where('device_code', 'DB1')->update(['owner_user_id' => $newOwnerRow()['id']]);
        });
        $this->onDeviceB(function () use ($newOwnerRow) {
            DB::table('users')->insert($newOwnerRow());
        });

        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId])->id);
        $this->syncDevice('device_a');

        // An admin reassigns the customer to the new owner — a direct
        // cloud-side edit, same as S4-03's conflict-restore admin action.
        $this->onServer(function () use ($id, $newOwnerId) {
            SyncContext::withOriginDevice(null, fn () => Customer::find($id)->update(['owner_user_id' => $newOwnerId]));
        });

        $this->syncDevice('device_a'); // pulls its own scope_revoke -> hard delete
        $this->syncDevice('device_b'); // pulls the full re-emitted row

        $this->onDeviceA(fn () => $this->assertNull(Customer::withTrashed()->find($id)));
        $this->onDeviceB(fn () => $this->assertNotNull(Customer::find($id)));
    }

    public function test_s12_an_outdated_client_schema_is_rejected_before_anything_is_pushed(): void
    {
        $this->onDeviceA(function () {
            Customer::factory()->create(['owner_user_id' => $this->ownerId]);
        });

        config(['sync.min_supported_schema_version' => 5]);

        try {
            $this->pushFrom('device_a', $this->apiFor('device_a', schemaVersion: 1));
            $this->fail('Expected a schema_outdated rejection.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('schema_outdated', $e->getMessage());
        } finally {
            config(['sync.min_supported_schema_version' => 1]);
        }

        $this->onServer(fn () => $this->assertSame(0, Customer::count()));
        $this->onDeviceA(fn () => $this->assertSame(1, DB::table('sync_changes')->where('status', 'pending')->count()));
    }

    public function test_s13_a_corrupted_local_db_reseeds_to_match_the_server_with_no_duplicates(): void
    {
        $this->onServer(function () {
            Customer::factory(5)->create(['owner_user_id' => $this->ownerId]);
        });

        $this->onDeviceA(function () {
            // "Corrupted" — garbage local data and a stale outbox entry
            // that must never get re-pushed after the reseed.
            Customer::factory(2)->create(['owner_user_id' => $this->ownerId]);
            $this->assertGreaterThan(0, DB::table('sync_changes')->count());

            app()->instance(SyncApi::class, $this->apiFor('device_a'));
            Artisan::call('sync:seed', ['--force' => true]);

            $this->assertSame(5, Customer::count());
            $this->assertSame(5, Customer::distinct()->count('code'), 'no duplicates');
            $this->assertSame(0, DB::table('sync_changes')->count());
        });
    }

    public function test_s14_duplicate_emails_from_different_devices_are_both_kept_and_flagged(): void
    {
        $email = 'dup@example.test';

        $idA = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'email' => $email])->id);
        $idB = $this->onDeviceB(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'email' => $email])->id);

        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        $this->onServer(function () use ($idA, $idB, $email) {
            $this->assertNotNull(Customer::find($idA));
            $this->assertNotNull(Customer::find($idB));
            $this->assertSame(2, Customer::where('email', $email)->count());
            $this->assertSame(2, DB::table('duplicate_candidates')->where('field', 'email')->count());
        });
    }

    public function test_s15_a_revoked_device_is_wiped_within_one_sync_cycle(): void
    {
        $this->onDeviceA(function () {
            Customer::factory(2)->create(['owner_user_id' => $this->ownerId]);
        });
        $this->syncDevice('device_a');

        $this->onServer(fn () => DB::table('devices')->where('device_code', 'DA1')->update(['revoked_at' => now()]));

        $this->onDeviceA(function () {
            Customer::factory()->create(['owner_user_id' => $this->ownerId]); // one more local edit before it learns it's revoked

            try {
                app(PushRunner::class, ['api' => $this->apiFor('device_a')])->run();
                $this->fail('Expected a DeviceRevokedException.');
            } catch (DeviceRevokedException) {
                app(SyncWipe::class)->wipe(); // exactly what SyncRunner itself does on this exception
            }

            $this->assertSame(0, Customer::withTrashed()->count());
            $this->assertSame(0, DB::table('sync_changes')->count());
        });
    }

    public function test_s16_interleaved_pushes_from_three_devices_produce_a_gapless_sequence(): void
    {
        foreach (['device_a', 'device_b', 'device_c'] as $context) {
            $this->withContext(self::CONTEXTS[$context], function () {
                Customer::factory(5)->create(['owner_user_id' => $this->ownerId]);
            });
        }

        // Interleaved, not batched — near-simultaneous activity from a
        // single-threaded test process. Real concurrent-connection row
        // -locking is SequenceAllocatorTest's job; this proves the
        // *outcome* stays gapless once several devices actually race it.
        for ($round = 0; $round < 5; $round++) {
            foreach (['device_a', 'device_b', 'device_c'] as $context) {
                $this->pushFrom($context);
            }
        }

        $this->onServer(function () {
            $seqs = DB::table('sync_log')->orderBy('seq')->pluck('seq')->all();
            $this->assertSame(15, count($seqs));
            $this->assertSame(range(1, 15), $seqs, 'no reader may ever observe a gap in the sequence');
        });
    }

    public function test_s17_an_admin_restore_reaches_every_device_as_an_undelete(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'name' => 'Keep Me'])->id);
        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        $this->onDeviceA(fn () => Customer::find($id)->delete());
        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        $this->onServer(fn () => Customer::withTrashed()->find($id)->restore());

        $this->syncDevice('device_a');
        $this->syncDevice('device_b');

        foreach (['device_a', 'device_b'] as $context) {
            $this->withContext(self::CONTEXTS[$context], function () use ($id) {
                $c = Customer::find($id);
                $this->assertNotNull($c);
                $this->assertSame('Keep Me', $c->name);
            });
        }
    }

    public function test_s18_three_way_offline_conflict_converges_to_one_winner(): void
    {
        $id = $this->onDeviceA(fn () => Customer::factory()->create(['owner_user_id' => $this->ownerId, 'name' => 'Original'])->id);
        $this->syncDevice('device_a');
        $this->syncDevice('device_b');
        $this->syncDevice('device_c');

        $this->onDeviceA(fn () => Customer::find($id)->update(['name' => 'From A']));
        $this->onDeviceB(fn () => Customer::find($id)->update(['name' => 'From B']));
        $this->onDeviceC(fn () => Customer::find($id)->update(['name' => 'From C']));

        $this->syncDevice('device_a');
        $this->syncDevice('device_b');
        $this->syncDevice('device_c');

        $this->onServer(fn () => $this->assertSame(2, DB::table('sync_conflicts')->count()));

        $winner = $this->onServer(fn () => Customer::find($id)->name);
        $this->assertContains($winner, ['From A', 'From B', 'From C']);

        $this->syncDevice('device_a'); // A and B need one more pull each to
        $this->syncDevice('device_b'); // receive whichever edit actually won

        foreach (['device_a', 'device_b', 'device_c'] as $context) {
            $this->withContext(self::CONTEXTS[$context], fn () => $this->assertSame($winner, Customer::find($id)->name));
        }

        $payloads = $this->onServer(fn () => DB::table('sync_conflicts')->orderBy('created_at')->get()
            ->flatMap(fn ($row) => [$row->incoming_payload, $row->current_payload])
            ->implode(' '));

        $this->assertStringContainsString('From A', $payloads);
        $this->assertStringContainsString('From B', $payloads);
        $this->assertStringContainsString('From C', $payloads);
    }

    public function test_s19_a_pull_that_drops_mid_batch_resumes_gap_free(): void
    {
        $this->onServer(function () {
            SyncContext::withOriginDevice('DB1', function () {
                Customer::factory(5)->create(['owner_user_id' => $this->ownerId]);
            });
        });

        $api = $this->apiFor('device_a')->failPullAfterPages(1); // page 1 succeeds, page 2 fails

        try {
            $this->pullTo('device_a', $api, limit: 2);
            $this->fail('Expected the simulated failure on the second page.');
        } catch (Throwable) {
            // expected
        }

        $this->onDeviceA(function () {
            $this->assertSame(2, Customer::count(), 'only the first, fully-committed page landed');
            $this->assertSame(2, (int) DB::table('sync_state')->where('id', 1)->value('cursor'));
        });

        $this->pullTo('device_a', $api, limit: 2); // retry — resumes from the committed cursor

        $this->onDeviceA(function () {
            $this->assertSame(5, Customer::count());
            $this->assertSame(5, (int) DB::table('sync_state')->where('id', 1)->value('cursor'));
        });
    }

    public function test_s20_writes_lock_past_14_days_offline_and_unlock_on_reconnect(): void
    {
        $this->onDeviceA(function () {
            DB::table('sync_state')->where('id', 1)->update(['last_success_at' => now()->subDays(15)]);

            try {
                Customer::factory()->create(['owner_user_id' => $this->ownerId]);
                $this->fail('Expected the offline lock to block this write.');
            } catch (OfflineLockException) {
                // expected
            }
        });

        $this->onServer(function () {
            SyncContext::withOriginDevice('DB1', function () {
                Customer::factory(3)->create(['owner_user_id' => $this->ownerId]);
            });
        });

        // The full SyncRunner, not the lighter push-then-pull helper this
        // file otherwise uses — "reconnecting ... unlocks writes" is
        // specifically about SyncRunner's own last_success_at bookkeeping
        // (App\Sync\SyncRunner::attempt(), set only once a whole cycle
        // completes) being what OfflineLock actually reads; the pull
        // itself is never blocked (Sync::withoutRecording — invariant 2)
        // regardless of which runner drives it.
        $this->onDeviceA(function () {
            app()->instance(SyncApi::class, $this->apiFor('device_a'));
            $this->assertTrue(app(SyncRunner::class)->run());
        });

        $this->onDeviceA(function () {
            $this->assertSame(3, Customer::count(), 'the backlog synced with no loss');
            $this->assertFalse(app(OfflineLock::class)->status()['locked']);

            Customer::factory()->create(['owner_user_id' => $this->ownerId]); // writes are unlocked again
            $this->assertSame(4, Customer::count());
        });
    }
}
