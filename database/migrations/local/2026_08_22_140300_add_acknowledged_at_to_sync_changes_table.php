<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            // S4-04: "the user is told, never silently overridden" — a
            // 'conflict' row stays visible until the user has actually
            // seen the notice, not just until the next page load.
            $table->timestamp('acknowledged_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropColumn('acknowledged_at');
        });
    }
};
