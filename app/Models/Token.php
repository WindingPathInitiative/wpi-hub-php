<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Legacy login token. Ports wpi-hub models/token.js. A UUID primary key and a
 * one-hour expiry are assigned on save. Kept for dev/switch parity and the
 * upcoming Keycloak transition.
 *
 * @property string $token
 * @property int $user
 * @property Carbon $expires
 */
class Token extends Model
{
    protected $table = 'tokens';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Mirrors Token.saving(): assign a UUID if missing, always (re)set the
        // expiry to one hour out.
        static::saving(function (Token $token): void {
            if (! $token->token) {
                $token->token = (string) Str::uuid();
            }
            $token->expires = Carbon::now()->addHour();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user');
    }
}
