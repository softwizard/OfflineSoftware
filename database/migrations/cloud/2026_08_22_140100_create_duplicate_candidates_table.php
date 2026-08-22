<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity');
            $table->uuid('entity_id');
            $table->string('field'); // e.g. 'email' — A6's soft-unique fields
            $table->string('value');
            $table->string('status')->default('unresolved'); // unresolved | resolved
            $table->timestamps();

            // One flag per (entity_id, field) — re-detecting the same
            // collision on a later edit shouldn't duplicate the flag.
            $table->unique(['entity', 'entity_id', 'field']);
            $table->index(['entity', 'field', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_candidates');
    }
};
