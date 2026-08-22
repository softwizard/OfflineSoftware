<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The client-side half of S2-02's provisioning exchange — Sprint 2 built
 * the server endpoint; without this, there's no way for a real laptop
 * install to actually obtain the token HttpSyncApi needs. Not itself an
 * S3 "Done when" line item, but SyncApi has nothing to authenticate with
 * otherwise.
 */
class SyncRegister extends Command
{
    protected $signature = 'sync:register {server_url} {device_code} {provisioning_code}';

    protected $description = 'Exchange a one-time provisioning code for a device token and save it to .env.';

    public function handle(): int
    {
        if (! sync_is_local()) {
            $this->error('sync:register is a local-mode command — this install is in cloud mode.');

            return self::FAILURE;
        }

        $serverUrl = rtrim($this->argument('server_url'), '/');

        $response = Http::baseUrl($serverUrl)->post('/api/sync/register', [
            'device_code' => $this->argument('device_code'),
            'provisioning_code' => $this->argument('provisioning_code'),
        ]);

        if ($response->failed()) {
            $this->error('Registration failed: '.($response->json('error') ?? $response->status()));

            return self::FAILURE;
        }

        $token = $response->json('device_token');

        $this->updateEnv('SYNC_SERVER_URL', $serverUrl);
        $this->updateEnv('SYNC_DEVICE_TOKEN', $token);

        $this->info('Registered. SYNC_SERVER_URL and SYNC_DEVICE_TOKEN written to .env — restart the app to pick them up.');

        return self::SUCCESS;
    }

    protected function updateEnv(string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $contents = file_exists($path) ? (file_get_contents($path) ?: '') : '';

        $line = "{$key}={$value}";
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents)."\n{$line}\n";

        file_put_contents($path, $contents);
    }
}
