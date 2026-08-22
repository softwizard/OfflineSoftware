<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity');
            $table->uuid('entity_id');
            $table->uuid('change_id'); // the losing push's change id
            $table->string('origin_device')->nullable();
            $table->unsignedBigInteger('incoming_base_version')->nullable();
            $table->unsignedBigInteger('current_version');
            $table->text('incoming_payload');
            $table->text('current_payload');
            // Sprint 2 only detects and holds (this row). Winner-selection,
            // the admin review screen, and the clock-skew guard are Sprint 4
            // (S4-01..S4-04) — this table just needs to already exist so that
            // work doesn't retrofit a migration underneath live conflicts.
            $table->string('status')->default('unresolved');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
