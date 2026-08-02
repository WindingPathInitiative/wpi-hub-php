<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A batch of Change envelopes submitted against a character, approved or
 * rejected as a unit by a storyteller.
 *
 * `changes_json` is an array of Change envelopes
 * ({id, kind, cycleId, status, submittedAt, resolvedAt, note, payload}); the
 * kinds allowed depend on `type` (see SubmissionValidator).
 *
 * @property int $id
 * @property int $character_id
 * @property ?int $cycle_id
 * @property string $type
 * @property string $status
 * @property array $changes_json
 * @property ?array $current_json
 * @property ?string $note
 * @property ?Carbon $submitted_at
 * @property ?Carbon $resolved_at
 * @property ?int $resolved_by
 */
class CharacterSubmission extends Model
{
    protected $table = 'character_submissions';

    protected $guarded = ['id'];

    public const TYPES = ['advancement', 'downtime', 'adjustment', 'rewrite', 'storyteller'];

    /** Types a player may create for their own character. */
    public const PLAYER_TYPES = ['advancement', 'downtime', 'adjustment', 'rewrite'];

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    protected function casts(): array
    {
        return [
            'changes_json' => 'array',
            'current_json' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Awaiting a storyteller's decision.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    /**
     * Counted against the improvement budget: everything except drafts the
     * player has not committed to and submissions the ST turned down.
     */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->whereIn('status', ['submitted', 'approved']);
    }

    /**
     * The changes of a given kind (or kind prefix, e.g. "advancement.").
     *
     * @return array<int, array>
     */
    public function changesOfKind(string $prefix): array
    {
        return array_values(array_filter(
            $this->changes_json ?? [],
            fn ($c) => is_array($c) && str_starts_with((string) ($c['kind'] ?? ''), $prefix)
        ));
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
