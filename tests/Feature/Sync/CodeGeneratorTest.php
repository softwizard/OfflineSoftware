<?php

namespace Tests\Feature\Sync;

use App\Sync\CodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_100_creates_give_100_distinct_codes(): void
    {
        $codes = [];

        for ($i = 0; $i < 100; $i++) {
            $codes[] = CodeGenerator::next('CUST');
        }

        $this->assertCount(100, array_unique($codes));
    }

    public function test_different_devices_never_collide_even_at_the_same_count(): void
    {
        config(['sync.device_id' => 'D07']);
        $fromD07 = CodeGenerator::next('CUST');

        // D07 and D08 are two different laptops with two different local
        // databases in reality — each starts its own counter at 1. This
        // single-process test can only simulate that by resetting the
        // shared counter between the two calls.
        DB::table('code_sequences')->where('prefix', 'CUST')->update(['next_number' => 1]);
        config(['sync.device_id' => 'D08']);
        $fromD08 = CodeGenerator::next('CUST');

        // Both are the device's first code — same numeric suffix, different
        // device prefix, so they still don't collide.
        $this->assertSame('CUST-D07-000001', $fromD07);
        $this->assertSame('CUST-D08-000001', $fromD08);
        $this->assertNotSame($fromD07, $fromD08);
    }

    public function test_a_second_prefix_gets_its_own_independent_counter(): void
    {
        CodeGenerator::next('CUST');
        CodeGenerator::next('CUST');

        $this->assertSame('VEND-D01-000001', CodeGenerator::next('VEND'));
    }
}
