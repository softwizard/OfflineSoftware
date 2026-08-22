<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_log', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->primary();
            $table->string('entity');
            $table->uuid('entity_id');
            // 'change' (create/update/delete, always the full canonical row
            // for create/update — see App\Sync\Syncable::fullCanonicalPayload
            // — or a {deleted_at} tombstone for delete) or 'scope_revoke'
            // (A3: the record left this owner's scope; no payload, client
            // hard-deletes on receipt).
            $table->string('event');
            $table->string('op')->nullable();
            $table->text('payload')->nullable();
            // Who this log row is FOR on pull — the record's current owner
            // for 'change' rows, but the OLD owner for 'scope_revoke' rows
            // (A2/A3): that owner has already lost the record, so it can't
            // be read off the record's current state at pull time.
            $table->uuid('target_owner_user_id');
            $table->unsignedBigInteger('version')->nullable();
            $table->string('origin_device')->nullable();
            $table->timestamp('server_received_at');
            $table->timestamps();

            $table->index(['target_owner_user_id', 'seq']);
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_log');
    }
};
