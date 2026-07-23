<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Sporebound character owned by a user (see "Character Plan.md").
 *
 * `character_json` holds the wizard export (the starting character); post-creation
 * changes accrue as Improvement rows rather than mutating the export.
 *
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property ?int $org_unit_id
 * @property array $character_json
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

    public function improvements(): HasMany
    {
        return $this->hasMany(Improvement::class);
    }
}
