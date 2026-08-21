<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity');
            $table->uuid('entity_id');
            $table->string('op');
            $table->text('payload');
            $table->unsignedBigInteger('base_version')->nullable();
            // Stored pre-formatted via SyncTime::wire(), not a native timestamp
            // column — the wire string is the value that must round-trip
            // byte-for-byte, not a DB-driver's reinterpretation of it.
            $table->string('client_updated_at');
            $table->string('origin_device');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['entity', 'entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
