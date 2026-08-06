<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders a downtime submission's changes into something readable in an email.
 *
 * Deliberately dumb: a label for the kind plus the payload's own text. The
 * rules catalogue (Germ names, Anchor categories) lives in the TypeScript
 * client, and the hub has no copy of it — so ids resolve against the
 * submission's own `current_json`, which does carry Anchor names, and a Germ
 * falls back to its id read as words.
 *
 * The client's describe.ts does the same job for the screen. Keep them
 * recognisably in step, but they are not required to agree word for word.
 */
class DowntimeSummary
{
    /**
     * One entry per change: a heading and the lines under it.
     *
     * @param  array<int, mixed>  $changes  changes_json
     * @param  ?array  $sheet  current_json — the sheet this submission resolves to
     * @return array<int, array{heading: string, lines: string[]}>
     */
    public static function entries(array $changes, ?array $sheet = null): array
    {
        $entries = [];

        foreach ($changes as $change) {
            if (! is_array($change)) {
                continue;
            }

            $entry = self::entry($change, $sheet);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return ?array{heading: string, lines: string[]}
     */
    private static function entry(array $change, ?array $sheet): ?array
    {
        $kind = (string) ($change['kind'] ?? '');
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        return match ($kind) {
            'downtime.investigation' => [
                'heading' => 'Investigation',
                'lines' => self::lines([
                    self::text($payload, 'topic'),
                    self::about($payload['anchorId'] ?? null, $sheet),
                ]),
            ],
            // Investigate as the action is a second Investigation, same shape.
            'downtime.investigate' => [
                'heading' => 'Downtime Action — Investigate (a second Investigation)',
                'lines' => self::lines([
                    self::text($payload, 'topic'),
                    self::about($payload['anchorId'] ?? null, $sheet),
                ]),
            ],
            'downtime.develop-anchor' => [
                'heading' => 'Downtime Action — Develop an Anchor',
                'lines' => self::lines([
                    self::anchorFromPayload(is_array($payload['anchor'] ?? null) ? $payload['anchor'] : []),
                    self::text(is_array($payload['anchor'] ?? null) ? $payload['anchor'] : [], 'description'),
                ]),
            ],
            'downtime.protect-anchor' => [
                'heading' => 'Downtime Action — Protect your Anchor',
                'lines' => self::lines([
                    self::anchorName($payload['anchorId'] ?? null, $sheet),
                    'Immune to being In Crisis at their next game.',
                ]),
            ],
            'downtime.undermine-anchor' => [
                'heading' => 'Downtime Action — Undermine Anchor',
                'lines' => self::lines([
                    self::labelled('Target', self::text($payload, 'target')),
                    self::text($payload, 'describe'),
                ]),
            ],
            'downtime.destroy-anchor' => [
                'heading' => 'Downtime Action — Destroy Anchor',
                'lines' => self::lines([
                    self::labelled('Target', self::text($payload, 'target')),
                    self::text($payload, 'describe'),
                ]),
            ],
            'downtime.narrative-impact' => [
                'heading' => 'Downtime Action — Narrative Impact',
                'lines' => self::lines([self::text($payload, 'describe')]),
            ],
            'downtime.culture-germ' => [
                'heading' => 'Downtime Action — Culture a Germ',
                'lines' => self::lines([self::germ($payload['germId'] ?? null, $sheet)]),
            ],
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return string[]
     */
    private static function lines(array $lines): array
    {
        return array_values(array_filter(
            array_map(fn ($line) => is_string($line) ? trim($line) : '', $lines),
            fn (string $line) => $line !== ''
        ));
    }

    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private static function labelled(string $label, string $value): string
    {
        return $value === '' ? '' : "{$label}: {$value}";
    }

    private static function about(mixed $anchorId, ?array $sheet): string
    {
        return self::labelled('About', self::anchorName($anchorId, $sheet));
    }

    /** An Anchor by name where the sheet knows it, and by id where it does not. */
    private static function anchorName(mixed $anchorId, ?array $sheet): string
    {
        if (! is_string($anchorId) || $anchorId === '') {
            return '';
        }

        foreach ($sheet['anchors'] ?? [] as $anchor) {
            if (is_array($anchor) && ($anchor['anchorId'] ?? null) === $anchorId) {
                $name = is_string($anchor['name'] ?? null) ? trim($anchor['name']) : '';
                $meta = self::meta($anchor['tag'] ?? null, $anchor['category'] ?? null);

                return $name === '' ? $anchorId : ($meta === '' ? $name : "{$name} ({$meta})");
            }
        }

        return $anchorId;
    }

    private static function anchorFromPayload(array $anchor): string
    {
        $name = is_string($anchor['name'] ?? null) ? trim($anchor['name']) : '';
        $shape = ($anchor['type'] ?? null) === 'individual' ? 'an individual' : 'a group';
        $meta = self::meta($anchor['tag'] ?? null, $anchor['category'] ?? null);

        if ($name === '') {
            return '';
        }

        return trim("{$name} — {$shape}".($meta === '' ? '' : ", {$meta}"));
    }

    private static function meta(mixed $tag, mixed $category): string
    {
        return implode(' · ', array_filter([
            is_string($tag) ? $tag : null,
            is_string($category) ? $category : null,
        ]));
    }

    /**
     * Germ names live in the client's spore catalogue, so the best the hub can
     * do is read the id as words — and say which Anchor it hangs off, which is
     * the part the storyteller is adjudicating.
     */
    private static function germ(mixed $germId, ?array $sheet): string
    {
        if (! is_string($germId) || $germId === '') {
            return '';
        }

        $name = Str::headline($germId);

        foreach ($sheet['germs'] ?? [] as $germ) {
            if (is_array($germ) && ($germ['id'] ?? null) === $germId) {
                $anchor = self::anchorName($germ['anchorId'] ?? null, $sheet);

                return $anchor === '' ? $name : "{$name}, tethered to {$anchor}";
            }
        }

        return $name;
    }
}
