<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open();

        // S7-03 — a packaged install has no Windows Task Scheduler entry
        // and no OS cron; `schedule:work` is Laravel's own long-running
        // scheduler loop (ticks every minute for as long as this process
        // lives), which is what actually keeps routes/console.php's
        // Schedule::command('sync:run')->everyFiveMinutes() firing while
        // the app is open. Same persistent-child-process pattern
        // NativePHP's own QueueWorker::up() uses for queue workers.
        ChildProcess::artisan(['schedule:work'], 'scheduler', persistent: true);
    }

    /**
     * Return an array of php.ini directives to be set.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
