<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        // Single row, like sync_sequence on the server — PullRunner locks
        // and updates this one row's cursor, never inserts a second.
        DB::table('sync_state')->insert(['id' => 1, 'cursor' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
    }
};
