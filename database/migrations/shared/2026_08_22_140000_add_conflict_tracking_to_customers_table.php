<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // ConflictResolver's LWW comparison (A5) needs to know the
            // client_updated_at/origin_device behind the row's *current*
            // value, not just its version number — sync_log has
            // origin_device per entry but never client_updated_at, and
            // reconstructing "the current side" from log history on every
            // conflict is far more work than keeping it on the row itself.
            $table->string('last_client_updated_at')->nullable()->after('version');
            $table->string('last_origin_device')->nullable()->after('last_client_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['last_client_updated_at', 'last_origin_device']);
        });
    }
};
