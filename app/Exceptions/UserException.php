<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Ports wpi-hub helpers/errors.js (UserError). Renders as the legacy JSON shape
 * `{ message, status }` with the given HTTP status (default 500). The optional
 * $dev cause is preserved for logging but never leaked to the client.
 */
class UserException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $status = 500,
        ?Throwable $dev = null,
    ) {
        parent::__construct($message, 0, $dev);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'status' => $this->status,
        ], $this->status);
    }
}
