<?php

namespace App\Console\Commands;

use App\Events\CharacterSubmitted;
use App\Models\Character;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Services\StorytellerContactService;
use Illuminate\Console\Command;

/**
 * Reports who the hub would write to about a character, and optionally proves
 * the whole chain by raising a real event.
 *
 * Every character mail needs two addresses, and the storyteller one is the
 * opaque half: it is not stored anywhere, it is resolved by walking up the org
 * chain looking for a Primary office that holds `character_approve` and has an
 * address. When that walk turns up nothing, the mails addressed *to* the
 * storyteller (a new character, a submitted advancement) have nobody to send to
 * and are dropped with a warning in the log — which looks exactly like "email
 * is broken". This says which it is.
 */
class CharacterMailCheck extends Command
{
    protected $signature = 'character:mail-check
                            {character : Character id}
                            {--send : Raise a real CharacterSubmitted event, sending live mail}';

    protected $description = 'Show the player and storyteller addresses used for a character\'s mail';

    public function handle(StorytellerContactService $contacts): int
    {
        $character = Character::with(['user', 'unit'])->find($this->argument('character'));

        if ($character === null) {
            $this->error('No character with id '.$this->argument('character').'.');

            return self::FAILURE;
        }

        $this->line('Mailer: '.config('mail.default').', from '.config('mail.from.address'));
        $this->line('Character: '.$character->name.' (#'.$character->id.')');
        $this->line('Game: '.($character->unit?->name ?? 'none — a character with no game has no storyteller'));

        $player = $character->user?->email;
        $this->line('Player: '.($player ?: '<none> — every mail to the player is dropped'));

        $storyteller = $contacts->emailForCharacter($character);
        $this->line('Storyteller: '.($storyteller ?? '<none> — mail addressed to the storyteller is dropped'));

        if ($storyteller === null) {
            $this->offices($character);
        }

        $this->newLine();
        $this->summarise($player ?: null, $storyteller);

        if ($this->option('send')) {
            $this->newLine();
            $this->line('Raising CharacterSubmitted...');
            CharacterSubmitted::dispatch($character);
            $this->info('Raised. A mail with no listener behind it leaves no trace at all —');
            $this->info('if nothing arrives and the log is silent, the listener is not registered');
            $this->info('(check `php artisan event:list`).');
        }

        return self::SUCCESS;
    }

    /**
     * The offices the walk actually looked at, so a missing storyteller can be
     * traced to the office that needs a holder, an address, or the role.
     */
    private function offices(Character $character): void
    {
        if ($character->org_unit_id === null) {
            return;
        }

        $unit = OrgUnit::find($character->org_unit_id);
        if ($unit === null) {
            return;
        }

        $this->newLine();
        $this->line('Primary offices on that game and its parents:');

        foreach (array_merge([$unit->id], array_reverse($unit->parents())) as $orgId) {
            $offices = Office::where('parentOrgID', $orgId)->where('type', 'Primary')->with('user')->get();

            foreach ($offices as $office) {
                $roles = is_array($office->roles) ? $office->roles : [];

                $this->line(sprintf(
                    '  %-28s %-14s %s',
                    $office->name ?? ('office #'.$office->id),
                    in_array('character_approve', $roles, true) ? 'can approve' : 'NO ROLE',
                    $office->email ?: ($office->user?->email ?: '<no address>'),
                ));
            }
        }
    }

    private function summarise(?string $player, ?string $storyteller): void
    {
        $rows = [
            ['Downtime filed', $player, $player !== null],
            ['Downtime / update reviewed', $player, $player !== null],
            ['New character', $storyteller, $storyteller !== null],
            ['Advancement / adjustment / rewrite submitted', $storyteller, $storyteller !== null],
        ];

        foreach ($rows as [$mail, $to, $sends]) {
            $this->line(sprintf(
                '  %-45s %s',
                $mail,
                $sends ? 'to '.$to : 'DROPPED — no address',
            ));
        }
    }
}
