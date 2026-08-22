<?php

namespace App\Models;

use App\Sync\CodeGenerator;
use App\Sync\Syncable;
use App\Sync\SyncableModel;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $city
 * @property string|null $notes
 * @property string $status
 * @property string $owner_user_id
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['code', 'name', 'email', 'phone', 'city', 'notes', 'status', 'owner_user_id'])]
class Customer extends Model implements SyncableModel
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids, SoftDeletes, Syncable;

    public const CODE_PREFIX = 'CUST';

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            // Local-only: CodeGenerator needs DeviceIdentity::id(), which
            // throws in cloud mode. A cloud-side create always has a code
            // already — from the push payload (the client generated it) or
            // an explicit value a caller supplied; there's no server-side
            // device to generate one from.
            if (sync_is_local() && ! $customer->code) {
                $customer->code = CodeGenerator::next(self::CODE_PREFIX);
            }
            // The migration's default(1) is a DB-side default only — Eloquent
            // never re-fetches after insert for a UUID (non-autoincrement)
            // key, so without this the in-memory model's version stays null
            // even though the persisted row correctly has 1. That null then
            // poisons the very first base_version an update would carry.
            $customer->version ??= 1;
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }
}
