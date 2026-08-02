<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Helpers for the v2 character envelope
 * ({formatVersion, identity, starting, changes, current}).
 *
 * The server treats the envelope as opaque apart from its outer keys: it never
 * replays changes (that engine is TypeScript, shared with the wizard), it only
 * keeps the envelope well-formed and appends approved changes in order.
 */
class CharacterEnvelope
{
    public const VERSION = 2;

    /**
     * Coerce whatever the client saved into a v2 envelope. A v1 export (no
     * formatVersion) is lifted by wrapping its body as `starting`, mirroring the
     * loader-side migration.
     */
    public static function normalize(array $json): array
    {
        if (($json['formatVersion'] ?? null) === self::VERSION) {
            return [
                'formatVersion' => self::VERSION,
                'identity' => $json['identity'] ?? [],
                'starting' => $json['starting'] ?? [],
                'changes' => array_values($json['changes'] ?? []),
                'current' => $json['current'] ?? null,
            ] + $json;
        }

        $identity = $json['identity'] ?? [];
        $starting = $json;
        unset($starting['identity'], $starting['formatVersion']);

        return [
            'formatVersion' => self::VERSION,
            'identity' => $identity,
            'starting' => $starting,
            'changes' => [],
            'current' => null,
        ];
    }

    /**
     * Stamp a submission's changes for storage: give each an id, and record the
     * status/cycle/timestamps the envelope's history is read back with.
     *
     * @param  array<int, array>  $changes
     * @return array<int, array>
     */
    public static function stamp(array $changes, string $status, ?int $cycleId, ?string $submittedAt, ?string $resolvedAt, ?string $note = null): array
    {
        return array_values(array_map(function ($change) use ($status, $cycleId, $submittedAt, $resolvedAt, $note) {
            $change = is_array($change) ? $change : [];
            $change['id'] = $change['id'] ?? (string) Str::uuid();
            $change['cycleId'] = $cycleId;
            $change['status'] = $status;
            $change['submittedAt'] = $submittedAt;
            $change['resolvedAt'] = $resolvedAt;
            if ($note !== null && ($change['note'] ?? '') === '') {
                $change['note'] = $note;
            }

            return $change;
        }, $changes));
    }

    /**
     * Append approved changes to the envelope and refresh its cached sheet.
     *
     * @param  array<int, array>  $changes
     */
    public static function withApproved(array $json, array $changes, ?array $current): array
    {
        $json = self::normalize($json);
        $json['changes'] = array_merge($json['changes'], $changes);

        if ($current !== null) {
            $json['current'] = $current;
        }

        return $json;
    }
}
