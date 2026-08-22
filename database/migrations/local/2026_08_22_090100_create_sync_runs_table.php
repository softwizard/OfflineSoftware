<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status'); // running | success | offline | failed
            $table->string('stage')->nullable(); // probe | handshake | push | pull, set on failure
            $table->text('error')->nullable();
            $table->unsignedInteger('pushed_count')->default(0);
            $table->unsignedInteger('pulled_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
