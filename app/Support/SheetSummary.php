<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders a resolved character sheet into something readable in an email — the
 * shape a storyteller needs when a player hands them a brand new character.
 *
 * Same contract and same limits as ChangeSummary: entries of a heading and its
 * lines, and no rules catalogue, so ids that name a Clade, Germ or Skill are
 * read as words rather than looked up.
 */
class SheetSummary
{
    /** The four attribute cards, in the order the sheet shows them. */
    private const ATTRIBUTES = ['body', 'mind', 'finesse', 'charisma'];

    /**
     * @param  ?array  $sheet  a resolved character (current_json)
     * @return array<int, array{heading: string, lines: string[]}>
     */
    public static function entries(?array $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        return array_values(array_filter([
            self::entry('Clade', [
                self::name($sheet['cladeId'] ?? null),
                self::labelled('Bloom', self::bloom($sheet)),
                self::labelled('HP', $sheet['hp'] ?? null),
            ]),
            self::entry('Attributes', [self::attributes($sheet['attributes'] ?? null)]),
            self::entry('Face Cards', [self::faceCards($sheet['hand']['faceCardSuits'] ?? null)]),
            self::entry('Trained Skills', self::skills($sheet['trainedSkills'] ?? null)),
            self::entry('Anchors', self::anchors($sheet['anchors'] ?? null, $sheet['bloomAnchorId'] ?? null)),
            self::entry('Germs', self::germs($sheet['germs'] ?? null, $sheet)),
        ]));
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return ?array{heading: string, lines: string[]}
     */
    private static function entry(string $heading, array $lines): ?array
    {
        $lines = self::lines($lines);

        return $lines === [] ? null : ['heading' => $heading, 'lines' => $lines];
    }

    private static function attributes(mixed $attributes): string
    {
        if (! is_array($attributes)) {
            return '';
        }

        $parts = [];

        foreach (self::ATTRIBUTES as $id) {
            $value = $attributes[$id] ?? null;
            if (is_numeric($value)) {
                $parts[] = Str::headline($id).' '.$value;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Suit glyphs live in the client's catalogue, so the suits are spelled out.
     */
    private static function faceCards(mixed $suits): string
    {
        if (! is_array($suits)) {
            return '';
        }

        $parts = [];

        foreach (['A', 'J', 'Q', 'K'] as $rank) {
            $suit = $suits[$rank] ?? null;
            if (is_string($suit) && $suit !== '') {
                $parts[] = $rank.' '.Str::headline($suit);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Crafts can be trained more than once, each for a different type, so the
     * sheet carries entries rather than bare ids — but it has also been written
     * as a plain list of ids, and both read the same here.
     *
     * @return string[]
     */
    private static function skills(mixed $skills): array
    {
        if (! is_array($skills)) {
            return [];
        }

        return array_map(function ($skill) {
            if (is_string($skill)) {
                return Str::headline($skill);
            }
            if (! is_array($skill)) {
                return '';
            }

            $name = self::name($skill['skillId'] ?? null);
            $craftType = is_string($skill['craftType'] ?? null) ? trim($skill['craftType']) : '';

            return $name === '' || $craftType === '' ? $name : "{$name} ({$craftType})";
        }, $skills);
    }

    /**
     * The Bloom, read from its id; "(lapsed)" once its Anchor is gone.
     */
    private static function bloom(array $sheet): string
    {
        $bloomId = $sheet['bloomId'] ?? null;
        if (! is_string($bloomId) || $bloomId === '') {
            return '';
        }

        return self::name($bloomId).(empty($sheet['bloomAnchorId']) ? ' (lapsed)' : '');
    }

    /**
     * Destroyed Anchors are still worth showing on a new character — they are
     * rare there, and the reason is part of the character's history.
     *
     * @return string[]
     */
    private static function anchors(mixed $anchors, mixed $bloomAnchorId = null): array
    {
        if (! is_array($anchors)) {
            return [];
        }

        return array_map(function ($anchor) use ($bloomAnchorId) {
            if (! is_array($anchor)) {
                return '';
            }

            $name = is_string($anchor['name'] ?? null) ? trim($anchor['name']) : '';
            if ($name === '') {
                return '';
            }

            $shape = ($anchor['type'] ?? null) === 'individual' ? 'an individual' : 'a group';
            $meta = implode(' · ', array_filter([
                is_string($anchor['tag'] ?? null) ? $anchor['tag'] : null,
                is_string($anchor['category'] ?? null) ? $anchor['category'] : null,
            ]));

            $line = "{$name} — {$shape}".($meta === '' ? '' : ", {$meta}");

            if ($bloomAnchorId !== null && ($anchor['anchorId'] ?? null) === $bloomAnchorId) {
                $line .= ' — Bloom Anchor';
            }

            if (($anchor['active'] ?? true) === false) {
                $reason = is_string($anchor['lostReason'] ?? null) ? trim($anchor['lostReason']) : '';

                $line .= ' — destroyed'.($reason === '' ? '' : ": {$reason}");
            }

            $description = is_string($anchor['description'] ?? null) ? trim($anchor['description']) : '';

            return $description === '' ? $line : "{$line}. {$description}";
        }, $anchors);
    }

    /**
     * @return string[]
     */
    private static function germs(mixed $germs, array $sheet): array
    {
        if (! is_array($germs)) {
            return [];
        }

        return array_map(function ($germ) use ($sheet) {
            if (! is_array($germ)) {
                return '';
            }

            $name = self::name($germ['id'] ?? null);
            if ($name === '') {
                return '';
            }

            $anchor = self::anchorName($germ['anchorId'] ?? null, $sheet);
            $line = $anchor === '' ? "{$name}, untethered" : "{$name}, tethered to {$anchor}";

            return ($germ['cultured'] ?? false) ? "{$line} — cultured" : $line;
        }, $germs);
    }

    private static function anchorName(mixed $anchorId, array $sheet): string
    {
        if (! is_string($anchorId) || $anchorId === '') {
            return '';
        }

        foreach ($sheet['anchors'] ?? [] as $anchor) {
            if (is_array($anchor) && ($anchor['anchorId'] ?? null) === $anchorId) {
                $name = is_string($anchor['name'] ?? null) ? trim($anchor['name']) : '';
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $anchorId;
    }

    /** An id read as words, which is the best the hub can do without the catalogue. */
    private static function name(mixed $id): string
    {
        return is_string($id) && $id !== '' ? Str::headline($id) : '';
    }

    private static function labelled(string $label, mixed $value): string
    {
        return is_numeric($value) ? "{$label}: {$value}" : '';
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
}
