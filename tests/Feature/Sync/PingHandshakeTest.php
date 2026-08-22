<?php

namespace Tests\Feature\Sync;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

class PingHandshakeTest extends CloudTestCase
{
    public function test_ping_answers_with_no_database_queries(): void
    {
        DB::listen(function () {
            $this->fail('ping must not touch the database');
        });

        $this->get('/api/sync/ping')->assertNoContent();
    }

    public function test_handshake_returns_server_time_and_schema_version(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Sync-Schema-Version', (string) config('sync.schema_version'))
            ->postJson('/api/sync/handshake');

        $response->assertOk();
        $response->assertJsonStructure(['server_time', 'schema_version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $response->json('server_time'),
        );
    }
}
