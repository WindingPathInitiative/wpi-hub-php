<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\UserException;
use Illuminate\Support\Facades\Validator;

/**
 * Runs Laravel validation but reports failures in the legacy
 * "Invalid data provided: ..." / 400 shape that wpi-hub's validate.js produced,
 * so existing clients don't break. (Used in lieu of Form Requests because the
 * Node controllers validate *after* permission/parent checks.)
 */
trait ValidatesLegacy
{
    /**
     * @return array<string, mixed> the validated subset
     */
    protected function validateLegacy(array $data, array $rules, array $messages = []): array
    {
        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new UserException(
                'Invalid data provided: '.implode(', ', $validator->errors()->all()),
                400
            );
        }

        return $validator->validated();
    }
}
