<?php

namespace Tests\Feature\Sync;

use App\Sync\Backoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackoffTest extends TestCase
{
    use RefreshDatabase;

    private function insertRun(string $status, \DateTimeInterface $startedAt): void
    {
        DB::table('sync_runs')->insert([
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);
    }

    public function test_no_prior_runs_never_skips(): void
    {
        $this->assertFalse(app(Backoff::class)->shouldSkip());
    }

    public function test_a_successful_last_run_never_skips(): void
    {
        $this->insertRun('success', now()->subSeconds(1));

        $this->assertFalse(app(Backoff::class)->shouldSkip());
    }

    public function test_one_failure_backs_off_for_1_minute(): void
    {
        $this->insertRun('failed', now()->subSeconds(30));
        $this->assertTrue(app(Backoff::class)->shouldSkip(), 'only 30s since the one failure, tier is 1m');

        DB::table('sync_runs')->update(['started_at' => now()->subSeconds(61)]);
        $this->assertFalse(app(Backoff::class)->shouldSkip(), '61s since the one failure clears the 1m tier');
    }

    public function test_consecutive_failures_escalate_the_tier(): void
    {
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('failed', now()->subMinutes(4)); // 4th failure, 4 minutes ago -> 15m tier -> still skip

        $this->assertTrue(app(Backoff::class)->shouldSkip());
    }

    public function test_recovers_automatically_once_the_tier_delay_elapses(): void
    {
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('failed', now()->subMinutes(20)); // 3rd failure -> 15m tier, 20m ago -> elapsed

        $this->assertFalse(app(Backoff::class)->shouldSkip());
    }

    public function test_a_success_resets_the_streak(): void
    {
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('failed', now()->subMinutes(20));
        $this->insertRun('success', now()->subSeconds(1));

        $this->assertFalse(app(Backoff::class)->shouldSkip());
    }

    public function test_offline_counts_as_a_failure_for_backoff_purposes(): void
    {
        $this->insertRun('offline', now()->subSeconds(10));

        $this->assertTrue(app(Backoff::class)->shouldSkip());
    }

    public function test_failures_beyond_the_fourth_stay_capped_at_1_hour(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->insertRun('failed', now()->subHours(2));
        }

        $this->assertFalse(app(Backoff::class)->shouldSkip(), '2h since the last of many failures clears even the 1h cap');
    }
}
