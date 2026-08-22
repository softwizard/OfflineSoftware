<?php

namespace Tests\Feature\Sync;

use App\Http\Requests\CustomerRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_requires_name_code_and_owner(): void
    {
        $this->expectException(ValidationException::class);

        CustomerRequest::validatePayload([], 'create');
    }

    public function test_create_accepts_a_complete_payload(): void
    {
        $owner = User::factory()->create();

        $validated = CustomerRequest::validatePayload([
            'code' => 'CUST-D01-000001',
            'name' => 'Acme Ltd',
            'owner_user_id' => $owner->id,
        ], 'create');

        $this->assertSame('Acme Ltd', $validated['name']);
    }

    public function test_update_allows_a_partial_payload(): void
    {
        // Absent means "don't touch" (§3.2) — an update touching only
        // 'city' must not be forced to also supply 'name'/'owner_user_id'.
        $validated = CustomerRequest::validatePayload(['city' => 'Springfield'], 'update');

        $this->assertSame(['city' => 'Springfield'], $validated);
    }

    public function test_owner_user_id_must_reference_a_real_user(): void
    {
        $this->expectException(ValidationException::class);

        CustomerRequest::validatePayload([
            'code' => 'CUST-D01-000001',
            'name' => 'Acme',
            'owner_user_id' => (string) Str::uuid7(),
        ], 'create');
    }

    public function test_the_bare_rules_method_defaults_to_create_semantics(): void
    {
        $request = new CustomerRequest;

        $this->assertSame($request->rulesFor('create'), $request->rules());
    }
}
