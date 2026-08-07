<?php

namespace Tests\Feature;

use App\Mail\CharacterUpdateReviewed;
use App\Mail\CharacterUpdateSubmitted;
use App\Mail\DowntimeReviewed;
use Database\Seeders\CharacterFixturesSeeder as F;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\CharacterTestCase;

/**
 * The two mails that are not a filed downtime: an advancement, adjustment or
 * rewrite going *to* the storyteller who has to decide it, and the verdict
 * coming back to the player.
 *
 * The addressing is the point of most of these. A request for a decision is
 * addressed to the person making it; a decision is addressed to the person
 * waiting on it; and reply-to is always the other one, so an answer lands
 * somewhere a human reads.
 */
class SubmissionMailTest extends CharacterTestCase
{
    private const PLAYER_EMAIL = 'playerone@example.com';

    private const ST_EMAIL = 'storyteller@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->makeCycle();
    }

    /**
     * Drafts and submits, returning the submission id.
     */
    private function fileUpdate(int $characterId, string $type = 'advancement', ?array $changes = null): int
    {
        $id = $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$characterId}/submission", [
                'type' => $type,
                'changes_json' => $changes ?? $this->changes(['advancement.card']),
                'current_json' => $this->sheet(),
            ])
            ->assertStatus(201)
            ->json('id');

        $this->asUser(F::PLAYER)
            ->postJson("/v1/character/{$characterId}/submission/{$id}/submit")
            ->assertOk();

        return $id;
    }

    // --- submitted --------------------------------------------------------

    public function test_the_storyteller_is_written_to_and_the_player_copied(): void
    {
        $character = $this->makeCharacter();

        $this->fileUpdate($character->id);

        Mail::assertSent(CharacterUpdateSubmitted::class, function (CharacterUpdateSubmitted $mail) {
            return $mail->hasTo(self::ST_EMAIL)
                && $mail->hasCc(self::PLAYER_EMAIL)
                && $mail->hasReplyTo(self::PLAYER_EMAIL);
        });
    }

    public function test_the_subject_names_the_type_and_the_character(): void
    {
        $character = $this->makeCharacter();

        $this->fileUpdate($character->id, 'adjustment', $this->changes(['adjust.face-cards']));

        Mail::assertSent(
            CharacterUpdateSubmitted::class,
            fn (CharacterUpdateSubmitted $mail) => $mail->envelope()->subject
                === '[Sporebound] Adjustment submitted — Nicki Parker'
        );
    }

    /**
     * The storyteller is the addressee here, so a game with nobody holding
     * character_approve has nobody to send to at all — but the submission still
     * files and still queues.
     */
    public function test_a_game_with_no_storyteller_files_but_sends_nothing(): void
    {
        DB::table('offices')->whereIn('id', [1, 3])->update(['userID' => null]);
        $character = $this->makeCharacter();

        $this->fileUpdate($character->id);

        Mail::assertNotSent(CharacterUpdateSubmitted::class);
    }

    public function test_resubmitting_after_a_rejection_sends_again(): void
    {
        $character = $this->makeCharacter();
        $id = $this->fileUpdate($character->id);

        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/reject", ['note' => 'Pick another card'])->assertOk();
        $this->asUser(F::PLAYER)->putJson("/v1/character/{$character->id}/submission/{$id}", [
            'changes_json' => $this->changes(['advancement.skill']),
        ])->assertOk();
        $this->asUser(F::PLAYER)->postJson("/v1/character/{$character->id}/submission/{$id}/submit")->assertOk();

        Mail::assertSent(CharacterUpdateSubmitted::class, 2);
    }

    public function test_the_body_renders_the_changes(): void
    {
        $character = $this->makeCharacter();

        $this->fileUpdate($character->id, 'advancement', $this->changes([
            'advancement.card', 'advancement.germ',
        ]));

        Mail::assertSent(CharacterUpdateSubmitted::class, function (CharacterUpdateSubmitted $mail) {
            $body = $mail->render();

            return str_contains($body, 'Advancement submitted for Nicki Parker')
                && str_contains($body, '+1 Body')
                && str_contains($body, 'Learned Mycelial Senses on The Crew');
        });
    }

    /**
     * A rewrite carries other changes inside it, and the storyteller is being
     * asked about those, so they have to survive onto the page.
     */
    public function test_a_rewrite_renders_the_advancements_it_re_authors(): void
    {
        $character = $this->makeCharacter();

        $this->fileUpdate($character->id, 'rewrite', [[
            'kind' => 'rewrite',
            'payload' => [
                'starting' => ['cladeId' => 'sewer'],
                'advancements' => $this->changes(['advancement.card', 'advancement.skill']),
            ],
        ]]);

        Mail::assertSent(CharacterUpdateSubmitted::class, function (CharacterUpdateSubmitted $mail) {
            $body = $mail->render();

            return $mail->envelope()->subject === '[Sporebound] Rewrite submitted — Nicki Parker'
                && str_contains($body, 'Re-authored 2 advancements')
                && str_contains($body, 'Improvement — Card: +1 Body')
                && str_contains($body, 'Improvement — Skill: Trained Investigate');
        });
    }

    // --- reviewed ---------------------------------------------------------

    public function test_an_approval_goes_to_the_player_and_copies_the_storyteller(): void
    {
        $character = $this->makeCharacter();
        $id = $this->fileUpdate($character->id);

        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/approve")->assertOk();

        Mail::assertSent(CharacterUpdateReviewed::class, function (CharacterUpdateReviewed $mail) {
            return $mail->hasTo(self::PLAYER_EMAIL)
                && $mail->hasCc(self::ST_EMAIL)
                && $mail->hasReplyTo(self::ST_EMAIL)
                && $mail->envelope()->subject === '[Sporebound] Advancement approved — Nicki Parker';
        });
    }

    public function test_a_denial_carries_the_storyteller_s_note(): void
    {
        $character = $this->makeCharacter();
        $id = $this->fileUpdate($character->id);

        $this->asUser(F::ST)
            ->putJson("/v1/submission/{$id}/reject", ['note' => 'Body is already at your cap'])
            ->assertOk();

        Mail::assertSent(CharacterUpdateReviewed::class, function (CharacterUpdateReviewed $mail) {
            $body = $mail->render();

            return $mail->hasTo(self::PLAYER_EMAIL)
                && $mail->envelope()->subject === '[Sporebound] Advancement denied — Nicki Parker'
                && str_contains($body, 'Why it was sent back')
                && str_contains($body, 'Body is already at your cap')
                && str_contains($body, 'Nothing has changed on your character')
                // The storyteller who decided it is named.
                && str_contains($body, 'Story Teller');
        });
    }

    public function test_a_reviewed_downtime_is_its_own_mail(): void
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
        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/approve")->assertOk();

        Mail::assertNotSent(CharacterUpdateReviewed::class);
        Mail::assertSent(
            DowntimeReviewed::class,
            fn (DowntimeReviewed $mail) => $mail->hasTo(self::PLAYER_EMAIL)
                && $mail->hasCc(self::ST_EMAIL)
                && $mail->envelope()->subject
                    === '[Sporebound] Downtime approved — Nicki Parker — Game 1 -> 2'
        );
    }

    /**
     * A storyteller's direct edit approves itself as it is created; nobody was
     * waiting on a verdict, so there is no verdict to report.
     */
    public function test_a_storyteller_direct_edit_reports_no_verdict(): void
    {
        $character = $this->makeCharacter();

        $this->asUser(F::ST)->postJson("/v1/character/{$character->id}/anchor", [
            'anchor' => ['type' => 'group', 'name' => 'The Night Shift'],
        ])->assertStatus(201);

        Mail::assertNotSent(CharacterUpdateReviewed::class);
        Mail::assertNotSent(DowntimeReviewed::class);
    }

    public function test_the_verdict_still_reaches_the_player_with_no_storyteller_address(): void
    {
        $character = $this->makeCharacter();
        $id = $this->fileUpdate($character->id);

        // The offices are still held — somebody has to be able to approve it —
        // but neither they nor their holders have an address to copy.
        DB::table('offices')->whereIn('id', [1, 3])->update(['email' => null]);
        DB::table('users')->whereIn('id', [F::ST, F::CHAPTER_ST])->update(['email' => '']);

        $this->asUser(F::ST)->putJson("/v1/submission/{$id}/approve")->assertOk();

        Mail::assertSent(
            CharacterUpdateReviewed::class,
            fn (CharacterUpdateReviewed $mail) => $mail->hasTo(self::PLAYER_EMAIL)
                && $mail->envelope()->cc === []
        );
    }
}
