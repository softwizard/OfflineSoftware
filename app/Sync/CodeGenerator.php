<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Device-prefixed human codes (CUST-D07-000123) — never a globally
 * sequential number generated locally (build-plan_1.md §3.1). The counter
 * is per-prefix and local to this device, so D07 and D08 never collide
 * even though each starts counting from 1 independently.
 */
class CodeGenerator
{
    public static function next(string $prefix): string
    {
        DB::table('code_sequences')->insertOrIgnore(['prefix' => $prefix, 'next_number' => 1]);

        return DB::transaction(function () use ($prefix) {
            $number = DB::table('code_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->value('next_number');

            DB::table('code_sequences')
                ->where('prefix', $prefix)
                ->update(['next_number' => $number + 1]);

            return sprintf('%s-%s-%06d', $prefix, DeviceIdentity::id(), $number);
        });
    }
}
