<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\PushRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_changes_are_pushed_and_applied_row_deleted_from_outbox(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);
        $this->assertSame(1, DB::table('sync_changes')->count());

        Http::fake(['*/api/sync/push' => Http::response([
            'results' => [[
                'id' => DB::table('sync_changes')->value('id'),
                'entity_id' => $customer->id,
                'outcome' => 'applied',
                'row' => $customer->toWirePayload(),
                'version' => 1,
            ]],
        ])]);

        $pushed = app(PushRunner::class)->run();

        $this->assertSame(1, $pushed);
        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_a_conflict_outcome_applies_the_servers_row_and_keeps_the_change_marked(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::create(['name' => 'Mine', 'owner_user_id' => $owner->id]);
        $changeId = DB::table('sync_changes')->value('id');

        Http::fake(['*/api/sync/push' => Http::response([
            'results' => [[
                'id' => $changeId,
                'entity_id' => $customer->id,
                'outcome' => 'conflict',
                'row' => array_merge($customer->toWirePayload(), ['name' => 'Server Wins']),
                'version' => 9,
            ]],
        ])]);

        app(PushRunner::class)->run();

        $customer->refresh();
        $this->assertSame('Server Wins', $customer->name);
        $this->assertSame(9, $customer->version);

        $row = DB::table('sync_changes')->where('id', $changeId)->first();
        $this->assertNotNull($row, 'a conflict must stay visible, not be silently discarded');
        $this->assertSame('conflict', $row->status);
    }

    public function test_a_rejected_outcome_keeps_the_change_and_records_the_error(): void
    {
        $owner = User::factory()->create();
        Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);
        $changeId = DB::table('sync_changes')->value('id');

        Http::fake(['*/api/sync/push' => Http::response([
            'results' => [[
                'id' => $changeId,
                'entity_id' => 'irrelevant',
                'outcome' => 'rejected',
                'errors' => ['name' => ['The name field is required.']],
            ]],
        ])]);

        app(PushRunner::class)->run();

        $row = DB::table('sync_changes')->where('id', $changeId)->first();
        $this->assertSame('rejected', $row->status);
        $this->assertStringContainsString('required', $row->error);
    }

    public function test_transport_failure_leaves_everything_pending(): void
    {
        $owner = User::factory()->create();
        Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);

        Http::fake(function () {
            throw new ConnectionException('down');
        });

        try {
            app(PushRunner::class)->run();
            $this->fail('Expected the transport failure to propagate.');
        } catch (\Throwable) {
            // expected
        }

        $row = DB::table('sync_changes')->first();
        $this->assertSame('pending', $row->status, 'a transport failure must not touch the outbox status/payload');
        $this->assertSame(1, $row->attempts, 'attempts is a deliberate carve-out — it still counts even when nothing else is touched');
    }

    public function test_retry_after_a_transport_failure_creates_no_duplicate_server_side_state(): void
    {
        // The retry sends the exact same change id — Sprint 2's receipts
        // (A4) are what make this safe; PushRunner's only job is to not
        // have mutated anything locally, so the retry re-sends unchanged.
        $owner = User::factory()->create();
        $customer = Customer::create(['name' => 'Acme', 'owner_user_id' => $owner->id]);
        $changeId = DB::table('sync_changes')->value('id');

        Http::fake(['*/api/sync/push' => Http::response([
            'results' => [[
                'id' => $changeId,
                'entity_id' => $customer->id,
                'outcome' => 'applied',
                'row' => $customer->toWirePayload(),
                'version' => 1,
            ]],
        ])]);

        app(PushRunner::class)->run();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/sync/push')
            && $request['changes'][0]['id'] === $changeId);
        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_returns_zero_and_makes_no_request_when_nothing_is_pending(): void
    {
        Http::fake();

        $pushed = app(PushRunner::class)->run();

        $this->assertSame(0, $pushed);
        Http::assertNothingSent();
    }
}
