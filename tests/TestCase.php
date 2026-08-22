<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sprint 5 embeds the status bar (Connectivity::online() -> a real
        // ping) in the shared layout, so every authenticated-page test now
        // renders it. Without this, a test that never calls Http::fake()
        // would let that ping attempt a genuine network request — its
        // try/catch swallows the eventual failure either way, but "eventual"
        // means depending on this machine's DNS/timeout behavior for
        // speed and determinism, not on anything this suite controls.
        // preventStrayRequests() makes any unfaked call throw immediately,
        // with no real I/O attempted, so that swallowed failure is instant
        // and environment-independent instead.
        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
