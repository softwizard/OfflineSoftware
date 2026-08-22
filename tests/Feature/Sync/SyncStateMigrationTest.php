<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncStateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_state_seeded_with_exactly_one_row(): void
    {
        $rows = DB::table('sync_state')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows->first()->id);
        $this->assertSame(0, (int) $rows->first()->cursor);
    }

    public function test_cursor_and_last_success_persist(): void
    {
        DB::table('sync_state')->where('id', 1)->update([
            'cursor' => 42,
            'last_success_at' => now(),
        ]);

        $row = DB::table('sync_state')->where('id', 1)->first();

        $this->assertSame(42, (int) $row->cursor);
        $this->assertNotNull($row->last_success_at);
    }

    public function test_sync_runs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('sync_runs'));
    }
}
