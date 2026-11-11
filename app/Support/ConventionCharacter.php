<?php

namespace App\Support;

use App\Exceptions\UserException;
use Illuminate\Support\Facades\Validator;

/**
 * The ConventionCharacter document stored in `characters.character_json` for
 * `kind = convention` rows (sporebound-character "convention-plan.md" §3).
 *
 * The hub checks shape only — strings are strings, cards are 1–10 of a real
 * suit, lists are capped — and never catalog ids. The catalog lives in the
 * client, and "needs one more skill" is a prep-stage issue the storyteller is
 * allowed to save with. Mirrors `validateConventionShape()` in the client.
 */
final class ConventionCharacter
{
    public const FORMAT = 'convention';

    public const FORMAT_VERSION = 1;

    public const SUITS = ['diamonds', 'hearts', 'spades', 'clubs'];

    /**
     * Keys the roster list leaves out: the long prose and storyteller notes.
     * Everything else is small and is what the roster's issue badge needs.
     *
     * @var string[]
     */
    public const HEAVY_KEYS = ['background', 'relationships', 'goals', 'prelude', 'stNotes'];

    /**
     * Validates a document, returning it with only the known keys.
     *
     * @throws UserException 400 listing every field error
     */
    public static function validate(mixed $doc, string $prefix = ''): array
    {
        if (! is_array($doc)) {
            throw new UserException("Invalid data provided: {$prefix}character_json must be an object", 400);
        }

        $validator = Validator::make($doc, self::rules());

        if ($validator->fails()) {
            $errors = array_map(fn (string $e) => $prefix.$e, $validator->errors()->all());

            throw new UserException('Invalid data provided: '.implode(', ', $errors), 400);
        }

        return self::normalize($validator->validated());
    }

    /**
     * The roster's cut of a document.
     */
    public static function summary(array $doc): array
    {
        return array_diff_key($doc, array_flip(self::HEAVY_KEYS));
    }

    /**
     * @return array<string, string|array>
     */
    private static function rules(): array
    {
        return [
            'format' => 'required|string|in:'.self::FORMAT,
            'formatVersion' => 'required|integer|in:'.self::FORMAT_VERSION,

            'sheetCode' => 'required|string|min:1|max:40',
            'nickname' => 'present|nullable|string|max:120',
            'fullName' => 'present|nullable|string|max:200',

            'cladeId' => 'present|nullable|string|max:40',
            'bloomId' => 'present|nullable|string|max:40',
            'troop' => 'present|nullable|string|max:80',
            'hp' => 'required|integer|min:0|max:99',

            'skills' => 'present|array|max:20',
            'skills.*' => 'string|max:40',
            'craftType' => 'sometimes|nullable|string|max:80',

            'activeGermIds' => 'present|array|max:10',
            'activeGermIds.*' => 'string|max:60',

            'hand' => 'present|array|max:30',
            'hand.*' => 'array:value,suit',
            'hand.*.value' => 'required|integer|min:1|max:10',
            'hand.*.suit' => 'required|string|in:'.implode(',', self::SUITS),

            'resource' => 'present|nullable|string|max:300',
            'background' => 'present|nullable|string|max:20000',

            'relationships' => 'present|array|max:50',
            'relationships.*' => 'array:name,text',
            'relationships.*.name' => 'present|nullable|string|max:200',
            'relationships.*.text' => 'present|nullable|string|max:5000',

            'goals' => 'present|array|max:50',
            'goals.*' => 'array:title,text',
            'goals.*.title' => 'present|nullable|string|max:200',
            'goals.*.text' => 'present|nullable|string|max:5000',

            'prelude' => 'sometimes|nullable|string|max:30000',
            'stNotes' => 'sometimes|nullable|string|max:20000',
            'importNotes' => 'sometimes|nullable|array|max:50',
            'importNotes.*' => 'string|max:1000',
        ];
    }

    /**
     * Laravel's validated() drops keys whose value was null for nullable
     * `present` rules on some versions and keeps nested arrays loosely keyed;
     * pin the stored shape so every row reads the same.
     */
    private static function normalize(array $doc): array
    {
        $str = fn ($v) => $v === null ? '' : (string) $v;

        $out = [
            'format' => self::FORMAT,
            'formatVersion' => self::FORMAT_VERSION,
            'sheetCode' => trim($doc['sheetCode']),
            'nickname' => $str($doc['nickname'] ?? ''),
            'fullName' => $str($doc['fullName'] ?? ''),
            'cladeId' => $str($doc['cladeId'] ?? ''),
            'bloomId' => filled($doc['bloomId'] ?? null) ? (string) $doc['bloomId'] : null,
            'troop' => $str($doc['troop'] ?? ''),
            'hp' => (int) $doc['hp'],
            'skills' => array_values($doc['skills'] ?? []),
            'craftType' => filled($doc['craftType'] ?? null) ? (string) $doc['craftType'] : null,
            'activeGermIds' => array_values($doc['activeGermIds'] ?? []),
            'hand' => array_map(
                fn (array $c) => ['value' => (int) $c['value'], 'suit' => $c['suit']],
                array_values($doc['hand'] ?? []),
            ),
            'resource' => $str($doc['resource'] ?? ''),
            'background' => $str($doc['background'] ?? ''),
            'relationships' => array_map(
                fn (array $r) => ['name' => $str($r['name'] ?? ''), 'text' => $str($r['text'] ?? '')],
                array_values($doc['relationships'] ?? []),
            ),
            'goals' => array_map(
                fn (array $g) => ['title' => $str($g['title'] ?? ''), 'text' => $str($g['text'] ?? '')],
                array_values($doc['goals'] ?? []),
            ),
        ];

        if (filled($doc['prelude'] ?? null)) {
            $out['prelude'] = (string) $doc['prelude'];
        }
        if (filled($doc['stNotes'] ?? null)) {
            $out['stNotes'] = (string) $doc['stNotes'];
        }
        if (! empty($doc['importNotes'])) {
            $out['importNotes'] = array_values($doc['importNotes']);
        }

        return $out;
    }

    /**
     * The `characters.name` for a document: the nickname, else the sheet code.
     */
    public static function displayName(array $doc): string
    {
        $nick = trim((string) ($doc['nickname'] ?? ''));

        return $nick !== '' ? $nick : (string) $doc['sheetCode'];
    }
}
