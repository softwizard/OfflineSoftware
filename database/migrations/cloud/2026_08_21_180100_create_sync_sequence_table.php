<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_sequence', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('value')->default(0);
        });

        // Exactly one row — App\Sync\SequenceAllocator locks and increments
        // this single row per §3.5. Seeded here, not in a separate seeder,
        // so a fresh cloud migrate:fresh is immediately correct.
        DB::table('sync_sequence')->insert(['id' => 1, 'value' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_sequence');
    }
};
