<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRegisterCommandTest extends TestCase
{
    public function test_successful_registration_writes_server_url_and_token_to_env(): void
    {
        Http::fake(['*/api/sync/register' => Http::response([
            'device_token' => 'abc123token',
            'device_code' => 'D07',
            'schema_version' => 1,
        ], 201)]);

        $envPath = tempnam(sys_get_temp_dir(), 'env_test_');
        file_put_contents($envPath, "APP_NAME=Laravel\nSYNC_DEVICE_ID=D07\n");
        $this->app->useEnvironmentPath(dirname($envPath));
        $this->app->loadEnvironmentFrom(basename($envPath));

        $this->artisan('sync:register', [
            'server_url' => 'https://cloud.test',
            'device_code' => 'D07',
            'provisioning_code' => 'SECRET',
        ])->assertExitCode(0);

        $contents = file_get_contents($envPath);
        $this->assertStringContainsString('SYNC_SERVER_URL=https://cloud.test', $contents);
        $this->assertStringContainsString('SYNC_DEVICE_TOKEN=abc123token', $contents);

        @unlink($envPath);
    }

    public function test_a_rejected_registration_fails_without_writing_anything(): void
    {
        Http::fake(['*/api/sync/register' => Http::response(['error' => 'invalid_provisioning_code'], 422)]);

        $this->artisan('sync:register', [
            'server_url' => 'https://cloud.test',
            'device_code' => 'D07',
            'provisioning_code' => 'WRONG',
        ])->assertExitCode(1);
    }
}
