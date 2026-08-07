<?php

namespace Tests\Feature;

use App\Mail\CharacterSubmitted;
use Database\Seeders\CharacterFixturesSeeder as F;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\CharacterTestCase;

/**
 * A new character tells the storyteller of the game it joined.
 *
 * There is no approval step on a new character — saving it is the whole of it —
 * so this mail is an introduction rather than a request, and there is no
 * matching verdict mail.
 */
class CharacterMailTest extends CharacterTestCase
{
    private const PLAYER_EMAIL = 'playerone@example.com';

    private const ST_EMAIL = 'storyteller@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * Posts a character through the wizard export route.
     */
    private function createCharacter(array $body = [])
    {
        return $this->asUser(F::PLAYER)->postJson('/v1/character', array_merge([
            'name' => 'Nicki Parker',
            'org_unit_id' => F::GAME,
            'character_json' => [
                'formatVersion' => 2,
                'identity' => [
                    'characterName' => 'Nicki Parker',
                    'playerName' => 'Player One',
                    'pronouns' => 'they/them',
                ],
                'starting' => ['cladeId' => 'dumpster'],
                'changes' => [],
                'current' => $this->sheet(),
            ],
            'current_json' => $this->sheet(),
        ], $body));
    }

    public function test_the_storyteller_is_written_to_and_the_player_copied(): void
    {
        $this->createCharacter()->assertStatus(201);

        Mail::assertSent(CharacterSubmitted::class, function (CharacterSubmitted $mail) {
            return $mail->hasTo(self::ST_EMAIL)
                && $mail->hasCc(self::PLAYER_EMAIL)
                && $mail->hasReplyTo(self::PLAYER_EMAIL)
                && $mail->envelope()->subject === '[Sporebound] New character — Nicki Parker';
        });
    }

    public function test_the_body_renders_the_sheet(): void
    {
        $this->createCharacter()->assertStatus(201);

        Mail::assertSent(CharacterSubmitted::class, function (CharacterSubmitted $mail) {
            $body = $mail->render();

            return str_contains($body, 'New character: Nicki Parker')
                && str_contains($body, 'they/them')
                && str_contains($body, 'Sporebound Test Game')
                && str_contains($body, 'Dumpster')
                && str_contains($body, 'HP: 10')
                && str_contains($body, 'Body 5 · Mind 6 · Finesse 4 · Charisma 3')
                && str_contains($body, 'Empathy')
                && str_contains($body, 'The Crew — a group')
                && str_contains($body, 'Marcus — an individual')
                && str_contains($body, 'Shroom Shield, tethered to The Crew');
        });
    }

    /**
     * The wizard export does not have to name a game, and one that names none
     * has no storyteller to tell. The character still saves.
     */
    public function test_a_character_with_no_game_saves_and_sends_nothing(): void
    {
        $this->createCharacter(['org_unit_id' => null])->assertStatus(201);

        Mail::assertNothingSent();
    }

    public function test_a_game_with_no_storyteller_saves_and_sends_nothing(): void
    {
        DB::table('offices')->whereIn('id', [1, 3])->update(['userID' => null]);

        $this->createCharacter()->assertStatus(201);

        Mail::assertNothingSent();
    }
}
