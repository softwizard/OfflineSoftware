<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // "D07" — the wire format's origin_device, the CodeGenerator prefix
            // suffix, and CUST-D07-000123's human code all point back here.
            $table->string('device_code')->unique();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            // One-time provisioning: hashed at rest, like a password reset
            // token. The plaintext is shown once at `sync:device:provision`
            // time and never stored.
            $table->string('provisioning_code_hash')->nullable();
            $table->timestamp('provisioning_code_expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
