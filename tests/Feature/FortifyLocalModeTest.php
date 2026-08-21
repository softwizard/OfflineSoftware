<?php

namespace Tests\Feature;

use Laravel\Fortify\Features;
use Tests\TestCase;

class FortifyLocalModeTest extends TestCase
{
    public function test_local_mode_excludes_reset_passwords_email_verification_and_two_factor(): void
    {
        $features = $this->fortifyFeaturesFor('local');

        $this->assertContains(Features::registration(), $features);
        $this->assertContains(Features::passkeys(), $features);
        $this->assertNotContains(Features::resetPasswords(), $features);
        $this->assertNotContains(Features::emailVerification(), $features);
        $this->assertNotContains(Features::twoFactorAuthentication(), $features);
    }

    public function test_cloud_mode_includes_reset_passwords_email_verification_and_two_factor(): void
    {
        $features = $this->fortifyFeaturesFor('cloud');

        $this->assertContains(Features::resetPasswords(), $features);
        $this->assertContains(Features::emailVerification(), $features);
        $this->assertContains(Features::twoFactorAuthentication(), $features);
    }

    public function test_forgot_password_route_is_unreachable_in_local_mode(): void
    {
        // Not route('password.request') — the feature being disabled means the
        // named route was never registered, so route() itself would throw.
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_two_factor_challenge_route_is_unreachable_in_local_mode(): void
    {
        $this->get('/two-factor-challenge')->assertNotFound();
    }

    public function test_login_route_still_works_offline(): void
    {
        $this->get(route('login'))->assertOk();
    }

    /**
     * Load config/fortify.php's 'features' array as it would resolve under
     * the given APP_MODE, without a full app reboot — Features::registration()
     * etc. return stable string identifiers (see vendor/laravel/fortify/src/Features.php),
     * so requiring the file directly is enough to exercise the same branch
     * the real boot takes.
     */
    private function fortifyFeaturesFor(string $mode): array
    {
        // env('APP_MODE') can resolve from $_SERVER/$_ENV (PHPUnit's <env> tags
        // populate both) ahead of a plain putenv(), so all three must be set
        // for the override to actually take.
        $previous = [getenv('APP_MODE'), $_SERVER['APP_MODE'] ?? null, $_ENV['APP_MODE'] ?? null];

        putenv("APP_MODE={$mode}");
        $_SERVER['APP_MODE'] = $mode;
        $_ENV['APP_MODE'] = $mode;

        $features = (require config_path('fortify.php'))['features'];

        [$env, $server, $envArr] = $previous;

        if ($env === false) {
            putenv('APP_MODE');
        } else {
            putenv("APP_MODE={$env}");
        }

        if ($server === null) {
            unset($_SERVER['APP_MODE']);
        } else {
            $_SERVER['APP_MODE'] = $server;
        }

        if ($envArr === null) {
            unset($_ENV['APP_MODE']);
        } else {
            $_ENV['APP_MODE'] = $envArr;
        }

        return $features;
    }
}
