<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Throwaway message used by `php artisan mail:test` to prove the SMTP
 * credentials work. Safe to delete once real mail exists; it is not
 * referenced anywhere else.
 */
class TestMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $sentAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('app.name').' mail test',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test',
        );
    }
}
