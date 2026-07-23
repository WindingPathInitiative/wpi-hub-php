<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A batch of changes (advancements + downtime) applied to a Character after
 * creation, pending approval (see "Character Plan.md").
 *
 * @property int $id
 * @property int $character_id
 * @property Carbon $date_added
 * @property ?Carbon $date_approved
 * @property array $improvements_json
 */
class Improvement extends Model
{
    protected $table = 'improvements';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_added' => 'datetime',
            'date_approved' => 'datetime',
            'improvements_json' => 'array',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
