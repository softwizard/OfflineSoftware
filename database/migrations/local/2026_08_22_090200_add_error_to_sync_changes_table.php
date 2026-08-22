<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            // PushRunner keeps a 'conflict'/'rejected' row (never deletes it)
            // so S5-04's /sync page can show the user why — only 'applied'
            // rows are removed once confirmed.
            $table->text('error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropColumn('error');
        });
    }
};
