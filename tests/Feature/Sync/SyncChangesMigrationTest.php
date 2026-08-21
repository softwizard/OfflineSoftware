<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncChangesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexed_on_status_and_created_at(): void
    {
        $this->assertTrue($this->hasIndexOn(['status', 'created_at']));
    }

    public function test_indexed_on_entity_entity_id_and_status(): void
    {
        $this->assertTrue($this->hasIndexOn(['entity', 'entity_id', 'status']));
    }

    private function hasIndexOn(array $columns): bool
    {
        return collect(Schema::getIndexes('sync_changes'))
            ->contains(fn ($index) => $index['columns'] === $columns);
    }
}
