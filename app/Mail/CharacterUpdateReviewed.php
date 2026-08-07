<?php

namespace App\Mail;

/**
 * The storyteller's verdict on an advancement, adjustment or rewrite.
 */
class CharacterUpdateReviewed extends SubmissionReviewed
{
    protected function label(): string
    {
        return $this->submission->typeLabel();
    }
}
