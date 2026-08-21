<?php

namespace Tests\Feature;

use App\Sync\LocalDatabasePath;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteBootstrapTest extends TestCase
{
    public function test_sqlite_connection_config_requests_wal_busy_timeout_and_foreign_keys(): void
    {
        $config = config('database.connections.sqlite');

        $this->assertSame('WAL', $config['journal_mode']);
        $this->assertSame(5000, $config['busy_timeout']);
        $this->assertTrue($config['foreign_key_constraints']);
    }

    public function test_a_file_backed_sqlite_connection_actually_applies_wal(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync_wal_test_'.uniqid().'.sqlite';
        touch($path);

        config(['database.connections.sqlite_wal_test' => array_merge(
            config('database.connections.sqlite'),
            ['database' => $path],
        )]);

        $mode = DB::connection('sqlite_wal_test')->selectOne('PRAGMA journal_mode')->journal_mode;

        $this->assertSame('wal', $mode);

        DB::purge('sqlite_wal_test');
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }

    public function test_local_database_path_resolves_under_localappdata_on_windows(): void
    {
        $path = LocalDatabasePath::resolve();

        $this->assertStringEndsWith('database.sqlite', $path);

        if ($root = getenv('LOCALAPPDATA')) {
            $this->assertStringStartsWith(rtrim($root, '\\/'), $path);
        }
    }
}
