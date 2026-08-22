<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            // S5-04's /sync page needs this — how many times PushRunner
            // has included this row in a push batch, incremented before
            // the API call regardless of outcome (even a transport
            // failure still counts as an attempt). Purely observational;
            // unlike status/payload, losing an increment to a crash isn't
            // a correctness concern, so it isn't part of the "nothing is
            // written until a successful response" guarantee (S3-04).
            $table->unsignedInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
