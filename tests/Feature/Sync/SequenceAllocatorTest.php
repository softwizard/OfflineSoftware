<?php

namespace Tests\Feature\Sync;

use App\Sync\SequenceAllocator;

class SequenceAllocatorTest extends CloudTestCase
{
    public function test_20_allocations_produce_no_gaps_or_duplicates(): void
    {
        $values = [];

        for ($i = 0; $i < 20; $i++) {
            $values[] = SequenceAllocator::next();
        }

        $this->assertSame(range(1, 20), $values, 'sequence must be gapless and strictly increasing');
    }

    public function test_starts_from_the_seeded_zero(): void
    {
        $this->assertSame(1, SequenceAllocator::next());
    }
}
