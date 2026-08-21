<?php

namespace Tests\Feature\Sync;

use App\Models\Customer;
use App\Sync\Scopes\OwnerScope;
use App\Sync\SyncRegistry;
use RuntimeException;
use Tests\TestCase;

class SyncRegistryTest extends TestCase
{
    public function test_fields_returns_the_customer_allowlist(): void
    {
        $this->assertSame(
            ['code', 'name', 'email', 'phone', 'city', 'notes', 'status', 'owner_user_id'],
            SyncRegistry::fields('customer'),
        );
    }

    public function test_key_for_model_resolves_from_an_instance_or_class_name(): void
    {
        $this->assertSame('customer', SyncRegistry::keyForModel(new Customer));
        $this->assertSame('customer', SyncRegistry::keyForModel(Customer::class));
    }

    public function test_model_for_and_scope_for_resolve_the_registered_classes(): void
    {
        $this->assertSame(Customer::class, SyncRegistry::modelFor('customer'));
        $this->assertSame(OwnerScope::class, SyncRegistry::scopeFor('customer'));
    }

    public function test_unknown_entity_throws(): void
    {
        $this->expectException(RuntimeException::class);

        SyncRegistry::fields('not-a-real-entity');
    }
}
