<?php

namespace Tests\Feature;

use App\Providers\NativeAppServiceProvider;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;
use Tests\TestCase;

/**
 * S7-03 — "sync:run fires on schedule inside the packaged app." A
 * packaged install has no Windows Task Scheduler entry and no OS cron,
 * so the only thing that can tick routes/console.php's
 * Schedule::command('sync:run')->everyFiveMinutes() is Laravel's own
 * schedule:work loop, kept alive as a persistent child process for as
 * long as the native app is open.
 */
class NativeAppServiceProviderTest extends TestCase
{
    private function fakeWindows(): void
    {
        Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);
    }

    public function test_boot_opens_the_main_window(): void
    {
        $this->fakeWindows();
        ChildProcess::fake();

        (new NativeAppServiceProvider)->boot();

        Window::assertOpened('main');
    }

    public function test_boot_starts_a_persistent_schedule_work_process(): void
    {
        $this->fakeWindows();
        ChildProcess::fake();

        (new NativeAppServiceProvider)->boot();

        ChildProcess::assertArtisan(function ($cmd, $alias, $env, $persistent, $iniSettings) {
            return $cmd === ['schedule:work']
                && $alias === 'scheduler'
                && $persistent === true;
        });
    }
}
