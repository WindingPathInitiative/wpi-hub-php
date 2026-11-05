<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A game's between-sessions cycle, added by storytellers between games.
 *
 * A character's improvement budget is the sum of `improvements_granted` over
 * its org unit's *opened* cycles (plus approved VXP purchases), which is what
 * lets a character created mid-season catch up automatically: the budget
 * depends on cycles, not on attendance.
 *
 * @property int $id
 * @property int $org_unit_id
 * @property string $name
 * @property int $improvements_granted
 * @property Carbon $opens_at
 * @property ?Carbon $downtime_due_at
 * @property ?Carbon $downtime_grace_ends_at
 */
class Cycle extends Model
{
    protected $table = 'cycles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'improvements_granted' => 'integer',
            'opens_at' => 'datetime',
            'downtime_due_at' => 'datetime',
            'downtime_grace_ends_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CharacterSubmission::class);
    }

    /**
     * Cycles that have started (and so grant their improvements).
     */
    public function scopeOpened(Builder $query): Builder
    {
        return $query->where('opens_at', '<=', now());
    }

    /**
     * The org unit's current cycle: the most recently opened one. Downtime may
     * only be submitted against this cycle.
     */
    public static function current(int $orgUnitId): ?self
    {
        return static::query()
            ->where('org_unit_id', $orgUnitId)
            ->opened()
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Downtime is accepted until the deadline, or until the grace period ends
     * when there is one.
     */
    public function downtimeIsOpen(): bool
    {
        if ($this->downtime_due_at === null || now()->lt($this->downtime_due_at)) {
            return true;
        }

        return $this->downtimeInGrace();
    }

    /**
     * Past the deadline but inside the grace period: late downtime is taken,
     * with no Investigation and a restricted set of Downtime Actions.
     */
    public function downtimeInGrace(): bool
    {
        return $this->downtime_due_at !== null
            && $this->downtime_grace_ends_at !== null
            && now()->gte($this->downtime_due_at)
            && now()->lt($this->downtime_grace_ends_at);
    }
}
