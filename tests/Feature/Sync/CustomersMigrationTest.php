<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_has_no_unique_index(): void
    {
        $indexes = Schema::getIndexes('customers');

        $emailIndexes = collect($indexes)->filter(
            fn ($index) => in_array('email', $index['columns']) && $index['unique']
        );

        $this->assertTrue($emailIndexes->isEmpty(), 'customers.email must not have a unique index (A6).');
    }

    public function test_code_does_have_a_unique_index(): void
    {
        $indexes = Schema::getIndexes('customers');

        $codeIndexes = collect($indexes)->filter(
            fn ($index) => $index['columns'] === ['code'] && $index['unique']
        );

        $this->assertTrue($codeIndexes->isNotEmpty(), 'customers.code (system-generated, not user-entered) should be unique.');
    }
}
