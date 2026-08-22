<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use App\Sync\PullRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PullRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_1200_changes_at_limit_500_page_through_to_1200_local_rows_with_the_correct_cursor(): void
    {
        $owner = User::factory()->create();

        $changes = [];
        for ($i = 1; $i <= 1200; $i++) {
            $changes[] = [
                'seq' => $i,
                'entity' => 'customer',
                'entity_id' => (string) Str::uuid7(),
                'event' => 'change',
                'op' => 'create',
                'payload' => [
                    'code' => "CUST-D07-{$i}",
                    'name' => "Customer {$i}",
                    'owner_user_id' => $owner->id,
                ],
                'version' => 1,
            ];
        }
        $pages = array_chunk($changes, 500);

        Http::fake([
            '*/api/sync/pull*' => Http::sequence()
                ->push(['changes' => $pages[0], 'cursor' => 500])
                ->push(['changes' => $pages[1], 'cursor' => 1000])
                ->push(['changes' => $pages[2], 'cursor' => 1200]),
        ]);

        $applied = app(PullRunner::class)->run(500);

        $this->assertSame(1200, $applied);
        $this->assertSame(1200, Customer::count());
        $this->assertSame(1200, (int) DB::table('sync_state')->where('id', 1)->value('cursor'));
        $this->assertSame(0, DB::table('sync_changes')->count(), 'pulling must never queue outbox rows');
    }

    public function test_a_short_final_page_stops_the_pagination_loop(): void
    {
        Http::fake(['*/api/sync/pull*' => Http::sequence()
            ->push(['changes' => [], 'cursor' => 0]),
        ]);

        app(PullRunner::class)->run(500);

        Http::assertSentCount(1); // an empty (short) page means "no more" — must not loop again
    }

    public function test_scope_revoke_hard_deletes_locally(): void
    {
        $customer = Customer::factory()->create();

        Http::fake(['*/api/sync/pull*' => Http::response([
            'changes' => [[
                'seq' => 1,
                'entity' => 'customer',
                'entity_id' => $customer->id,
                'event' => 'scope_revoke',
                'op' => null,
                'payload' => null,
                'version' => null,
            ]],
            'cursor' => 1,
        ])]);

        app(PullRunner::class)->run();

        $this->assertNull(Customer::withTrashed()->find($customer->id));
    }

    public function test_cursor_is_committed_with_its_own_batch_not_the_whole_session(): void
    {
        // Invariant 4, made concrete: page 1 lands and its cursor commits
        // even though page 2 in this same run() call fails outright.
        $owner = User::factory()->create();
        $id = (string) Str::uuid7();

        Http::fake(['*/api/sync/pull*' => Http::sequence()
            ->push(['changes' => array_fill(0, 500, [
                'seq' => 1,
                'entity' => 'customer',
                'entity_id' => $id,
                'event' => 'change',
                'op' => 'create',
                'payload' => ['code' => 'CUST-D07-000001', 'name' => 'A', 'owner_user_id' => $owner->id],
                'version' => 1,
            ]), 'cursor' => 500])
            ->push(['not' => 'json-shaped-like-a-pull-response'], 500),
        ]);

        try {
            app(PullRunner::class)->run(500);
        } catch (\Throwable) {
            // expected — the malformed/failing second page
        }

        $this->assertSame(500, (int) DB::table('sync_state')->where('id', 1)->value('cursor'));
    }

    public function test_starts_from_the_persisted_cursor(): void
    {
        DB::table('sync_state')->where('id', 1)->update(['cursor' => 42]);

        Http::fake(['*/api/sync/pull*' => Http::response(['changes' => [], 'cursor' => 42])]);

        app(PullRunner::class)->run();

        Http::assertSent(fn ($request) => $request['since'] === 42);
    }
}
