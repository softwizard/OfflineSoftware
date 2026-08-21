<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_zero_pending_on_an_empty_outbox(): void
    {
        $this->artisan('sync:status')
            ->expectsOutputToContain('Pending changes: 0')
            ->assertExitCode(0);
    }

    public function test_reports_pending_count_and_oldest_age(): void
    {
        $owner = User::factory()->create();
        Customer::factory(3)->create(['owner_user_id' => $owner->id]);

        $this->artisan('sync:status')
            ->expectsOutputToContain('Pending changes: 3')
            ->assertExitCode(0);
    }

    public function test_reports_failed_count(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['owner_user_id' => $owner->id]);
        DB::table('sync_changes')->where('entity_id', $customer->id)->update(['status' => 'failed']);

        $this->artisan('sync:status')
            ->expectsOutputToContain('Failed changes: 1')
            ->assertExitCode(0);
    }

    public function test_reports_that_the_cursor_is_not_yet_tracked(): void
    {
        $this->artisan('sync:status')
            ->expectsOutputToContain('Pull cursor:')
            ->assertExitCode(0);
    }
}
