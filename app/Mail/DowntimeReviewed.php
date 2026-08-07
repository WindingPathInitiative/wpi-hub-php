<?php

namespace App\Mail;

/**
 * The storyteller's verdict on a filed downtime.
 */
class DowntimeReviewed extends SubmissionReviewed
{
    protected function label(): string
    {
        return 'Downtime';
    }
}
