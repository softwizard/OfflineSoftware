<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // customers.owner_user_id has a real FK to users — a snapshot row
        // referencing an owner that doesn't exist locally would fail to
        // insert, same as any other foreign-keyed sync payload.
        $this->owner = User::factory()->create();
    }

    private function fakeSnapshot(array $rows, int $cursor = 5): void
    {
        Http::fake(['*/api/sync/snapshot*' => Http::response([
            'entities' => ['customer' => $rows],
            'cursor' => $cursor,
        ])]);
    }

    private function row(?string $id = null, array $overrides = []): array
    {
        return array_merge([
            'id' => $id ?? (string) Str::uuid7(),
            'version' => 3,
            'code' => 'CUST-D07-'.Str::padLeft((string) fake()->unique()->numberBetween(1, 999999), 6, '0'),
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'phone' => null,
            'city' => null,
            'notes' => null,
            'status' => 'active',
            'owner_user_id' => $this->owner->id,
        ], $overrides);
    }

    public function test_populates_local_data_and_sets_the_cursor(): void
    {
        $row = $this->row();
        $this->fakeSnapshot([$row], cursor: 42);

        $this->artisan('sync:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(1, Customer::count());
        $this->assertSame($row['id'], Customer::first()->id);
        $this->assertSame(3, Customer::first()->version);
        $this->assertSame(42, (int) DB::table('sync_state')->where('id', 1)->value('cursor'));
    }

    public function test_wipes_existing_local_rows_first_so_no_duplicates_survive(): void
    {
        Customer::factory()->create(['owner_user_id' => $this->owner->id]); // stale/corrupted local data (S13)

        $freshRow = $this->row();
        $this->fakeSnapshot([$freshRow]);

        $this->artisan('sync:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(1, Customer::count());
        $this->assertSame($freshRow['id'], Customer::first()->id);
    }

    public function test_clears_pending_outbox_changes_so_nothing_stale_gets_re_pushed(): void
    {
        $customer = Customer::factory()->create(['owner_user_id' => $this->owner->id]);
        $this->assertGreaterThan(0, DB::table('sync_changes')->count());

        $this->fakeSnapshot([$this->row($customer->id)]);

        $this->artisan('sync:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_seeding_does_not_queue_new_outbox_rows(): void
    {
        $this->fakeSnapshot([$this->row(), $this->row()]);

        $this->artisan('sync:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(2, Customer::count());
        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_aborts_without_force_unless_confirmed(): void
    {
        $this->fakeSnapshot([$this->row()]);

        $this->artisan('sync:seed')
            ->expectsConfirmation(
                'This replaces all local customer data and clears any unsynced pending changes with a fresh copy from the server. Continue?',
                'no',
            )
            ->assertExitCode(Command::FAILURE);

        Http::assertNothingSent();
    }

    public function test_refuses_to_run_in_cloud_mode(): void
    {
        config(['sync.mode' => 'cloud']);

        $this->artisan('sync:seed', ['--force' => true])->assertExitCode(Command::FAILURE);

        Http::assertNothingSent();
    }
}
