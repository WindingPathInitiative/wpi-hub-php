<?php

namespace Tests\Feature;

use App\Mail\DowntimeSubmitted;
use Database\Seeders\CharacterFixturesSeeder as F;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\CharacterTestCase;

/**
 * Every filed downtime is mailed to the player and copied to the storyteller
 * who has to answer it. Nothing about the mail may fail the submission.
 */
class DowntimeMailTest extends CharacterTestCase
{
    private const PLAYER_EMAIL = 'playerone@example.com';

    private const ST_EMAIL = 'storyteller@example.com';

    private const CHAPTER_ST_EMAIL = 'chapterteller@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->makeCycle();
    }

    /**
     * Files a well-formed downtime and returns the response.
     */
    private function fileDowntime(int $characterId, string $action = 'downtime.develop-anchor')
    {
        $id = $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$characterId}/submission", [
                'type' => 'downtime',
                'changes_json' => $this->downtime($action),
                'current_json' => $this->sheet(),
            ])
            ->assertStatus(201)
            ->json('id');

        return $this->asUser(F::PLAYER)->postJson("/v1/character/{$characterId}/submission/{$id}/submit");
    }

    public function test_the_player_is_written_to_and_the_storyteller_copied(): void
    {
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id)->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, function (DowntimeSubmitted $mail) {
            return $mail->hasTo(self::PLAYER_EMAIL)
                && $mail->hasCc(self::ST_EMAIL)
                && $mail->hasReplyTo(self::ST_EMAIL);
        });
    }

    public function test_the_subject_names_the_character_and_the_cycle(): void
    {
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id)->assertOk();

        Mail::assertSent(
            DowntimeSubmitted::class,
            fn (DowntimeSubmitted $mail) => $mail->envelope()->subject
                === '[Sporebound] Downtime — Nicki Parker — Game 1 -> 2'
        );
    }

    public function test_an_office_address_wins_over_the_officer_s_own(): void
    {
        DB::table('offices')->where('id', 1)->update(['email' => 'downtime@thisgame.example']);
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id)->assertOk();

        Mail::assertSent(
            DowntimeSubmitted::class,
            fn (DowntimeSubmitted $mail) => $mail->hasCc('downtime@thisgame.example')
        );
    }

    public function test_a_vacant_venue_office_falls_up_the_chain(): void
    {
        // Nobody holds the venue office and it has no address of its own; the
        // Chapter Owner holds character_approve over that venue too.
        DB::table('offices')->where('id', 1)->update(['userID' => null]);
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id)->assertOk();

        Mail::assertSent(
            DowntimeSubmitted::class,
            fn (DowntimeSubmitted $mail) => $mail->hasCc(self::CHAPTER_ST_EMAIL)
        );
    }

    public function test_a_game_with_no_storyteller_still_files_and_still_mails_the_player(): void
    {
        DB::table('offices')->whereIn('id', [1, 3])->update(['userID' => null]);
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id)->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, function (DowntimeSubmitted $mail) {
            return $mail->hasTo(self::PLAYER_EMAIL) && $mail->envelope()->cc === [];
        });
    }

    public function test_resubmitting_after_a_rejection_sends_again(): void
    {
        $character = $this->makeCharacter();

        $id = $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission", [
                'type' => 'downtime',
                'changes_json' => $this->downtime(),
                'current_json' => $this->sheet(),
            ])
            ->json('id');

        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();
        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/reject", ['note' => 'Pick a different Anchor'])->assertOk();

        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$id}", [
            'changes_json' => $this->downtime('downtime.narrative-impact'),
        ])->assertOk();
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, 2);
    }

    /**
     * Other types are mailed too, but as a CharacterUpdateSubmitted — this one
     * is only about who is *not* told they filed a downtime.
     */
    public function test_other_submission_types_do_not_send_a_downtime(): void
    {
        $character = $this->makeCharacter();

        $id = $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission", [
                'type' => 'adjustment',
                'changes_json' => $this->changes(['adjust.face-cards']),
            ])
            ->json('id');
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        Mail::assertNotSent(DowntimeSubmitted::class);
    }

    public function test_the_filing_mail_is_not_sent_again_when_it_is_rejected(): void
    {
        $character = $this->makeCharacter();
        $this->fileDowntime($character->id)->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, 1);

        $id = $character->submissions()->first()->id;
        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/reject", ['note' => 'no'])->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, 1);
    }

    public function test_the_body_renders_both_investigations_when_that_is_the_action(): void
    {
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id, 'downtime.investigate')->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, function (DowntimeSubmitted $mail) {
            $body = $mail->render();

            return str_contains($body, 'Who torched the warehouse on Delaware Ave?')
                && str_contains($body, 'Where did the missing shift nurse go?')
                && str_contains($body, 'a second Investigation')
                && str_contains($body, 'About: The Crew');
        });
    }

    public function test_the_body_renders_the_investigation_and_the_action(): void
    {
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id, 'downtime.protect-anchor')->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, function (DowntimeSubmitted $mail) {
            $body = $mail->render();

            return str_contains($body, 'Nicki Parker')
                && str_contains($body, 'Investigation')
                && str_contains($body, 'Who torched the warehouse on Delaware Ave?')
                && str_contains($body, 'Protect your Anchor')
                // The Anchor is named off current_json, not printed as "a1".
                && str_contains($body, 'The Crew')
                && ! str_contains($body, '>a1<');
        });
    }

    public function test_the_body_names_the_bloom_and_the_bloom_anchor(): void
    {
        $character = $this->makeCharacter();

        $this->fileDowntime($character->id, 'downtime.join-bloom')->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, function (DowntimeSubmitted $mail) {
            $body = $mail->render();

            return str_contains($body, 'Join a Bloom')
                && str_contains($body, 'Bloom: Corpse Flowers')
                && str_contains($body, 'Sponsored by Ash for Buried the Kensington dead')
                && str_contains($body, 'Bloom Anchor: The Crew')
                && str_contains($body, 'Costs one improvement');
        });
    }

    public function test_a_hilumless_bloom_reads_as_the_st_s_blessing(): void
    {
        $sheet = $this->sheet();
        $sheet['cladeId'] = 'shroomhead';
        $character = $this->makeCharacter(['current_json' => $sheet]);

        $changes = $this->downtime('downtime.join-bloom');
        $changes[1]['payload']['bloomId'] = 'trip-sitters';
        $changes[1]['payload']['sponsor'] = ['kind' => 'hilum'];

        $id = $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$character->id}/submission", ['type' => 'downtime', 'changes_json' => $changes])
            ->json('id');
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        Mail::assertSent(DowntimeSubmitted::class, fn (DowntimeSubmitted $mail) => str_contains($mail->render(), 'Blessed by the ST'));
    }
}
