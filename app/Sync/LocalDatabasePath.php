<?php

namespace App\Sync;

use Illuminate\Support\Str;

class LocalDatabasePath
{
    /**
     * Where the laptop's SQLite file lives: %LOCALAPPDATA%\<app>\database.sqlite
     * on Windows — never Documents/Desktop, which OneDrive's Known Folder Move
     * can silently redirect into a synced folder and corrupt a WAL database.
     *
     * Falls back to the project's database/ folder when LOCALAPPDATA isn't
     * set (non-Windows dev machines, CI runners).
     *
     * Deliberately uses plain PHP filesystem calls, not the File facade —
     * this runs from config/database.php during LoadConfiguration, which
     * happens before facades are wired up (RegisterFacades runs later in
     * the boot sequence), so `File::` here fails with "facade root not set".
     *
     * $appName is passed in, not read via env() in here, because this class
     * lives outside config/ — an env() call here would silently return null
     * once `config:cache` runs in production (Larastan: noEnvCallsOutsideOfConfig).
     */
    public static function resolve(string $appName = 'laravel'): string
    {
        $root = getenv('LOCALAPPDATA');

        if (! $root) {
            return database_path('database.sqlite');
        }

        $folder = rtrim($root, '\\/').DIRECTORY_SEPARATOR.Str::slug($appName, '-');

        if (! is_dir($folder)) {
            mkdir($folder, 0755, recursive: true);
        }

        return $folder.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    /**
     * S7-02 — "asserting the path is not OneDrive-redirected." Known
     * Folder Move normally targets Desktop/Documents/Pictures, but some
     * corporate OneDrive policies extend it to AppData\Local too — and
     * that's exactly the folder this class trusts to be a private,
     * unsynced disk location for a WAL-mode SQLite file (§6: "SQLite in
     * WAL inside a synced folder will corrupt, and it will corrupt
     * exactly the offline work this system exists to protect").
     *
     * Windows sets the `OneDrive` / `OneDriveCommercial` /
     * `OneDriveConsumer` environment variables to each signed-in
     * account's sync root whenever OneDrive is installed — comparing
     * LOCALAPPDATA against those is a real, no-registry-access way to
     * detect the redirection from plain PHP.
     *
     * A startup check, not part of resolve() itself: resolve() runs
     * during config/database.php's evaluation, before facades (and
     * therefore before anything that could usefully report a fatal
     * config error) are wired up — same reasoning as
     * SyncServiceProvider::boot()'s DeviceIdentity::id() call, which
     * this is meant to run alongside.
     */
    public static function assertNotOneDriveRedirected(): void
    {
        $localAppData = getenv('LOCALAPPDATA');

        if (! $localAppData) {
            return; // resolve() itself falls back to database/ in this case — nothing OneDrive could redirect
        }

        foreach (['OneDrive', 'OneDriveCommercial', 'OneDriveConsumer'] as $envVar) {
            $oneDriveRoot = getenv($envVar);

            if ($oneDriveRoot && static::isWithin($localAppData, $oneDriveRoot)) {
                throw new OneDriveRedirectionException(
                    "LOCALAPPDATA ({$localAppData}) is inside a OneDrive-synced folder ".
                    "({$envVar}={$oneDriveRoot}). A WAL-mode SQLite database inside a synced ".
                    'folder will corrupt — see build-plan_1.md §6. Fix the OneDrive Known '.
                    'Folder Move / sync settings for this account before running this app.'
                );
            }
        }
    }

    protected static function isWithin(string $path, string $ancestor): bool
    {
        $normalize = fn (string $p) => strtolower(rtrim(str_replace('\\', '/', $p), '/')).'/';

        return str_starts_with($normalize($path), $normalize($ancestor));
    }
}
