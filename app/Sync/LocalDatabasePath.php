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
}
