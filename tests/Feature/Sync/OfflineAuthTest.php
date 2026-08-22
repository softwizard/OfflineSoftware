<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * S6-02 — "login works with no network." Tests\TestCase::setUp() already
 * calls Http::preventStrayRequests() globally (Sprint 5), so any test in
 * this suite that made a real/unfaked HTTP call would fail immediately —
 * these tests lean on exactly that to prove login makes none at all,
 * rather than asserting it indirectly.
 */
class OfflineAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_bcrypt_rounds_default_to_at_least_12_on_a_real_deployment(): void
    {
        // phpunit.xml deliberately sets BCRYPT_ROUNDS=4 (Laravel's own
        // scaffold default) so the whole suite doesn't pay real bcrypt
        // cost on every factory-created user — Hash::make() inside this
        // process reflects that override, not what a real install gets.
        // What S6-02 actually requires is config/hashing.php's fallback
        // (env('BCRYPT_ROUNDS', 12)) once nothing overrides it, which is
        // what a real .env — see .env.example's BCRYPT_ROUNDS=12 — relies
        // on for anything it doesn't set explicitly.
        putenv('BCRYPT_ROUNDS');
        unset($_SERVER['BCRYPT_ROUNDS'], $_ENV['BCRYPT_ROUNDS']);

        try {
            $this->assertGreaterThanOrEqual(12, (int) env('BCRYPT_ROUNDS', 12));
        } finally {
            putenv('BCRYPT_ROUNDS=4');
            $_SERVER['BCRYPT_ROUNDS'] = '4';
            $_ENV['BCRYPT_ROUNDS'] = '4';
        }
    }

    public function test_env_example_documents_a_bcrypt_cost_of_at_least_12(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^BCRYPT_ROUNDS=(\d+)$/m', $envExample);
        preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', $envExample, $matches);
        $this->assertGreaterThanOrEqual(12, (int) $matches[1]);
    }

    public function test_login_succeeds_against_the_local_database_alone(): void
    {
        $user = User::factory()->create(['password' => 'a-real-password']);

        // preventStrayRequests() means this would throw on the first
        // unfaked network attempt — it doesn't, because auth never makes
        // one: local mode's "server" for login purposes is the SQLite
        // file on disk.
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => 'a-real-password']));
        $this->assertTrue(Auth::check());
    }

    public function test_login_fails_with_the_wrong_password_offline(): void
    {
        User::factory()->create(['email' => 'user@example.com', 'password' => 'a-real-password']);

        $this->assertFalse(Auth::attempt(['email' => 'user@example.com', 'password' => 'wrong-password']));
    }
}
