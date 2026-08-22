<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            // Sprint 2 only ever held (never applied) — every conflict's
            // "current" side won by default. Sprint 4's ConflictResolver
            // can now pick either side, so which one actually won needs
            // recording alongside the two payloads.
            $table->string('winner')->nullable()->after('current_payload'); // incoming | current
            $table->boolean('clock_suspect')->default(false)->after('winner');
            // "Restore losing value" (S4-03) needs to know whether the
            // losing side represented a delete or a live edit — a losing
            // delete restores by un-deleting with the payload's fields; a
            // losing update restores by re-deleting is never right, but a
            // losing *current* value that was itself trashed needs the
            // same distinction on the other side. incoming_payload for a
            // delete op is just {deleted_at: ...}, not a field payload.
            $table->string('incoming_op')->after('clock_suspect'); // create | update | delete
            $table->boolean('current_was_deleted')->default(false)->after('incoming_op');
        });
    }

    public function down(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->dropColumn(['winner', 'clock_suspect', 'incoming_op', 'current_was_deleted']);
        });
    }
};
