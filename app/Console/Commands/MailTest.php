<?php

namespace App\Console\Commands;

use App\Mail\TestMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one message through the configured mailer so SMTP problems surface
 * on the CLI (bad credentials, blocked port, unverified from-domain) instead
 * of inside a request.
 */
class MailTest extends Command
{
    protected $signature = 'mail:test {to : Recipient address}';

    protected $description = 'Send a test email through the configured mailer';

    public function handle(): int
    {
        $to = $this->argument('to');

        $this->line(sprintf(
            'Sending via %s (%s:%s) as %s...',
            config('mail.default'),
            config('mail.mailers.smtp.host'),
            config('mail.mailers.smtp.port'),
            config('mail.from.address'),
        ));

        try {
            Mail::to($to)->send(new TestMessage(now()->toDateTimeString()));
        } catch (\Throwable $e) {
            $this->error('Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent to '.$to.'.');

        if (config('mail.default') === 'log') {
            $this->comment('MAIL_MAILER=log — check storage/logs, nothing left the machine.');
        }

        return self::SUCCESS;
    }
}
