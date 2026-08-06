<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Sporebound character owned by a user.
 *
 * `character_json` holds the v2 envelope — {formatVersion, identity, starting,
 * changes, current} — where `changes` is the ordered list of *approved* changes.
 * Post-creation changes arrive as CharacterSubmission rows and are folded into
 * the envelope when a storyteller approves them.
 *
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property ?int $org_unit_id
 * @property array $character_json
 * @property ?array $current_json
 * @property ?Carbon $retired_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Character extends Model
{
    protected $table = 'characters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'character_json' => 'array',
            'current_json' => 'array',
            'retired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CharacterSubmission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * The resolved sheet the client last computed, preferring the dedicated
     * cache column and falling back to the envelope's own `current`.
     */
    public function resolved(): ?array
    {
        return $this->current_json ?? ($this->character_json['current'] ?? null);
    }
}
