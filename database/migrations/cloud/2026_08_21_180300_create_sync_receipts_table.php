<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_receipts', function (Blueprint $table) {
            // The wire format's change id (matches the client's sync_changes.id)
            // — A4: idempotency keyed on this, storing the outcome, not just
            // that the id was seen, so a replayed request returns the exact
            // original result instead of reprocessing or phantom-conflicting.
            $table->uuid('change_id')->primary();
            $table->string('entity');
            $table->uuid('entity_id');
            $table->string('outcome'); // applied | conflict | rejected
            $table->text('response_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_receipts');
    }
};
