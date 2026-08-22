<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * build-plan_1.md §3.5 / invariant 3 — the sequence is allocated LAST in
 * the transaction, under a lock held to commit. A row lock via
 * lockForUpdate(), held until the ambient transaction commits, makes
 * sequence order equal commit order: a reader can never observe a gap,
 * because no transaction can commit a lower seq after a higher one is
 * already visible. Works unchanged on MySQL (row lock) and SQLite (whole
 * -database lock — fine at this scale; the Postgres advisory-lock variant
 * from the plan isn't needed since this project's server DB is MySQL).
 *
 * Call this as the LAST statement before commit — moving it earlier
 * reintroduces A1 (clients permanently skipping rows).
 */
class SequenceAllocator
{
    public static function next(): int
    {
        $value = DB::table('sync_sequence')->where('id', 1)->lockForUpdate()->value('value');

        DB::table('sync_sequence')->where('id', 1)->update(['value' => $value + 1]);

        return $value + 1;
    }
}
