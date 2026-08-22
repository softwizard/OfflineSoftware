<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // S6-05's fleet dashboard needs both: what schema build is this
            // device running (EnforceSchemaVersion sets this on every
            // authenticated request), and how far behind its own scope's
            // log it is (PullController sets this on every pull page).
            $table->unsignedInteger('last_schema_version')->nullable()->after('last_seen_at');
            $table->unsignedBigInteger('last_pulled_seq')->default(0)->after('last_schema_version');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['last_schema_version', 'last_pulled_seq']);
        });
    }
};
