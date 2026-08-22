<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $device_code
 * @property string|null $owner_user_id
 * @property string|null $provisioning_code_hash
 * @property Carbon|null $provisioning_code_expires_at
 * @property Carbon|null $last_seen_at
 * @property int|null $last_schema_version
 * @property int $last_pulled_seq
 * @property Carbon|null $revoked_at
 *
 * Deliberately not Authenticatable and not registered as an auth guard
 * provider — §3.6: devices are not users, and a device token must never
 * authenticate a web session. HasApiTokens works on any Eloquent model;
 * App\Http\Middleware\AuthenticateDevice resolves tokens directly via
 * PersonalAccessToken::findToken(), bypassing Laravel's Auth facade
 * entirely so there's no guard/provider wiring for this to leak through.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provisioning_code_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
